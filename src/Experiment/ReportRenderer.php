<?php

namespace Waar\MicroCombat\Experiment;

final class ReportRenderer
{
    /** @param array<string, mixed> $report */
    public function markdown(array $report): string
    {
        $experiment = $report['experiment'];
        $lines = [
            '# '.$experiment['label'],
            '',
            sprintf('Témoin `%s` → candidat `%s`. %d itérations appariées par scénario, seed de base %d, %d combats.', $report['baseline']['id'], $report['candidate']['id'], $experiment['iterations'], $experiment['baseSeed'], $experiment['combatCount']),
            '',
            '> Prototype exploratoire : aucun équilibrage ni sélection automatique de paramètres.',
            '',
            '| Scénario | Camp | Victoires | Effectifs | Structure | Valeur |',
            '|---|---|---:|---:|---:|---:|',
        ];
        foreach ($report['rows'] as $row) {
            if (!$row['focus']) {
                continue;
            }
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $row['scenarioLabel'],
                'attacker' === $row['side'] ? 'Attaquant' : 'Défenseur',
                $this->movement($row['vector']['x']),
                $this->movement($row['vector']['y']['survivors']),
                $this->movement($row['vector']['y']['structure']),
                $this->movement($row['vector']['y']['economicValue']),
            );
        }
        $lines[] = '';
        $lines[] = 'Chaque cellule indique `témoin → candidat (écart)` en points de pourcentage. Les nuls restent dans le dénominateur des victoires.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $report */
    public function html(array $report, string $template): string
    {
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return str_replace('__REPORT_JSON__', $json, $template);
    }

    /** @param array{from: ?float, to: ?float, delta: ?float} $coordinate */
    private function movement(array $coordinate): string
    {
        if (null === $coordinate['from'] || null === $coordinate['to'] || null === $coordinate['delta']) {
            return 'n/a';
        }

        return sprintf('%.1f%% → %.1f%% (%+.1f pts)', 100 * $coordinate['from'], 100 * $coordinate['to'], 100 * $coordinate['delta']);
    }
}
