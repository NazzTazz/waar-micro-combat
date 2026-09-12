<?php

namespace Waar\MicroCombat\Experiment;

/** Descriptive projection of archived observations; no combat or objective evaluation. */
final class CandidateLegacyComparisonBuilder
{
    public const CANDIDATE_ID = 't31-candidate-0116-d0c5f6473d05';

    public function build(array $legacy, array $result, array $plan): array
    {
        $matches = array_values(array_filter($result['finalists'] ?? [], static fn (array $v): bool => self::CANDIDATE_ID === ($v['id'] ?? null)));
        if (1 !== count($matches)) {
            throw new \InvalidArgumentException('Expected exactly one candidate 116.');
        }
        $candidate = $matches[0];
        $scenarios = $plan['corpus']['scenarios'] ?? [];
        if (6 !== count($scenarios)) {
            throw new \InvalidArgumentException('Expected six T24 scenarios.');
        }
        $valuation = ['soldier' => 80, 'spearman' => 110, 'archer' => 130, 'knight' => 350];
        if (($legacy['comparisonProfile']['valuation'] ?? null) !== $valuation) {
            throw new \InvalidArgumentException('Unexpected Legacy valuation.');
        }
        $index = static function (array $rows): array {
            $indexed = [];
            foreach ($rows as $row) {
                $key = $row['scenarioId'].'/'.$row['side'];
                if (isset($indexed[$key])) {
                    throw new \InvalidArgumentException('Duplicate observation: '.$key);
                }
                $indexed[$key] = $row;
            }
            if (12 !== count($indexed)) {
                throw new \InvalidArgumentException('Expected twelve observations.');
            }
            return $indexed;
        };
        $legacyRows = $index($legacy['rows']);
        $microRows = $index($candidate['rows']);
        $legacyScenarios = [];
        foreach ($legacy['corpus']['scenarios'] as $scenario) {
            if (isset($legacyScenarios[$scenario['id']])) {
                throw new \InvalidArgumentException('Duplicate Legacy scenario.');
            }
            $legacyScenarios[$scenario['id']] = $scenario;
        }
        $seen = [];
        $rows = [];
        foreach ($scenarios as $scenario) {
            if (isset($seen[$scenario['id']])) {
                throw new \InvalidArgumentException('Duplicate T24 scenario.');
            }
            $seen[$scenario['id']] = true;
            foreach (['attacker', 'defender'] as $side) {
                $key = $scenario['id'].'/'.$side;
                $l = $legacyRows[$key] ?? throw new \InvalidArgumentException('Missing Legacy row: '.$key);
                $m = $microRows[$key] ?? throw new \InvalidArgumentException('Missing micro row: '.$key);
                if (($legacyScenarios[$scenario['id']][$side] ?? null) !== $scenario[$side] || $l['initial']['byType'] !== $scenario[$side] || $m['army'] !== $scenario[$side]) {
                    throw new \InvalidArgumentException('Composition mismatch: '.$key);
                }
                $metrics = [];
                foreach (['survivors' => 'operationalSurvivorsRatio', 'economicValue' => 'operationalEconomicValueRatio'] as $microMetric => $legacyMetric) {
                    $ly = self::ratio($l['metrics'][$legacyMetric]['value']);
                    $my = self::ratio($m['metrics'][$microMetric]);
                    $lx = 100 * self::ratio($l['winRate']['value']);
                    $mx = 100 * self::ratio($m['winRate']);
                    $dilated = 100 * (1 - 20 * (1 - $ly));
                    if ($dilated < -40 || $dilated > 100) {
                        throw new \InvalidArgumentException('Dilated observation outside fixed axis: '.$key);
                    }
                    $metrics[$microMetric] = [
                        'raw' => ['legacy' => [$lx, 100 * $ly], 'candidate' => [$mx, 100 * $my]],
                        'dilated' => ['legacy' => [$lx, $dilated], 'candidate' => [$mx, 100 * $my]],
                    ];
                }
                $rows[] = ['key' => $key, 'scenarioId' => $scenario['id'], 'label' => $scenario['label'], 'side' => $side, 'metrics' => $metrics, 'deltaPercentagePoints' => ['winRate' => $metrics['survivors']['raw']['candidate'][0] - $metrics['survivors']['raw']['legacy'][0], 'survivors' => $metrics['survivors']['raw']['candidate'][1] - $metrics['survivors']['raw']['legacy'][1], 'survivorsDilated' => $metrics['survivors']['dilated']['candidate'][1] - $metrics['survivors']['dilated']['legacy'][1]], 'repetitions' => ['legacy' => $l['repetitions'], 'candidate' => $m['iterations']]];
            }
        }
        return ['schemaVersion' => 'waar-candidate-legacy-comparison/0.1', 'candidateId' => self::CANDIDATE_ID, 'factor' => 20, 'axes' => ['x' => [0, 100], 'raw' => [0, 100], 'dilated' => [-40, 100]], 'rows' => $rows, 'scenarioNotes' => $this->describeScenarios($scenarios, $rows), 'globalConformity' => $this->summarize($rows), 'sampling' => ['legacy' => $legacy['sampling'], 'candidate' => $plan['sampling']], 'candidateStatus' => $candidate['t33Status']];
    }

    private function describeScenarios(array $scenarios, array $rows): array
    {
        $indexed = array_column($rows, null, 'key');
        $notes = [];
        $format = static fn(float $value): string => rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
        $composition = static function (array $army) use ($format): string {
            $parts = [];
            foreach (['soldier' => 'de soldats', 'spearman' => 'de lanciers', 'archer' => 'd’archers', 'knight' => 'de chevaliers'] as $unit => $label) {
                if ($army[$unit] > 0) {
                    $parts[] = $format(100 * $army[$unit] / array_sum($army)).' % '.$label.' ('.$army[$unit].')';
                }
            }
            return implode(', ', $parts);
        };
        foreach ($scenarios as $scenario) {
            $attacker = $indexed[$scenario['id'].'/attacker']['metrics']['survivors']['raw'];
            $defender = $indexed[$scenario['id'].'/defender']['metrics']['survivors'];
            $winner = static fn(string $engine): ?string => $attacker[$engine][0] > 50 ? 'attaquant' : ($defender['raw'][$engine][0] > 50 ? 'défenseur' : null);
            $legacyWinner = $winner('legacy');
            $candidateWinner = $winner('candidate');
            $outcome = null === $legacyWinner || null === $candidateWinner
                ? 'la comparaison d’issue majoritaire est indéterminée (au moins un moteur sans camp au-dessus de 50 % de victoires)'
                : ($legacyWinner === $candidateWinner
                    ? 'l’issue majoritaire reste la même : victoire du camp '.$candidateWinner
                    : 'l’issue majoritaire change : victoire du camp '.$legacyWinner.' avec le Legacy, puis du camp '.$candidateWinner.' avec le candidat 116');
            $legacyLoss = 100 - $defender['dilated']['legacy'][1];
            $candidateLoss = 100 - $defender['raw']['candidate'][1];
            $delta = $candidateLoss - $legacyLoss;
            $relative = $legacyLoss > 1e-12 ? 100 * $delta / $legacyLoss : null;
            if (abs($delta) < 1e-10) {
                $change = 'les pertes du défenseur restent identiques';
            } elseif (null === $relative) {
                $change = 'les pertes du défenseur augmentent de '.$format($delta).' points d’indice ; la variation relative est non définie car la référence est nulle';
            } else {
                $change = 'les pertes du défenseur sont '.($delta > 0 ? 'augmentées' : 'réduites').' de '.$format(abs($relative)).' % par rapport au Legacy dilaté ×20';
            }
            $text = 'Sur une armée attaquante constituée de '.$composition($scenario['attacker']).' face à une armée défensive constituée de '.$composition($scenario['defender']).', '.$outcome.', et '.$change.'. Les pertes passent de '.$format($legacyLoss).' à '.$format($candidateLoss).' sur l’échelle de pertes comparée (écart : '.($delta > 0 ? '+' : '').$format($delta).' points d’indice).';
            $notes[] = ['scenarioId' => $scenario['id'], 'label' => $scenario['label'], 'text' => $text, 'defenderLosses' => ['legacyDilated' => $legacyLoss, 'candidate' => $candidateLoss, 'deltaIndexPoints' => $delta, 'relativeChangePercent' => $relative], 'budgets' => $scenario['budgets']];
        }
        return $notes;
    }

    /** Equal-weight descriptive scores; they do not evaluate PO objectives. */
    private function summarize(array $rows): array
    {
        $pairs = [];
        $winError = 0.0;
        $survivorErrors = ['raw' => 0.0, 'dilated' => 0.0];
        foreach ($rows as $row) {
            $points = $row['metrics']['survivors'];
            $pairs[$row['scenarioId']][$row['side']] = $points['raw'];
            $winError += abs($points['raw']['candidate'][0] - $points['raw']['legacy'][0]);
            foreach ($survivorErrors as $view => $unused) {
                $survivorErrors[$view] += abs($points[$view]['candidate'][1] - $points[$view]['legacy'][1]);
            }
        }
        $sameWinner = 0;
        $withoutMajority = 0;
        foreach ($pairs as $pair) {
            $winners = [];
            foreach (['legacy', 'candidate'] as $engine) {
                $winners[$engine] = null;
                foreach (['attacker', 'defender'] as $side) {
                    if ($pair[$side][$engine][0] > 50) {
                        $winners[$engine] = $side;
                    }
                }
            }
            if (null === $winners['candidate'] || null === $winners['legacy']) {
                ++$withoutMajority;
            } elseif ($winners['candidate'] === $winners['legacy']) {
                ++$sameWinner;
            }
        }
        $score = static function (float $error) use ($rows): array {
            $mean = $error / count($rows);
            return ['score' => max(0.0, 100.0 - $mean), 'meanAbsoluteErrorPoints' => $mean];
        };
        return [
            'method' => 'equal-weight-mae-v1',
            'scope' => 'all-six-T24-scenarios-both-sides',
            'winner' => ['score' => 100.0 * $sameWinner / count($pairs), 'matchingScenarios' => $sameWinner, 'totalScenarios' => count($pairs), 'withoutMajority' => $withoutMajority],
            'winRate' => $score($winError),
            'survivors' => ['raw' => $score($survivorErrors['raw']), 'dilated' => $score($survivorErrors['dilated'])],
        ];
    }

    private static function ratio(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1) {
            throw new \InvalidArgumentException('Expected a finite ratio in [0, 1].');
        }
        return (float) $value;
    }
}
