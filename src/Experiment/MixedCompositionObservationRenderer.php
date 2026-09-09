<?php

namespace Waar\MicroCombat\Experiment;

final class MixedCompositionObservationRenderer
{
    /** @param array<string, mixed> $presentation */
    public function html(array $presentation, string $template, string $echartsBundle, string $model, string $application): string
    {
        if ('' === trim($template) || '' === trim($echartsBundle) || '' === trim($model) || '' === trim($application)) {
            throw new \InvalidArgumentException('T34 presentation resources cannot be empty.');
        }
        $json = json_encode($presentation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return str_replace(
            ['__ECHARTS_BUNDLE__', '__MIXED_MODEL__', '__MIXED_APP__', '__OBSERVATION_JSON__'],
            [$echartsBundle, $model, $application, $json],
            $template,
        );
    }

    /** @param array<string, mixed> $presentation */
    public function markdown(array $presentation): string
    {
        $sampling = $presentation['sampling'];
        $lines = [
            '# T34 — observation des compositions mixtes T24',
            '',
            sprintf('Plan `%s` : %d répétitions par scénario et variante, seed réservée `%d`, %d combats réels.', $presentation['run']['planId'], $sampling['iterationsPerScenarioAndVariant'], $sampling['baseSeed'], $sampling['scenarioCount'] * $sampling['iterationsPerScenarioAndVariant'] * $sampling['variantCount']),
            '',
            sprintf('Initial `%s` — statut T33 `%s`.', $presentation['initial']['id'], $presentation['initial']['t33Status']),
            '',
            '| Ordre T31 | Finaliste | Statut T33 | Renversements de majorité T24 |',
            '|---:|---|---|---:|',
        ];
        foreach ($presentation['finalists'] as $candidate) {
            $reversalCount = count(array_filter($presentation['observations']['majorityWinnerReversals'], static fn (array $entry): bool => $candidate['id'] === $entry['candidateId']));
            $lines[] = sprintf('| %d | `%s` | `%s` | %d |', $candidate['t31Order'], $candidate['id'], $candidate['t33Status'], $reversalCount);
        }
        $lines[] = '';
        $lines[] = '## Renversements de majorité observés';
        $lines[] = '';
        if ([] === $presentation['observations']['majorityWinnerReversals']) {
            $lines[] = 'Aucun sur cet échantillon.';
        } else {
            foreach ($presentation['observations']['majorityWinnerReversals'] as $entry) {
                $lines[] = sprintf('- `%s`, %s : `%s` → `%s`.', $entry['candidateId'], $entry['scenarioLabel'], $entry['from'], $entry['to']);
            }
        }
        $lines[] = '';
        $lines[] = '## Plus grands écarts absolus';
        $lines[] = '';
        $lines[] = '| Mesure | Finaliste | Scénario | Camp | Initial | Finaliste | Delta |';
        $lines[] = '|---|---|---|---|---:|---:|---:|';
        foreach ($presentation['observations']['largestAbsoluteGaps'] as $gap) {
            $lines[] = sprintf('| `%s` | `%s` | %s | `%s` | %.6f | %.6f | %+.6f |', $gap['metric'], $gap['candidateId'], $gap['scenarioLabel'], $gap['side'], $gap['from'], $gap['to'], $gap['delta']);
        }
        $lines[] = '';
        $lines[] = '> Lecture descriptive : aucun score d’acceptation T24, aucun verdict de fidélité Legacy et aucune nouvelle sélection. Les statuts T33 restent applicables, y compris l’échec des objectifs monotypes.';
        $lines[] = '';
        $lines[] = sprintf('Référence T24 historique : %d répétitions, seed `%d`, départage `%s` implicite. Ses mesures ne sont pas affichées et les différences de départage ne sont pas attribuées aux paramètres.', $presentation['historicalReference']['iterations'], $presentation['historicalReference']['baseSeed'], $presentation['historicalReference']['tieBreakPolicy']);
        $lines[] = '';

        return implode("\n", $lines);
    }
}
