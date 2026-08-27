<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\PlanetResourceStockCalculator;
use VonNeumannGame\Service\PlanetResourceStockMigration;

require_once __DIR__ . '/../../vendor/autoload.php';

$dryRun = false; $directory = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') { $dryRun = true; }
    elseif (str_starts_with($argument, '--directory=')) { $directory = substr($argument, 12); }
    else { fwrite(STDERR, "Usage: php scripts/one-shot-scripts/add-planet-resource-amounts.php [--dry-run] [--directory=PATH]\n"); exit(2); }
}
$factory = new AppFactory(dirname(__DIR__, 2));
$app = $factory->appConfig();
$directory ??= dirname(__DIR__, 2) . '/' . (string) ($app['universePath'] ?? 'data/universe') . '/sectors';
try {
    $report = (new PlanetResourceStockMigration(new PlanetResourceStockCalculator($factory->universeConfig())))->migrateDirectory($directory, $dryRun);
    echo json_encode($report + ['dryRun' => $dryRun], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
