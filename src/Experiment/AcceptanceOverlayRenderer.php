<?php

namespace Waar\MicroCombat\Experiment;

final class AcceptanceOverlayRenderer
{
    /** @param array<string, mixed> $overlay */
    public function html(array $overlay, string $template, string $echartsBundle, string $zonesModel, string $application): string
    {
        if ('' === trim($echartsBundle) || '' === trim($zonesModel) || '' === trim($application)) {
            throw new \InvalidArgumentException('The embedded ECharts bundle and acceptance editor scripts cannot be empty.');
        }
        $json = json_encode($overlay, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return str_replace(
            ['__ECHARTS_BUNDLE__', '__ZONES_MODEL__', '__OVERLAY_APP__', '__OVERLAY_JSON__'],
            [$echartsBundle, $zonesModel, $application, $json],
            $template,
        );
    }

    /** @param array<string, mixed> $overlay */
    public function markdown(array $overlay): string
    {
        return implode("\n", [
            '# '.$overlay['experiment']['label'].' — calque Legacy',
            '',
            sprintf('Témoin `%s` → candidat `%s`, avec référence `%s`.', $overlay['baseline']['id'], $overlay['candidate']['id'], $overlay['legacyReference']['id']),
            '',
            sprintf('%d observations micro, %d repères Legacy et %d tolérances proposées en brouillon.', count($overlay['rows']), count($overlay['rows']), count($overlay['zonesDocument']['zones'])),
            '',
            '> Cahier des charges éditable : les zones restent locales au navigateur jusqu’à leur export explicite.',
            '',
        ]);
    }

    /** @param array<string, mixed> $comparison */
    public function finalistComparisonHtml(array $comparison, string $template, string $echartsBundle, string $model, string $application): string
    {
        if ('' === trim($echartsBundle) || '' === trim($model) || '' === trim($application)) {
            throw new \InvalidArgumentException('The embedded ECharts bundle and finalist comparison scripts cannot be empty.');
        }
        $json = json_encode($comparison, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return str_replace(
            ['__ECHARTS_BUNDLE__', '__FINALIST_MODEL__', '__FINALIST_APP__', '__COMPARISON_JSON__'],
            [$echartsBundle, $model, $application, $json],
            $template,
        );
    }

    /** @param array<string, mixed> $comparison */
    public function finalistComparisonMarkdown(array $comparison): string
    {
        $lines = [
            '# T32 — comparaison visuelle des finalistes',
            '',
            sprintf('Run `%s` : **%s**.', $comparison['run']['planId'], $comparison['run']['statusLabel']),
            '',
            sprintf('Initial `%s` : perte %.12f, %d/%d objectifs, %d nul(s).', $comparison['initial']['id'], $comparison['initial']['summary']['continuousLoss'], $comparison['initial']['summary']['objectivesSatisfied'], $comparison['initial']['summary']['objectiveCount'], $comparison['initial']['summary']['drawCount']),
            '',
            '| Rang | Candidat | Perte | Objectifs | Pire excès | Nuls | Contrôles stricts |',
            '|---:|---|---:|---:|---:|---:|---|',
        ];
        foreach ($comparison['finalists'] as $candidate) {
            $summary = $candidate['summary'];
            $lines[] = sprintf('| %d | `%s` | %.12f | %d/%d | %.12f | %d | %s |', $candidate['rank'], $candidate['id'], $summary['continuousLoss'], $summary['objectivesSatisfied'], $summary['objectiveCount'], $summary['worstExcess'], $summary['drawCount'], $summary['strictControls']['passed'] ? 'réussis' : 'non satisfaits');
        }
        $lines[] = '';
        $lines[] = '> Ce rapport présente les mesures T31. Il ne qualifie jamais un candidat d’accepté sur la seule base de sa perte moyenne.';
        $lines[] = '';
        $lines[] = $comparison['objectiveDocument']['changeInstruction'];
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $presentation */
    public function finalistStabilityMarkdown(array $presentation, array $result): string
    {
        $initial = $presentation['initial'];
        $lines = [
            '# T33 — stabilité sur les simulations réservées',
            '',
            sprintf('Plan `%s` : **%s**.', $presentation['run']['planId'], $presentation['run']['statusLabel']),
            '',
            sprintf('%d lots de %d répétitions par scénario et candidat, seeds `%s`.', $result['measurement']['batchCount'], $result['measurement']['iterationsPerScenarioAndCandidate'], implode('`, `', $result['measurement']['baseSeeds'])),
            '',
            sprintf('Initial `%s` : statut `%s`, perte agrégée %.12f, %d/%d objectifs, %d nul(s).', $initial['id'], $initial['stabilityStatus'], $initial['summary']['continuousLoss'], $initial['summary']['objectivesSatisfied'], $initial['summary']['objectiveCount'], $initial['summary']['drawCount']),
            '',
            '| Ordre T31 | Candidat | Statut | Perte agrégée | Objectifs agrégés | Pire excès | Nuls | Lots stricts |',
            '|---:|---|---|---:|---:|---:|---:|---:|',
        ];
        foreach ($presentation['finalists'] as $candidate) {
            $strictBatches = count(array_filter($candidate['batchSummaries'], static fn (array $batch): bool => true === $batch['strictControls']['passed']));
            $summary = $candidate['summary'];
            $lines[] = sprintf('| %d | `%s` | `%s` | %.12f | %d/%d | %.12f | %d | %d/%d |', $candidate['rank'], $candidate['id'], $candidate['stabilityStatus'], $summary['continuousLoss'], $summary['objectivesSatisfied'], $summary['objectiveCount'], $summary['worstExcess'], $summary['drawCount'], $strictBatches, count($candidate['batchSummaries']));
        }
        $lines[] = '';
        $lines[] = '## Résultats par lot';
        $lines[] = '';
        foreach ([$presentation['initial'], ...$presentation['finalists']] as $candidate) {
            $lines[] = sprintf('### `%s` — `%s`', $candidate['id'], $candidate['stabilityStatus']);
            $lines[] = '';
            $lines[] = '| Lot | Seed | Perte | Objectifs | Pire excès | Nuls | Contrôles stricts |';
            $lines[] = '|---:|---:|---:|---:|---:|---:|---|';
            foreach ($candidate['batchSummaries'] as $batch) {
                $lines[] = sprintf('| %d | %d | %.12f | %d/%d | %.12f | %d | %s |', $batch['batch'], $batch['baseSeed'], $batch['continuousLoss'], $batch['objectivesSatisfied'], $batch['objectiveCount'], $batch['worstExcess'], $batch['drawCount'], $batch['strictControls']['passed'] ? 'réussis' : 'non satisfaits');
            }
            $lines[] = '';
        }
        $lines[] = sprintf('Combats réellement exécutés : **%s** candidats + témoin mis en cache ; coût logique des rapports : %s.', number_format($result['execution']['actualCombatCount'], 0, ',', ' '), number_format($result['execution']['logicalReportCombatCount'], 0, ',', ' '));
        $lines[] = '';
        $lines[] = '> « Variation entre lots » désigne uniquement l’étendue observée et le nombre de lots satisfaisants. Ce n’est pas un intervalle de confiance ni une garantie de combats futurs.';
        $lines[] = '';
        $lines[] = 'Le PO peut retenir un candidat dans cet ordre T31 ou demander une nouvelle expérience. Une reprise de recherche doit réserver un nouveau plan de validation.';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
