<?php

namespace Waar\MicroCombat\Workshop;

/** Presentation adapter. Reuses the T27 editor without rewriting historical reports. */
final class T27Editor
{
    public function render(array $profile, array $measurement, array $zones): array
    {
        $p=EngineProfile::fromArray($profile);
        $context=$measurement['context']??[];
        if (($context['objectiveMetric']??null)!=='rawCasualtyRatio') throw new \InvalidArgumentException('Mesure obsolète : remesurez les blessés et morts avant compression.');
        (new ConsequenceObjectives())->validate($profile,$zones,$context['weather']??'', $context['baseSeed']??-1,$context['iterations']??0);
        if (($measurement['profileFingerprint']??null)!==$p->semanticFingerprint() || ($measurement['modelVersion']??null)!==EngineProfile::MODEL_VERSION) throw new \InvalidArgumentException('Mesure incompatible.');
        $fingerprint=hash('sha256',json_encode([$p->semanticFingerprint(),$context],JSON_THROW_ON_ERROR));
        $rows=[];$editorZones=[];
        $labels=['soldier'=>'Soldat','spearman'=>'Lancier','archer'=>'Archer','knight'=>'Chevalier'];
        foreach ($measurement['rows'] as $r) {
            $type=$r['side']==='attacker'?$r['attackerType']:$r['defenderType'];
            $rows[]=['scenarioId'=>$r['scenarioId'],'scenarioLabel'=>$labels[$r['attackerType']].' / '.$labels[$r['defenderType']], 'side'=>$r['side'],'focus'=>$r['side']==='attacker',
                'army'=>[$type=>intdiv(MonotypeMeasurementService::BUDGET,$p->costs()[$type])],
                'micro'=>['baseline'=>['iterations'=>$context['iterations']],'vector'=>['x'=>['from'=>$r['winRate'],'to'=>$r['winRate']],'y'=>['rawCasualtyRatio'=>['from'=>$r['rawCasualtyRatio'],'to'=>$r['rawCasualtyRatio']]]]],
                'legacy'=>['coordinates'=>['x'=>null,'y'=>['rawCasualtyRatio'=>null]]]];
        }
        foreach ($zones as $z) $editorZones[]=['id'=>$z['id'],'scenarioId'=>$z['scenarioId'],'side'=>$z['side'],'endpoint'=>'tip','xMetric'=>'winRate','yMetric'=>'rawCasualtyRatio','shape'=>'ellipse','center'=>$z['center'],'radii'=>$z['radii'],'enabled'=>true,'approval'=>'draft',
            'source'=>['kind'=>'observation','referenceId'=>$p->semanticFingerprint(),'referencePointId'=>$z['id'],'originalCenter'=>$z['center'],'modifiedManually'=>false]];
        $data=['experiment'=>['id'=>$p->id],'axes'=>['y'=>[['id'=>'rawCasualtyRatio','label'=>'Blessés + morts (avant compression)']]],
            'comparisonProfile'=>['id'=>'workshop-consequences-v1','valuationId'=>'waar-profile-costs-v1','valuation'=>$p->costs(),'commonBudget'=>MonotypeMeasurementService::BUDGET],
            'legacyReference'=>['available'=>false,'id'=>$p->semanticFingerprint(),'sourceFingerprint'=>$p->semanticFingerprint(),'corpusFingerprint'=>$fingerprint],
            'objectiveReference'=>['id'=>$p->semanticFingerprint(),'label'=>$p->label],
            'ui'=>['workshop'=>true,'title'=>'Zones','referenceAvailable'=>false,'defaultEndpoint'=>'tip','editableEndpoints'=>['tip'],'pairView'=>true,'complementaryWinRates'=>$p->equalityPolicy==='defender','zoneLabel'=>'Objectifs','colorBy'=>'attackerType','noReferenceMessage'=>''],
            'designSurface'=>['unitOrder'=>array_keys(EngineProfile::UNIT_COSTS)],'rows'=>$rows,
            'zonesDocument'=>['schemaVersion'=>'waar-consequence-editor-zones/0.1','experimentId'=>$p->id,'corpusFingerprint'=>$fingerprint,'comparisonProfileId'=>'workshop-consequences-v1','valuationId'=>'waar-profile-costs-v1',
                'generation'=>['id'=>'workshop-consequences-v1','label'=>'Objectifs de pertes brutes','radiusX'=>.05,'radiusY'=>.1,'readOnly'=>false],'zones'=>$editorZones]];
        $root=dirname(__DIR__,2);
        $template=file_get_contents($root.'/resources/acceptance-overlay.html');
        $template=str_replace(['<script>__ECHARTS_BUNDLE__</script>','<script>__ZONES_MODEL__</script>','<script>__OVERLAY_APP__</script>','__OVERLAY_JSON__'],
            ['<script src="/editor/echarts.js"></script>','<script src="/editor/model.js"></script>','<script src="/editor/app.js"></script>',json_encode($data,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP)],$template);
        $template=str_replace('<details open>','<details>',$template);
        return ['html'=>str_replace('</head>','<link rel="stylesheet" href="/editor.css"></head>',$template),'fingerprint'=>$fingerprint];
    }
}
