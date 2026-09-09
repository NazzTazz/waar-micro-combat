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
}
