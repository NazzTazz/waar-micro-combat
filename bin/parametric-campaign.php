<?php

declare(strict_types=1);

use Waar\MicroCombat\Exploration\ParametricCampaign;
use Waar\MicroCombat\Exploration\ParametricCampaignExporter;
use Waar\MicroCombat\Exploration\ParametricCampaignRunner;

require dirname(__DIR__).'/autoload.php';

$command = $argv[1] ?? '';
$planPath = $argv[2] ?? '';
try {
    if (!in_array($command, ['preview', 'run', 'resume', 'export'], true) || $planPath === '') {
        throw new InvalidArgumentException('Usage: php bin/parametric-campaign.php preview|run|resume|export plan.json [--stop-after-lots=N]');
    }
    $loaded = ParametricCampaign::load($planPath);
    if ($command === 'preview') {
        $result = ParametricCampaign::preview($loaded['plan'], $loaded['profile']);
    } elseif ($command === 'export') {
        $result = ParametricCampaignExporter::export($loaded['outputPath']);
    } else {
        $stop = null;
        foreach (array_slice($argv, 3) as $arg) {
            if (str_starts_with($arg, '--stop-after-lots=')) {
                $stop = (int)substr($arg, 18);
            }
        }
        $result = (new ParametricCampaignRunner())->run($loaded, $stop);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),"\n";
} catch (Throwable$error) {
    fwrite(STDERR, 'Erreur: '.$error->getMessage()."\n");
    exit(1);
}
