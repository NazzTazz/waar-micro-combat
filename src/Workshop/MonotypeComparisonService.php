<?php

namespace Waar\MicroCombat\Workshop;

final class MonotypeComparisonService
{
    public const RATES = ['winRate', 'drawRate', 'rawLossRatio', 'rawWoundedRatio', 'rawCasualtyRatio', 'appliedLossRatio', 'woundedRatio', 'captureRatio', 'freeRatio'];

    /** Compare only the confrontation explicitly chosen by the tester. */
    public function compare(array $beforeProfile, array $afterProfile, array $before, array $after, string $scenario): array
    {
        MonotypeMechanics::types($scenario);
        $reference = EngineProfile::fromArray($beforeProfile);
        $current = EngineProfile::fromArray($afterProfile);
        $old = $this->validatedRows($before, $reference);
        $new = $this->validatedRows($after, $current);
        foreach (['weather', 'baseSeed', 'iterations', 'budget', 'modelVersion', 'runtime'] as $key) {
            if ($before['context'][$key] !== $after['context'][$key]) throw new \InvalidArgumentException('Remesurez la référence : contexte incompatible ('.$key.').');
        }
        $sides = [];
        $changed = array_fill_keys([...self::RATES, 'initialCount'], false);
        foreach (['attacker', 'defender'] as $side) {
            $id = $scenario.'/'.$side;
            $deltas = [];
            foreach ([...self::RATES, 'initialCount'] as $metric) {
                $deltas[$metric] = $new[$id][$metric] - $old[$id][$metric];
                $changed[$metric] = $changed[$metric] || abs($deltas[$metric]) > 1e-9;
            }
            $sides[$side] = ['before'=>$old[$id], 'after'=>$new[$id], 'deltas'=>$deltas];
        }
        $observations = [];
        if (!$changed['winRate']) $observations[] = 'Même nombre de victoires observé pour les deux camps.';
        else $observations[] = 'La répartition des victoires a changé.';
        if ($changed['drawRate']) $observations[] = 'Le nombre de matchs nuls a changé.';
        if ($changed['rawLossRatio'] || $changed['rawWoundedRatio']) $observations[] = 'Les morts ou les blessés bruts ont changé.';
        if ($changed['appliedLossRatio'] || $changed['woundedRatio'] || $changed['captureRatio']) $observations[] = 'Les conséquences après compression et capture ont changé.';
        if ($changed['initialCount']) $observations[] = 'Le même budget achète des effectifs différents.';
        if (!in_array(true, $changed, true)) $observations = ['Aucun écart observé sur les indicateurs de cette confrontation.'];
        $mechanics = new MonotypeMechanics();
        return ['scenarioId'=>$scenario, 'context'=>$after['context'], 'sides'=>$sides,
            'summary'=>'Dans ces 50 simulations par profil : '.implode(' ', $observations),
            'mechanisms'=>['before'=>$mechanics->describe($reference, $before['context']['weather'], $scenario), 'after'=>$mechanics->describe($current, $after['context']['weather'], $scenario)],
        ];
    }

    private function validatedRows(array $measurement, EngineProfile $profile): array
    {
        $context = $measurement['context'] ?? [];
        $range = $measurement['batch']['iterationRange'] ?? [];
        if (($measurement['schemaVersion'] ?? '') !== 'waar-monotype-consequence-observations/0.2'
            || ($measurement['profileFingerprint'] ?? '') !== $profile->semanticFingerprint()
            || ($context['iterations'] ?? null) !== 50 || ($context['baseSeed'] ?? null) !== 42
            || ($context['budget'] ?? null) !== MonotypeMeasurementService::BUDGET
            || ($context['modelVersion'] ?? '') !== EngineProfile::MODEL_VERSION
            || !in_array($context['weather'] ?? '', EngineProfile::WEATHER, true)
            || !isset($context['runtime']['kind'], $context['runtime']['transport'], $context['runtime']['modelVersion'])
            || ($measurement['batch']['totalCombats'] ?? null) !== 800
            || ($range['start'] ?? null) !== 0 || ($range['endExclusive'] ?? null) !== 50 || ($range['total'] ?? null) !== 50 || ($range['complete'] ?? false) !== true
            || count($measurement['rows'] ?? []) !== 32) {
            throw new \InvalidArgumentException('Mesure complète de 50 répétitions attendue pour ce profil.');
        }
        $rows = [];
        foreach ($measurement['rows'] as $row) {
            [$a, $d] = MonotypeMechanics::types($row['scenarioId'] ?? '');
            $side = $row['side'] ?? '';
            $id = $row['id'] ?? '';
            if (!in_array($side, ['attacker', 'defender'], true) || $id !== $row['scenarioId'].'/'.$side || isset($rows[$id])
                || ($row['attackerType'] ?? '') !== $a || ($row['defenderType'] ?? '') !== $d
                || ($row['iterations'] ?? null) !== 50
                || ($row['initialCount'] ?? null) !== intdiv(MonotypeMeasurementService::BUDGET, $profile->costs()[$side === 'attacker' ? $a : $d])) {
                throw new \InvalidArgumentException('Observations monotypes incompatibles.');
            }
            foreach (self::RATES as $metric) {
                $value = $row[$metric] ?? null;
                if (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > 1) throw new \InvalidArgumentException('Taux invalide dans la mesure.');
            }
            $rows[$id] = $row;
        }
        return $rows;
    }
}
