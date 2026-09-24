<?php

declare(strict_types=1);

namespace Waar\MicroCombat\Exploration;

use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\Workshop\CohortRequestFactory;
use Waar\MicroCombat\Workshop\EngineProfile;

final class ParametricCampaign
{
    public const SCHEMA = 'waar-parametric-campaign/1';
    public const TYPES = ['soldier', 'spearman', 'archer', 'knight'];

    /** @return array{plan:array<string,mixed>,profile:array<string,mixed>,planPath:string,profilePath:string,outputPath:string} */
    public static function load(string $planPath): array
    {
        $planPath = realpath($planPath) ?: throw new \InvalidArgumentException("Plan introuvable : {$planPath}");
        $plan = self::jsonFile($planPath);
        $base = dirname($planPath);
        self::exactKeys($plan, ['schemaVersion', 'profile', 'output', 'sampling', 'limits', 'axes', 'crosses', 'scenarios'], 'plan');
        if (($plan['schemaVersion'] ?? null) !== self::SCHEMA) {
            throw new \InvalidArgumentException('schemaVersion de campagne incompatible.');
        }
        foreach (['profile', 'output'] as $key) {
            if (!is_string($plan[$key] ?? null) || trim($plan[$key]) === '') {
                throw new \InvalidArgumentException("{$key} doit être un chemin non vide.");
            }
        }
        $profilePath = self::absolute($base, $plan['profile']);
        $profilePath = realpath($profilePath) ?: throw new \InvalidArgumentException("Profil introuvable : {$profilePath}");
        $profile = self::jsonFile($profilePath);
        $errors = EngineProfile::validate($profile);
        if ($errors) {
            throw new \InvalidArgumentException('Profil invalide : '.json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        self::validatePlan($plan, $profile);
        return ['plan' => $plan, 'profile' => $profile, 'planPath' => $planPath, 'profilePath' => $profilePath, 'outputPath' => self::absolute($base, $plan['output'])];
    }

    /** @return \Generator<int,array<string,mixed>> */
    public static function experiments(array $plan, array $reference): \Generator
    {
        $subsets = self::subsets($plan);
        $seen = [];
        foreach ($plan['scenarios'] as $scenario) {
            foreach (self::compositionVariants($scenario) as $composition) {
                foreach ($subsets as $axisIds) {
                    foreach (self::axisProducts($plan['axes'], $axisIds) as $values) {
                        $profile = $reference;
                        foreach ($values as $axisId => $value) {
                            $profile = self::applyAxis($profile, $axisId, $plan['axes'][$axisId], $value);
                        }
                        $validated = EngineProfile::fromArray($profile);
                        $armies = self::armies($scenario, $composition, $validated->costs());
                        $effective = ['profile' => $validated->toArray(), 'scenario' => ['id' => $scenario['id'], 'armies' => $armies, 'weather' => $scenario['weather'], 'modifiers' => $scenario['modifiers'], 'directions' => $scenario['directions']]];
                        $id = substr(hash('sha256', self::canonicalJson($effective)), 0, 24);
                        if (isset($seen[$id])) {
                            continue;
                        }
                        $seen[$id] = true;
                        yield ['id' => $id, 'scenarioId' => $scenario['id'], 'compositionId' => $composition['id'], 'axes' => $values, 'profile' => $validated->toArray(), 'profileFingerprint' => $validated->semanticFingerprint(), 'armies' => $armies, 'weather' => $scenario['weather'], 'modifiers' => $scenario['modifiers'], 'directions' => $scenario['directions']];
                    }
                }
            }
        }
    }

    /** @return array{experiments:int,directions:int,combats:int,warnings:list<string>,axes:list<string>,scenarios:list<string>} */
    public static function preview(array $plan, array $profile): array
    {
        $experiments = $directions = 0;
        $warnings = [];
        foreach (self::experiments($plan, $profile) as $experiment) {
            $experiments++;
            $directions += count(self::directions($experiment['directions']));
            foreach (['A', 'B'] as $camp) {
                $a = $experiment['armies'][$camp];
                if ($a['targetBudget'] !== null && $a['remainder'] > 0) {
                    $warnings[] = "{$experiment['scenarioId']}/{$experiment['compositionId']} camp {$camp}: reliquat {$a['remainder']}.";
                }
            }
            if ($experiment['armies']['A']['actualBudget'] !== $experiment['armies']['B']['actualBudget']) {
                $warnings[] = "{$experiment['scenarioId']}/{$experiment['compositionId']}: budgets réels A/B différents.";
            }
        }
        if (($profile['combat']['surrenderEnabled'] ?? false) === false) {
            foreach ($plan['axes'] as $id => $axis) {
                if ($axis['path'] === 'combat.surrenderDeadPercent') {
                    $warnings[] = "Axe {$id}: seuil de reddition inactif tant que la reddition reste désactivée.";
                }
            }
        }
        $combats = $directions * $plan['sampling']['repetitions'];
        if ($combats > $plan['limits']['maxCombats']) {
            throw new \InvalidArgumentException("Plafond dépassé : {$combats} combats prévus, maximum {$plan['limits']['maxCombats']}.");
        }
        return ['experiments' => $experiments, 'directions' => $directions, 'combats' => $combats, 'warnings' => array_values(array_unique($warnings)), 'axes' => array_keys($plan['axes']), 'scenarios' => array_column($plan['scenarios'], 'id')];
    }

    /** @return list<array{attacker:string,defender:string}> */
    public static function directions(string $mode): array
    {
        return match($mode) {
            'both' => [['attacker' => 'A', 'defender' => 'B'], ['attacker' => 'B', 'defender' => 'A']],'A-attacks-B' => [['attacker' => 'A', 'defender' => 'B']],'B-attacks-A' => [['attacker' => 'B', 'defender' => 'A']],default => throw new \InvalidArgumentException("Sens inconnu : {$mode}")
        };
    }

    /** @return array<string,mixed> */
    public static function batchRequest(array $experiment, int $baseSeed, int $start, int $iterations, int $total): array
    {
        $profile = EngineProfile::fromArray($experiment['profile']);
        $factory = new CohortRequestFactory();
        $scenarios = [];
        foreach (self::directions($experiment['directions']) as $direction) {
            $a = $direction['attacker'];
            $d = $direction['defender'];
            $combat = $factory->combat($profile, $experiment['armies'][$a]['units'], $experiment['armies'][$d]['units'], $baseSeed, $experiment['weather'][$a], $experiment['weather'][$d], $experiment['modifiers'][$a], $experiment['modifiers'][$d], 'none', $a, $d);
            $scenarios[] = ['id' => $a.'-'.$d, 'seedKey' => 0, 'armyIdentities' => $combat['armyIdentities'], 'attacker' => $combat['attacker'], 'defender' => $combat['defender']];
        }
        return ['schemaVersion' => 'waar-combat-campaign-batch-request/1', 'stochasticEngineVersion' => CohortRequestFactory::STOCHASTIC_VERSION, 'ruleset' => $profile->ruleset(), 'baseSeed' => $baseSeed, 'iterations' => $iterations, 'startIteration' => $start, 'totalIterations' => $total, 'consequences' => ['policyVersion' => CohortRequestFactory::POLICY_VERSION, 'compressionPercent' => $profile->lossCompressionPercent, 'capturePercent' => $profile->capturePercent], 'scenarios' => $scenarios];
    }

    public static function assertResponse(array $response, array $request): void
    {
        CohortRequestFactory::assertProvenance($response['consequenceProvenance'] ?? [], $request['consequences']);
        CohortRequestFactory::assertBatchRandomProvenance($response, $request['scenarios']);
        if (($response['unitOrder'] ?? null) !== self::TYPES || ($response['projectedCategoryOrder'] ?? null) !== ['healthy', 'wounded', 'dead', 'prisoners']) {
            throw new \RuntimeException('Ordre batch incompatible.');
        }
        if (($response['startIteration'] ?? null) !== $request['startIteration'] || ($response['iterations'] ?? null) !== $request['iterations']) {
            throw new \RuntimeException('Intervalle batch incompatible.');
        }
    }

    public static function canonicalJson(mixed $value): string
    {
        $sort = static function (mixed$v) use (&$sort): mixed {
            if (!is_array($v)) {
                return$v;
            }
            foreach ($v as $k => $item) {
                $v[$k] = $sort($item);
            }
            if (!array_is_list($v)) {
                ksort($v);
            }
            return$v;
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function atomicJson(string $path, array $value): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Création impossible : {$dir}");
        }
        $tmp = tempnam($dir, '.tmp-');
        if ($tmp === false) {
            throw new \RuntimeException('Fichier temporaire impossible.');
        }
        try {
            if (file_put_contents($tmp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n") === false || !rename($tmp, $path)) {
                throw new \RuntimeException("Écriture atomique impossible : {$path}");
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private static function validatePlan(array $plan, array $profile): void
    {
        $sampling = $plan['sampling'] ?? null;
        if (!is_array($sampling) || array_is_list($sampling)) {
            throw new \InvalidArgumentException('sampling doit être un objet.');
        }
        self::exactKeys($sampling, ['repetitions', 'baseSeed', 'batchSize'], 'sampling');
        foreach ([['repetitions', 1, 2147483647], ['batchSize', 1, 100], ['baseSeed', 0, 2147483647]] as [$k,$min,$max]) {
            if (!is_int($sampling[$k] ?? null) || $sampling[$k] < $min || $sampling[$k] > $max) {
                throw new \InvalidArgumentException("sampling.{$k} hors limites.");
            }
        }
        $limits = $plan['limits'] ?? null;
        if (!is_array($limits) || array_is_list($limits) || !is_int($limits['maxCombats'] ?? null) || $limits['maxCombats'] < 1) {
            throw new \InvalidArgumentException('limits.maxCombats doit être un entier positif.');
        }
        self::exactKeys($limits, ['maxCombats'], 'limits');
        if (!is_array($plan['axes'] ?? null) || ($plan['axes'] !== [] && array_is_list($plan['axes']))) {
            throw new \InvalidArgumentException('axes doit être un objet.');
        }
        foreach ($plan['axes'] as $id => $axis) {
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) || !is_array($axis) || array_is_list($axis)) {
                throw new \InvalidArgumentException("Axe invalide : {$id}");
            }
            self::exactKeys($axis, ['path', 'values'], 'axes.'.$id);
            if (!is_string($axis['path'] ?? null) || !is_array($axis['values'] ?? null) || !array_is_list($axis['values']) || $axis['values'] === []) {
                throw new \InvalidArgumentException("Axe {$id} incomplet.");
            }
            foreach ($axis['values'] as $value) {
                $candidate = self::applyAxis($profile, $id, $axis, $value);
                $errors = EngineProfile::validate($candidate);
                if ($errors) {
                    throw new \InvalidArgumentException("Valeur invalide pour {$id}: ".json_encode($errors, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        self::subsets($plan);
        if (!is_array($plan['scenarios'] ?? null) || !array_is_list($plan['scenarios']) || $plan['scenarios'] === []) {
            throw new \InvalidArgumentException('scenarios doit être une liste non vide.');
        }
        $ids = [];
        foreach ($plan['scenarios'] as $i => $scenario) {
            if (!is_array($scenario) || array_is_list($scenario)) {
                throw new \InvalidArgumentException("Scénario {$i} invalide.");
            }
            self::exactKeys($scenario, ['id', 'directions', 'weather', 'modifiers', 'armies', 'compositionVariants'], 'scenarios.'.$i);
            $id = $scenario['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) || isset($ids[$id])) {
                throw new \InvalidArgumentException("Identifiant de scénario invalide ou dupliqué : {$id}");
            }
            $ids[$id] = true;
            self::directions($scenario['directions'] ?? '');
            foreach (['A', 'B'] as $camp) {
                $weather = $scenario['weather'][$camp] ?? null;
                if (!in_array($weather, EngineProfile::WEATHER, true)) {
                    throw new \InvalidArgumentException("Météo {$camp} invalide dans {$id}.");
                }
                self::validateModifiers($scenario['modifiers'][$camp] ?? null, "{$id}.modifiers.{$camp}");
                self::validateArmy($scenario['armies'][$camp] ?? null, "{$id}.armies.{$camp}");
            }
            foreach (self::compositionVariants($scenario) as $variant) {
                self::armies($scenario, $variant, EngineProfile::fromArray($profile)->costs());
            }
        }
    }

    /** @return list<list<string>> */
    private static function subsets(array $plan): array
    {
        $axes = array_keys($plan['axes']);
        $crosses = $plan['crosses'] ?? null;
        if (!is_array($crosses) || array_is_list($crosses)) {
            throw new \InvalidArgumentException('crosses doit être un objet.');
        }
        self::exactKeys($crosses, ['singles', 'pairs', 'triplets'], 'crosses');
        $sets = ['' => []];
        if (($crosses['singles'] ?? null) !== true) {
            throw new \InvalidArgumentException('crosses.singles doit être true afin de conserver les témoins individuels.');
        }
        foreach ($axes as $a) {
            $sets[$a] = [$a];
        }
        foreach (self::crossList($crosses['pairs'] ?? [], 2, $axes, 'pairs') as $set) {
            foreach (self::lowerSets($set) as $lower) {
                sort($lower);
                $sets[implode('|', $lower)] = $lower;
            }
        }
        foreach (self::crossList($crosses['triplets'] ?? [], 3, $axes, 'triplets') as $set) {
            foreach (self::lowerSets($set) as $lower) {
                sort($lower);
                $sets[implode('|', $lower)] = $lower;
            }
        }
        ksort($sets);
        return array_values($sets);
    }
    private static function crossList(mixed$value, int$size, array$axes, string$name): array
    {
        if ($value === 'all') {
            if (count($axes) > 12) {
                throw new \InvalidArgumentException("crosses.{$name}=all limité à 12 axes.");
            }
            return self::combinations($axes, $size);
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("crosses.{$name} doit être 'all' ou une liste.");
        }
        $out = [];
        foreach ($value as $i => $set) {
            if (!is_array($set) || array_values(array_unique($set)) !== $set || count($set) !== $size || array_diff($set, $axes)) {
                throw new \InvalidArgumentException("Croisement {$name}[{$i}] invalide.");
            }
            $out[] = $set;
        }
        return$out;
    }
    private static function combinations(array$items, int$n, int$start = 0, array$prefix = []): array
    {
        if ($n === 0) {
            return[$prefix];
        }
        $out = [];
        for ($i = $start;$i <= count($items) - $n;$i++) {
            array_push($out, ...self::combinations($items, $n - 1, $i + 1, [...$prefix, $items[$i]]));
        }
        return$out;
    }
    private static function lowerSets(array$set): array
    {
        $out = [];
        for ($mask = 1;$mask < (1 << count($set));$mask++) {
            $row = [];
            foreach ($set as $i => $v) {
                if ($mask & (1 << $i)) {
                    $row[] = $v;
                }
            }
            $out[] = $row;
        }
        return$out;
    }
    private static function axisProducts(array$axes, array$ids, int$i = 0, array$current = []): \Generator
    {
        if ($i === count($ids)) {
            yield$current;
            return;
        }
        $id = $ids[$i];
        foreach ($axes[$id]['values'] as $value) {
            yield from self::axisProducts($axes, $ids, $i + 1, [...$current, $id => $value]);
        }
    }

    private static function applyAxis(array$profile, string$id, array$axis, mixed$value): array
    {
        $path = $axis['path'];
        if ($path === 'combat.surrender') {
            if (!is_array($value) || array_is_list($value) || !is_bool($value['enabled'] ?? null) || array_diff(array_keys($value), ['enabled', 'deadPercent'])) {
                throw new \InvalidArgumentException("État conditionnel invalide pour {$id}.");
            }
            $profile['combat']['surrenderEnabled'] = $value['enabled'];
            if (array_key_exists('deadPercent', $value)) {
                $profile['combat']['surrenderDeadPercent'] = $value['deadPercent'];
            }
            return$profile;
        }
        if (preg_match('/^relations\.([a-z]+)>([a-z]+)\.factor$/', $path, $m)) {
            if (!in_array($m[1], self::TYPES, true) || !in_array($m[2], self::TYPES, true) || $m[1] === $m[2]) {
                throw new \InvalidArgumentException("Contre invalide : {$path}");
            }
            $found = false;
            foreach ($profile['relations'] as &$relation) {
                if ($relation['acting'] === $m[1] && $relation['target'] === $m[2]) {
                    $relation['factor'] = $value;
                    $found = true;
                    break;
                }
            }
            unset($relation);
            if (!$found) {
                $profile['relations'][] = ['acting' => $m[1], 'target' => $m[2], 'factor' => $value];
            }
            return$profile;
        }
        $allowed = '/^(units\.(soldier|spearman|archer|knight)\.(attack|structure|baseAccuracy|accuracySpread|strikesPerAttack|defendingEfficiency|cost|capturable)|combat\.(maxRounds|surrenderEnabled|surrenderDeadPercent|tieBreakCriterion|equalityPolicy|lossCompressionPercent|capturePercent|woundDamageThreshold)|weather\.('.implode('|', EngineProfile::WEATHER).' )\.(soldier|spearman|archer|knight)\.(attack|baseAccuracy))$/';
        $allowed = str_replace(' )', ')', $allowed);
        if (!preg_match($allowed, $path)) {
            throw new \InvalidArgumentException("Chemin de paramètre inconnu : {$path}");
        }
        $parts = explode('.', $path);
        $cursor = &$profile;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                throw new \InvalidArgumentException("Chemin absent du profil : {$path}");
            }
            $cursor = &$cursor[$part];
        }
        $cursor = $value;
        return$profile;
    }

    private static function compositionVariants(array$scenario): array
    {
        $variants = $scenario['compositionVariants'] ?? null;
        if (!is_array($variants) || !array_is_list($variants) || $variants === []) {
            throw new \InvalidArgumentException("compositionVariants manquant dans {$scenario['id']}.");
        }
        $ids = [];
        foreach ($variants as $v) {
            if (!is_array($v) || array_is_list($v) || !is_string($v['id'] ?? null) || isset($ids[$v['id']])) {
                throw new \InvalidArgumentException("Variante de composition invalide dans {$scenario['id']}.");
            }
            $ids[$v['id']] = true;
            self::exactKeys($v, ['id', 'operations'], 'compositionVariant');
            if (!is_array($v['operations'] ?? null) || !array_is_list($v['operations'])) {
                throw new \InvalidArgumentException('operations doit être une liste.');
            }
        }
        return$variants;
    }
    private static function validateArmy(mixed$a, string$path): void
    {
        if (!is_array($a) || array_is_list($a) || !is_string($a['mode'] ?? null)) {
            throw new \InvalidArgumentException("{$path} invalide.");
        }
        if ($a['mode'] === 'explicit') {
            self::exactKeys($a, ['mode', 'units'], $path);
            self::counts($a['units'] ?? null, $path.'.units');
            return;
        }
        if ($a['mode'] === 'budgetShares') {
            self::exactKeys($a, ['mode', 'budget', 'shares', 'fillRemainderWith'], $path);
            if (!is_int($a['budget'] ?? null) || $a['budget'] < 1) {
                throw new \InvalidArgumentException("{$path}.budget invalide.");
            }
            if (!is_array($a['shares'] ?? null) || array_is_list($a['shares'])) {
                throw new \InvalidArgumentException("{$path}.shares invalide.");
            }
            $sum = 0;
            foreach ($a['shares'] as $t => $share) {
                if (!in_array($t, self::TYPES, true) || (!is_int($share) && !is_float($share)) || $share < 0) {
                    $sum = 2;
                } else {
                    $sum += $share;
                }
            }
            if (abs($sum - 1) > 0.000001) {
                throw new \InvalidArgumentException("{$path}.shares doit totaliser 1.");
            }
            $fill = $a['fillRemainderWith'] ?? null;
            if ($fill !== null && !in_array($fill, self::TYPES, true)) {
                throw new \InvalidArgumentException("{$path}.fillRemainderWith invalide.");
            }
            return;
        }
        throw new \InvalidArgumentException("Mode d'armée inconnu : {$a['mode']}");
    }
    private static function counts(mixed$units, string$path): array
    {
        if (!is_array($units) || array_is_list($units) || array_diff(array_keys($units), self::TYPES)) {
            throw new \InvalidArgumentException("{$path} invalide.");
        }
        $out = array_fill_keys(self::TYPES, 0);
        foreach ($units as $t => $n) {
            if (!is_int($n) || $n < 0 || $n > 1000000) {
                throw new \InvalidArgumentException("{$path}.{$t} invalide.");
            }
            $out[$t] = $n;
        }
        if (array_sum($out) < 1) {
            throw new \InvalidArgumentException("{$path} vide.");
        }
        return$out;
    }
    private static function armies(array$scenario, array$variant, array$costs): array
    {
        $out = [];
        foreach (['A', 'B'] as $camp) {
            $out[$camp] = self::buildArmy($scenario['armies'][$camp], $costs);
        }
        foreach ($variant['operations'] as $i => $op) {
            if (!is_array($op) || array_is_list($op) || !in_array($op['camp'] ?? null, ['A', 'B', 'both'], true)) {
                throw new \InvalidArgumentException("Opération {$i} invalide dans {$variant['id']}.");
            }
            $camps = $op['camp'] === 'both' ? ['A', 'B'] : [$op['camp']];
            foreach ($camps as $camp) {
                $out[$camp] = self::transform($out[$camp], $op, $costs);
            }
        }
        return$out;
    }
    private static function buildArmy(array$d, array$costs): array
    {
        if ($d['mode'] === 'explicit') {
            $units = self::counts($d['units'], 'units');
            return self::armyMeta($units, $costs, null, null, 'explicit');
        }
        $budget = $d['budget'];
        $units = array_fill_keys(self::TYPES, 0);
        foreach ($d['shares'] as $t => $share) {
            $units[$t] = (int)floor($budget * $share / $costs[$t]);
        }
        $spent = self::cost($units, $costs);
        $fill = $d['fillRemainderWith'];
        if ($fill !== null) {
            $units[$fill] += intdiv($budget - $spent, $costs[$fill]);
        }
        return self::armyMeta($units, $costs, $budget, $budget - self::cost($units, $costs), 'budgetShares', $d['shares'], $fill);
    }
    private static function transform(array$a, array$op, array$costs): array
    {
        $mode = $op['mode'] ?? null;
        if ($mode === 'scale' || $mode === 'forceRatio') {
            self::exactKeys($op, ['camp', 'mode', 'factor'], 'operation');
            if ((!is_int($op['factor']) && !is_float($op['factor'])) || $op['factor'] <= 0) {
                throw new \InvalidArgumentException('factor invalide.');
            }
            foreach ($a['units'] as $t => $n) {
                $a['units'][$t] = (int)floor($n * $op['factor']);
            }
            return self::armyMeta($a['units'], $costs, null, null, $mode);
        }
        if ($mode === 'add') {
            self::exactKeys($op, ['camp', 'mode', 'unit', 'count'], 'operation');
            if (!in_array($op['unit'] ?? null, self::TYPES, true) || !is_int($op['count'] ?? null) || $op['count'] < 0) {
                throw new \InvalidArgumentException('Ajout invalide.');
            }
            $a['units'][$op['unit']] += $op['count'];
            return self::armyMeta($a['units'], $costs, null, null, 'add');
        }
        if ($mode === 'replaceBudget') {
            self::exactKeys($op, ['camp', 'mode', 'from', 'to', 'count'], 'operation');
            $from = $op['from'] ?? '';
            $to = $op['to'] ?? '';
            $count = $op['count'] ?? null;
            if (!in_array($from, self::TYPES, true) || !in_array($to, self::TYPES, true) || $from === $to || !is_int($count) || $count < 0 || $count > $a['units'][$from]) {
                throw new \InvalidArgumentException('Remplacement invalide.');
            }
            $original = $a['actualBudget'];
            $a['units'][$from] -= $count;
            $released = $count * $costs[$from];
            $a['units'][$to] += intdiv($released, $costs[$to]);
            return self::armyMeta($a['units'], $costs, $original, $released % $costs[$to], 'replaceBudget');
        }
        if ($mode === 'budgetScale') {
            self::exactKeys($op, ['camp', 'mode', 'factor'], 'operation');
            if ($a['targetBudget'] === null || $a['budgetShares'] === null) {
                throw new \InvalidArgumentException('budgetScale requiert une armée budgetShares.');
            }
            $target = (int)floor($a['targetBudget'] * $op['factor']);
            return self::buildArmy(['mode' => 'budgetShares', 'budget' => $target, 'shares' => $a['budgetShares'], 'fillRemainderWith' => $a['fillRemainderWith']], $costs);
        }
        throw new \InvalidArgumentException("Mode de composition inconnu : {$mode}");
    }
    private static function armyMeta(array$units, array$costs, ?int$target, ?int$remainder, string$mode, ?array$shares = null, ?string$fill = null): array
    {
        $actual = self::cost($units, $costs);
        return['mode' => $mode, 'units' => $units, 'targetBudget' => $target, 'actualBudget' => $actual, 'remainder' => $target === null ? ($remainder ?? 0) : $target - $actual, 'budgetShares' => $shares, 'fillRemainderWith' => $fill];
    }
    private static function cost(array$units, array$costs): int
    {
        $sum = 0;
        foreach (self::TYPES as $t) {
            $sum += $units[$t] * $costs[$t];
        }
        return$sum;
    }
    private static function validateModifiers(mixed$v, string$path): void
    {
        if (!is_array($v) || !array_is_list($v)) {
            throw new \InvalidArgumentException("{$path} doit être une liste.");
        }
        $seen = [];
        foreach ($v as $i => $m) {
            if (!is_array($m) || array_is_list($m)) {
                throw new \InvalidArgumentException("{$path}[{$i}] invalide.");
            }
            self::exactKeys($m, ['source', 'id', 'label', 'unitType', 'parameter', 'operation', 'value'], $path."[{$i}]");
            foreach (['source', 'id', 'label'] as $field) {
                if (!is_string($m[$field] ?? null) || trim($m[$field]) === '') {
                    throw new \InvalidArgumentException("{$path}[{$i}].{$field} doit être non vide.");
                }
            }
            $key = $m['source']."\0".$m['id'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("{$path}: effet dupliqué {$m['source']}/{$m['id']}.");
            }
            $seen[$key] = true;
            if (!in_array($m['unitType'] ?? null, self::TYPES, true)) {
                throw new \InvalidArgumentException("{$path}[{$i}].unitType invalide.");
            }
            $parameter = $m['parameter'] ?? null;
            if ($parameter === 'capturable') {
                if (($m['operation'] ?? null) !== 'set' || !is_bool($m['value'] ?? null)) {
                    throw new \InvalidArgumentException("{$path}[{$i}] : capturable requiert set booléen.");
                }
                continue;
            }
            if (!in_array($parameter, ['attack', 'structure', 'cost', 'baseAccuracy', 'accuracySpread', 'strikesPerAttack', 'defendingEfficiency'], true) || ($m['operation'] ?? null) !== 'multiply' || !is_string($m['value'] ?? null)) {
                throw new \InvalidArgumentException("{$path}[{$i}] invalide.");
            }
            try {
                $factor = FixedPoint::parse($m['value']);
            } catch (\Throwable) {
                throw new \InvalidArgumentException("{$path}[{$i}].value décimal invalide.");
            }
            if ($factor < 0 || $factor > 10 * FixedPoint::SCALE) {
                throw new \InvalidArgumentException("{$path}[{$i}].value hors de 0..10.");
            }
            if ($m['source'] === 'weather' && !in_array($parameter, ['attack', 'baseAccuracy'], true)) {
                throw new \InvalidArgumentException("{$path}[{$i}] : la météo ne modifie que attaque ou précision.");
            }
        }
    }
    private static function exactKeys(array$v, array$allowed, string$path): void
    {
        $unknown = array_diff(array_keys($v), $allowed);
        $missing = array_diff($allowed, array_keys($v));
        if ($unknown || $missing) {
            throw new \InvalidArgumentException("{$path}: champs inconnus [".implode(',', $unknown)."] ou manquants [".implode(',', $missing).']');
        }
    }
    private static function jsonFile(string$path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Lecture impossible : {$path}");
        }
        $v = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($v) || array_is_list($v)) {
            throw new \InvalidArgumentException("Objet JSON attendu : {$path}");
        }
        return$v;
    }
    private static function absolute(string$base, string$path): string
    {
        if (preg_match('~^[A-Za-z]:[\\\\/]~', $path) || str_starts_with($path, '/')) {
            return$path;
        }
        return$base.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
