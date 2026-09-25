<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\AcceptanceOverlayRenderer;
use Waar\MicroCombat\Experiment\FinalistComparisonBuilder;

require_once dirname(__DIR__).'/autoload.php';

final class FinalistComparisonTest extends TestCase
{
    private string $projectRoot;
    private string $runDirectory;
    private string $initialReport;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__);
        $this->runDirectory = $this->projectRoot.'/experiments/references/t31-standard-seed-314159';
        $this->initialReport = $this->projectRoot.'/experiments/references/t28-defender-tie-break/micro-report.json';
    }

    public function testBuildsReadOnlyComparisonFromFrozenT31ArtifactsWithoutMutation(): void
    {
        $paths = array_merge([
            $this->runDirectory.'/search-result.json',
            $this->runDirectory.'/objectives.json',
            $this->runDirectory.'/experiment.json',
            $this->runDirectory.'/candidate-initial.json',
            $this->runDirectory.'/evaluation-initial.json',
            $this->initialReport,
        ], glob($this->runDirectory.'/finalists/*/*.json') ?: []);
        $before = array_combine($paths, array_map('hash_file', array_fill(0, count($paths), 'sha256'), $paths));

        $comparison = (new FinalistComparisonBuilder())->buildFromDirectory($this->runDirectory, $this->initialReport);

        self::assertSame($before, array_combine($paths, array_map('hash_file', array_fill(0, count($paths), 'sha256'), $paths)));
        self::assertSame(FinalistComparisonBuilder::SCHEMA_VERSION, $comparison['schemaVersion']);
        self::assertTrue($comparison['run']['complete']);
        self::assertStringContainsString('aucun candidat strict', $comparison['run']['statusLabel']);
        self::assertSame('roles-a', $comparison['initial']['id']);
        self::assertCount(3, $comparison['finalists']);
        self::assertSame([1, 2, 3], array_column($comparison['finalists'], 'rank'));
        self::assertSame($comparison['finalists'][0]['id'], $comparison['defaultFinalistId']);
        self::assertCount(16, $comparison['scenarios']);
        self::assertCount(32, $comparison['initial']['rows']);
        self::assertTrue($comparison['objectiveDocument']['readOnly']);
        self::assertFalse($comparison['browserContract']['simulationAllowed']);
        self::assertFalse($comparison['browserContract']['objectiveInferenceAllowed']);

        foreach ([$comparison['initial'], ...$comparison['finalists']] as $candidate) {
            self::assertCount(32, array_unique(array_column($candidate['rows'], 'objectiveId')));
            foreach ($candidate['rows'] as $row) {
                self::assertSame($row['objectives']['survivors']['target'], $row['objectives']['economicValue']['target']);
                self::assertSame('survivors', $row['objectives']['economicValue']['aliasOf']);
                self::assertNull($row['objectives']['structure']);
                self::assertSame($row['observation']['survivors'], $row['observation']['economicValue']);
                self::assertContains($row['shape'], ['circle', 'diamond']);
            }
        }

        $download = json_decode($comparison['finalists'][1]['downloads']['variant']['json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($comparison['finalists'][1]['id'], $download['artifact']['id']);
        self::assertSame($comparison['finalists'][1]['id'], $download['provenance']['candidateId']);
        self::assertSame(2, $download['provenance']['rank']);
        self::assertSame($comparison['run']['planId'], $download['provenance']['planId']);
    }

    public function testOneFinalistAndInterruptedRunRemainExplicit(): void
    {
        $temporary = sys_get_temp_dir().'/waar-t32-'.bin2hex(random_bytes(6));
        mkdir($temporary.'/finalists', 0777, true);
        foreach (['objectives.json', 'experiment.json', 'candidate-initial.json', 'evaluation-initial.json'] as $name) {
            copy($this->runDirectory.'/'.$name, $temporary.'/'.$name);
        }
        $result = json_decode((string) file_get_contents($this->runDirectory.'/search-result.json'), true, 512, JSON_THROW_ON_ERROR);
        $result['state'] = 'interrupted';
        $result['outcome'] = 'proposal-limit-reached';
        $result['counts']['evaluatedCandidates'] = 64;
        $result['counts']['finalists'] = 1;
        $result['finalists'] = [$result['finalists'][0]];
        foreach ($result['finalists'][0]['artifacts'] as $relative) {
            $destination = $temporary.'/'.dirname($relative);
            if (!is_dir($destination)) {
                mkdir($destination, 0777, true);
            }
            copy($this->runDirectory.'/'.$relative, $temporary.'/'.$relative);
        }
        file_put_contents($temporary.'/search-result.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $comparison = (new FinalistComparisonBuilder())->buildFromDirectory($temporary, $this->initialReport);

        self::assertFalse($comparison['run']['complete']);
        self::assertSame('Run partiel', $comparison['run']['statusLabel']);
        self::assertStringContainsString('64/128', $comparison['run']['statusDetail']);
        self::assertCount(1, $comparison['finalists']);
    }

    public function testRendererProducesOneStandaloneReportWithPinnedEchartsAndNoAcceptanceClaim(): void
    {
        $comparison = (new FinalistComparisonBuilder())->buildFromDirectory($this->runDirectory, $this->initialReport);
        $resources = dirname(__DIR__).'/resources';
        $renderer = new AcceptanceOverlayRenderer();
        $html = $renderer->finalistComparisonHtml(
            $comparison,
            (string) file_get_contents($resources.'/finalist-comparison.html'),
            (string) file_get_contents($resources.'/vendor/echarts-5.6.0.min.js'),
            (string) file_get_contents($resources.'/finalist-comparison-model.js'),
            (string) file_get_contents($resources.'/finalist-comparison-app.js'),
        );

        foreach (['__ECHARTS_BUNDLE__', '__FINALIST_MODEL__', '__FINALIST_APP__', '__COMPARISON_JSON__', '<script src=', 'cdn.'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        foreach (['Initial → candidat', 'Témoin neutre T28', 'État des 32 objectifs canoniques', 'Objectifs PO en lecture seule', 'Variante complète', 'Aucun verdict d’acceptation'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringContainsString('simulationAllowed', $html);
        self::assertStringContainsString('objectiveInferenceAllowed', $html);
        self::assertStringContainsString('ECharts', (string) file_get_contents($resources.'/vendor/echarts-5.6.0-LICENSE.txt'));
    }
}
