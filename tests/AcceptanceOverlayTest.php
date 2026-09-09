<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Experiment\AcceptanceOverlayBuilder;
use Waar\MicroCombat\Experiment\AcceptanceOverlayRenderer;
use Waar\MicroCombat\Experiment\AcceptanceZoneEvaluator;
use Waar\MicroCombat\Experiment\ExperimentDefinition;
use Waar\MicroCombat\Experiment\ExperimentRunner;

require_once dirname(__DIR__).'/autoload.php';

final class AcceptanceOverlayTest extends TestCase
{
    public function testBuildsEditableLegacyCenteredZonesForBothComparableMetrics(): void
    {
        [$report, $legacy] = $this->fixture();
        $before = json_encode($report, JSON_THROW_ON_ERROR);
        $overlay = (new AcceptanceOverlayBuilder())->build($report, $legacy);

        self::assertSame($before, json_encode($report, JSON_THROW_ON_ERROR), 'Building the overlay must not alter the micro results.');
        self::assertSame(AcceptanceOverlayBuilder::SCHEMA_VERSION, $overlay['schemaVersion']);
        self::assertCount(2, $overlay['rows']);
        self::assertCount(8, $overlay['zonesDocument']['zones']);
        self::assertSame(['survivors', 'economicValue'], array_values(array_unique(array_column($overlay['zonesDocument']['zones'], 'yMetric'))));

        foreach ($overlay['zonesDocument']['zones'] as $zone) {
            self::assertSame('draft', $zone['approval']);
            self::assertSame('legacy', $zone['source']['kind']);
            self::assertSame('fixture-legacy', $zone['source']['referenceId']);
            self::assertSame($zone['center'], $zone['source']['originalCenter']);
            self::assertFalse($zone['source']['modifiedManually']);
            self::assertSame(['x' => 0.05, 'y' => 0.1], $zone['radii']);
            self::assertArrayNotHasKey('state', $zone, 'Evaluation state must remain derived from the current observations.');
            self::assertArrayNotHasKey('readOnly', $zone, 'Read-only is a delivery mode, not a portable constraint property.');
            self::assertContains($overlay['zoneStates'][$zone['id']], ['inside', 'outside']);
        }
        self::assertFalse($overlay['zonesDocument']['generation']['readOnly']);
    }

    public function testRejectsCorpusOrCommonValuationDrift(): void
    {
        [$report, $legacy] = $this->fixture();
        $legacy['corpus']['scenarios'][0]['attacker']['soldier'] = 31;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('different corpus');
        (new AcceptanceOverlayBuilder())->build($report, $legacy);
    }

    public function testRejectsMicroCostsOutsideTheDeclaredCommonValuation(): void
    {
        [$report, $legacy] = $this->fixture();
        $report['candidate']['units']['soldier']['cost'] = 81;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('common valuation');
        (new AcceptanceOverlayBuilder())->build($report, $legacy);
    }

    public function testEllipseGeometryCoversCenterBoundaryOutsideClippingAndMissingMetric(): void
    {
        $evaluator = new AcceptanceZoneEvaluator();
        $zone = ['center' => ['x' => 0.5, 'y' => 0.5], 'radii' => ['x' => 0.05, 'y' => 0.1]];

        self::assertSame('inside', $evaluator->evaluate(0.5, 0.5, $zone));
        self::assertSame('inside', $evaluator->evaluate(0.55, 0.5, $zone), 'The ellipse boundary is included.');
        self::assertSame('outside', $evaluator->evaluate(0.551, 0.5, $zone));
        self::assertSame('not-applicable', $evaluator->evaluate(0.5, null, $zone));

        $clipped = ['center' => ['x' => 1.0, 'y' => 0.5], 'radii' => ['x' => 0.05, 'y' => 0.1]];
        self::assertSame('inside', $evaluator->evaluate(1.0, 0.5, $clipped));
        self::assertSame('outside', $evaluator->evaluate(1.01, 0.5, $clipped), 'The accepted domain is clipped to [0,1]².');
    }

    public function testRendererEmbedsPinnedEchartsAndTheCompleteEditor(): void
    {
        [$report, $legacy] = $this->fixture();
        $overlay = (new AcceptanceOverlayBuilder())->build($report, $legacy);
        $template = (string) file_get_contents(dirname(__DIR__).'/resources/acceptance-overlay.html');
        $bundle = (string) file_get_contents(dirname(__DIR__).'/resources/vendor/echarts-5.6.0.min.js');
        $zonesModel = (string) file_get_contents(dirname(__DIR__).'/resources/acceptance-zones-model.js');
        $application = (string) file_get_contents(dirname(__DIR__).'/resources/acceptance-overlay-app.js');
        $license = (string) file_get_contents(dirname(__DIR__).'/resources/vendor/echarts-5.6.0-LICENSE.txt');
        $html = (new AcceptanceOverlayRenderer())->html($overlay, $template, $bundle, $zonesModel, $application);

        self::assertSame('bf4a223524e40b77c304bec67e1222cf551f14880cf42c69dc046558e11c07b1', hash('sha256', $bundle));
        self::assertStringContainsString('Apache License', $license);
        self::assertStringNotContainsString('__ECHARTS_BUNDLE__', $html);
        self::assertStringNotContainsString('__ZONES_MODEL__', $html);
        self::assertStringNotContainsString('__OVERLAY_APP__', $html);
        self::assertStringNotContainsString('__OVERLAY_JSON__', $html);
        self::assertStringNotContainsString('<script src=', $html);
        self::assertStringContainsString('ECharts 5.6.0', $html);
        self::assertStringContainsString('Le moteur graphique ECharts embarqué n’a pas pu être chargé', $html);
        self::assertStringContainsString('animationDuration:animate?180:0', str_replace(' ', '', $html));
        foreach (['survivors', 'structure', 'economicValue', 'focus', 'attacker', 'defender', 'both'] as $viewValue) {
            self::assertStringContainsString($viewValue, $html);
        }
        foreach (['Modifier la zone', 'Importer', 'Exporter JSON', 'Annuler', 'Rétablir', 'Confirmer la contrainte', 'Désactiver', 'Réancrer la provenance'] as $control) {
            self::assertStringContainsString($control, $html);
        }
        self::assertStringContainsString('MAX_IMPORT_BYTES', $html);
        self::assertStringContainsString('modifiedManually', $html);
        $compactApplication = str_replace(["\r", "\n", ' '], '', $application);
        self::assertStringContainsString('circleMode.checked=editorState.selectZone(zone?.id||null,!!zone&&Math.abs(zone.radii.x-zone.radii.y)<=model.BOUNDARY_TOLERANCE);', $compactApplication);
        self::assertStringContainsString('if(!editorState.hasActiveDrag())return;', $compactApplication);
        self::assertStringContainsString("if(event.key==='Escape'&&editorState.hasActiveDrag())", $compactApplication);

        $schema = json_decode((string) file_get_contents(dirname(__DIR__).'/schema/acceptance-zones.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(AcceptanceOverlayBuilder::ZONES_SCHEMA_VERSION, $schema['properties']['schemaVersion']['const']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testT24ReportFingerprintRemainsUnchanged(): void
    {
        $experiment = ExperimentDefinition::fromFile(dirname(__DIR__).'/experiments/t24-astra-vector-corrections.json');
        $report = (new ExperimentRunner())->run($experiment);
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        self::assertSame('1d3cfe2363690131324db392588b4eb4dbfeef08374f4b5e5f080bd82312549a', hash('sha256', $json));
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function fixture(): array
    {
        $values = json_decode((string) file_get_contents(dirname(__DIR__).'/experiments/t24-astra-vector-corrections.json'), true, 512, JSON_THROW_ON_ERROR);
        $values['iterations'] = 2;
        $values['scenarios'] = [$values['scenarios'][0]];
        $experiment = ExperimentDefinition::fromJson(json_encode($values, JSON_THROW_ON_ERROR));
        $report = (new ExperimentRunner())->run($experiment);
        $legacyRows = [];
        foreach ($report['rows'] as $row) {
            $win = 'attacker' === $row['side'] ? 0 : 2;
            $initialCount = array_sum($row['army']);
            $initialValue = 0;
            foreach ($row['army'] as $unit => $count) {
                $initialValue += $count * $values['baseline']['units'][$unit]['cost'];
            }
            $legacyRows[] = [
                'scenarioId' => $row['scenarioId'],
                'side' => $row['side'],
                'wins' => $win,
                'draws' => 0,
                'repetitions' => 2,
                'initial' => ['byType' => $row['army'], 'count' => $initialCount, 'commonValue' => $initialValue],
                'coordinates' => ['x' => $win / 2, 'y' => ['operationalSurvivorsRatio' => 0.95, 'operationalEconomicValueRatio' => 0.94, 'structureRatio' => null]],
            ];
        }
        $legacy = [
            'schemaVersion' => 'waar-legacy-reference/0.1',
            'reference' => ['id' => 'fixture-legacy', 'rulesetVersion' => 'fixture-v1', 'sourceFingerprint' => str_repeat('a', 64)],
            'corpus' => ['experimentId' => $report['experiment']['id'], 'fingerprint' => str_repeat('b', 64), 'scenarios' => $report['scenarios']],
            'sampling' => ['baseSeed' => $report['experiment']['baseSeed'], 'repetitions' => 2],
            'comparisonProfile' => [
                'id' => 'operational-common-value-v1',
                'valuationId' => 't24-common-valuation-v1',
                'valuation' => ['soldier' => 80, 'spearman' => 110, 'archer' => 130, 'knight' => 350],
            ],
            'rows' => $legacyRows,
        ];

        return [$report, $legacy];
    }
}
