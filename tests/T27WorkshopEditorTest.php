<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\Workshop\EngineProfile;
use Waar\MicroCombat\Workshop\MonotypeMeasurementService;
use Waar\MicroCombat\Workshop\T27Editor;

final class T27WorkshopEditorTest extends TestCase
{
    public function testOriginalEditorReceivesDistinctConsequenceContract(): void
    {
        $profile = EngineProfile::defaults();
        $measurement = (new MonotypeMeasurementService())->measure($profile, 'neutral', 42, 1);
        $zones = array_map(static fn ($r) => ['id' => $r['id'], 'scenarioId' => $r['scenarioId'], 'side' => $r['side'], 'center' => ['x' => $r['winRate'], 'y' => $r['rawCasualtyRatio']], 'radii' => ['x' => .05, 'y' => .1], 'sourceFingerprint' => $measurement['profileFingerprint'], 'modelVersion' => $measurement['modelVersion'], 'context' => $measurement['context']], $measurement['rows']);
        $result = (new T27Editor())->render($profile, $measurement, $zones);
        self::assertStringContainsString('/editor/app.js', $result['html']);
        self::assertStringContainsString('id="undo"', $result['html']);
        self::assertStringContainsString('id="circle-mode"', $result['html']);
        preg_match('/<script id="overlay-data" type="application\/json">(.*?)<\/script>/s', $result['html'], $match);
        $data = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('waar-consequence-editor-zones/0.1', $data['zonesDocument']['schemaVersion']);
        self::assertSame('rawCasualtyRatio', $data['axes']['y'][0]['id']);
        self::assertCount(32, $data['rows']);
        self::assertSame($measurement['rows'][0]['rawCasualtyRatio'], $data['rows'][0]['micro']['vector']['y']['rawCasualtyRatio']['from']);
        self::assertSame($result['fingerprint'], $data['zonesDocument']['corpusFingerprint']);
        self::assertFalse($data['legacyReference']['available']);
    }
}
