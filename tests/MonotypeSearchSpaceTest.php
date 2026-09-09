<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;
use Waar\MicroCombat\Experiment\ExperimentVariant;
use Waar\MicroCombat\Experiment\MonotypeSearchSpace;

require_once dirname(__DIR__).'/autoload.php';

final class MonotypeSearchSpaceTest extends TestCase
{
    public function testOfficialManifestRoundTripsTheInitialCandidate(): void
    {
        [$experiment, $manifest, $space] = $this->fixture();

        self::assertSame('t30-proposed-15-parameters', $space->id);
        self::assertSame('t30.1-proposed', $space->version);
        self::assertSame('proposed', $space->status);
        self::assertSame('0.001', $space->quantization);
        self::assertCount(15, $space->parameters);
        self::assertSame($experiment->candidate->toArray(), $space->candidateFromValues(
            $space->initialValues(),
            $experiment->candidate->id,
            $experiment->candidate->label,
            $experiment->candidate->ruleset->version,
        ));
        self::assertSame($space->initialValues(), $space->validateExperiment($experiment));
        self::assertSame($manifest['parameters'][12]['path'], 'counters.spearman.knight.factor');
    }

    public function testInclusiveMinimaAndMaximaAreLegalAndSafeForEveryScenario(): void
    {
        [$experiment, , $space] = $this->fixture();

        foreach (['minimum', 'maximum'] as $bound) {
            $candidate = $space->candidateFromValues($space->boundValues($bound), 'bound-'.$bound, 'Bound '.$bound, 't30.0-'.$bound);
            $space->validateCandidate(ExperimentVariant::fromArray($candidate));

            $values = $experiment->toArray();
            $values['iterations'] = 1;
            $values['candidate'] = $candidate;
            $report = (new ExperimentRunner())->run(ExperimentDefinition::fromJson(json_encode($values, JSON_THROW_ON_ERROR)));
            self::assertSame(16, $report['experiment']['scenarioCount']);
            self::assertSame(32, $report['experiment']['combatCount']);
        }
    }

    public function testQuantizationUsesNearestHalfUpBeforeCandidateCreation(): void
    {
        [$experiment, , $space] = $this->fixture();
        $proposal = $space->initialValues();
        $proposal['units.soldier.attack'] = '7.0004';
        $proposal['units.soldier.structure'] = '18.0005';

        $candidate = $space->candidateFromValues($proposal, 'quantized', 'Quantized', 't30.0-quantized');

        self::assertSame('7', $candidate['units']['soldier']['attack']);
        self::assertSame('18.001', $candidate['units']['soldier']['structure']);
        self::assertSame($experiment->candidate->toArray()['counters'], $candidate['counters']);
    }

    public function testRejectsOutOfBoundsNonFiniteMissingAndUnknownProposals(): void
    {
        [, , $space] = $this->fixture();
        $proposal = $space->initialValues();

        foreach (['3.4999', 'NaN'] as $invalid) {
            $copy = $proposal;
            $copy['units.soldier.attack'] = $invalid;
            try {
                $space->quantize($copy);
                self::fail(sprintf('Proposal %s should have been rejected.', $invalid));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        unset($proposal['units.soldier.attack']);
        $proposal['units.soldier.unknown'] = '7';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly the 15 known parameter paths');
        $space->quantize($proposal);
    }

    public function testRejectsUnknownAndDuplicateManifestPaths(): void
    {
        [$experiment, $manifest] = $this->fixture();
        $manifest['parameters'][0]['path'] = 'units.soldier.cost';

        try {
            MonotypeSearchSpace::fromArray($manifest, $experiment);
            self::fail('An unknown path should have been rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Unknown or unopened', $exception->getMessage());
        }

        [, $manifest] = $this->fixture();
        $manifest['parameters'][1]['path'] = $manifest['parameters'][0]['path'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate search parameter path');
        MonotypeSearchSpace::fromArray($manifest, $experiment);
    }

    public function testRejectsAReferenceAlreadyModifiedBeforeSpaceConstruction(): void
    {
        [$experiment, $manifest] = $this->fixture();
        $values = $experiment->toArray();
        $values['baseline']['units']['soldier']['attack'] = '70';
        $modifiedReference = ExperimentDefinition::fromJson(json_encode($values, JSON_THROW_ON_ERROR));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical reference fingerprint mismatch');
        MonotypeSearchSpace::fromArray($manifest, $modifiedReference);
    }

    public function testWholeValidationIgnoresJsonFormattingAndCounterOrder(): void
    {
        [$experiment, $manifest] = $this->fixture();
        $values = $experiment->toArray();
        $values['baseline']['counters'] = array_reverse($values['baseline']['counters']);
        $values['candidate']['counters'] = array_reverse($values['candidate']['counters']);
        $reordered = ExperimentDefinition::fromJson(json_encode($values, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        self::assertSame(
            MonotypeSearchSpace::canonicalReferenceFingerprint($experiment),
            MonotypeSearchSpace::canonicalReferenceFingerprint($reordered),
        );
        self::assertCount(15, MonotypeSearchSpace::fromArray($manifest, $reordered)->parameters);
    }

    public function testRejectsManifestBoundsThatAreInvalidOrOffGrid(): void
    {
        [$experiment, $manifest] = $this->fixture();
        $manifest['parameters'][0]['minimum'] = '7.000001';

        try {
            MonotypeSearchSpace::fromArray($manifest, $experiment);
            self::fail('Bounds excluding the initial value should have been rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('do not contain', $exception->getMessage());
        }

        [, $manifest] = $this->fixture();
        $manifest['parameters'][0]['minimum'] = '3.500001';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('respect quantization');
        MonotypeSearchSpace::fromArray($manifest, $experiment);
    }

    public function testCounterIdentityDoesNotDependOnArrayOrder(): void
    {
        [$experiment, , $space] = $this->fixture();
        $candidate = $experiment->candidate->toArray();
        $candidate['counters'] = array_reverse($candidate['counters']);

        $values = $space->validateCandidate(ExperimentVariant::fromArray($candidate));

        self::assertSame('1.5', $values['counters.spearman.knight.factor']);
        self::assertSame('1.35', $values['counters.archer.spearman.factor']);
        self::assertSame('1.4', $values['counters.knight.archer.factor']);
    }

    public function testRejectsDuplicateOrChangedCounterIdentities(): void
    {
        [$experiment, , $space] = $this->fixture();
        $candidate = $experiment->candidate->toArray();
        $candidate['counters'][] = $candidate['counters'][0];

        try {
            $space->validateCandidate(ExperimentVariant::fromArray($candidate));
            self::fail('A duplicate counter should have been rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Duplicate directed counter', $exception->getMessage());
        }

        $candidate = $experiment->candidate->toArray();
        $candidate['counters'][0]['target'] = 'soldier';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('counter identities are frozen');
        $space->validateCandidate(ExperimentVariant::fromArray($candidate));
    }

    public function testRejectsCandidateAndExperimentFrozenFieldChanges(): void
    {
        [$experiment, , $space] = $this->fixture();
        $candidate = $experiment->candidate->toArray();
        $candidate['maxRounds'] = 4;

        try {
            $space->validateCandidate(ExperimentVariant::fromArray($candidate));
            self::fail('A max-round change should have been rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('maxRounds', $exception->getMessage());
        }

        $candidate = $experiment->candidate->toArray();
        $candidate['units']['soldier']['cost'] = 81;
        try {
            $space->validateCandidate(ExperimentVariant::fromArray($candidate));
            self::fail('A cost change should have been rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('cost', $exception->getMessage());
        }

        $values = $experiment->toArray();
        $values['baseSeed'] = 43;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field frozen by T30');
        $space->validateExperiment(ExperimentDefinition::fromJson(json_encode($values, JSON_THROW_ON_ERROR)));
    }

    public function testRejectsOffGridCandidateValuesInsteadOfCorrectingThem(): void
    {
        [$experiment, , $space] = $this->fixture();
        $candidate = $experiment->candidate->toArray();
        $candidate['units']['soldier']['attack'] = '7.000001';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not respect quantization');
        $space->validateCandidate(ExperimentVariant::fromArray($candidate));
    }

    /** @return array{ExperimentDefinition, array<string, mixed>, MonotypeSearchSpace} */
    private function fixture(): array
    {
        $experiment = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t28-defender-tie-break.json');
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/experiments/t30-proposed-search-space.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return [$experiment, $manifest, MonotypeSearchSpace::fromArray($manifest, $experiment)];
    }
}
