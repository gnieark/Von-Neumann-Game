<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Service\AsteroidTrajectory\SectorAsteroidFormatMigration;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
$universePath = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--universe-path=')) {
        $universePath = substr($argument, strlen('--universe-path='));
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$root = dirname(__DIR__, 2);
$factory = new AppFactory($root);
$appConfig = $factory->appConfig();
$configuredUniversePath = $universePath ?? (string) ($appConfig['universePath'] ?? 'data/universe');
if (!str_starts_with($configuredUniversePath, DIRECTORY_SEPARATOR)) {
    $configuredUniversePath = $root . DIRECTORY_SEPARATOR . $configuredUniversePath;
}

try {
    $report = (new SectorAsteroidFormatMigration())->migrate($configuredUniversePath, $dryRun);
    if (!$dryRun) {
        $factory->pdo($databaseConfig, initializeSchema: true);
    }
    printf(
        "Asteroid trajectory migration complete: files_scanned=%d files_changed=%d asteroids_changed=%d dry_run=%s\n",
        $report['filesScanned'],
        $report['filesChanged'],
        $report['asteroidsChanged'],
        $dryRun ? 'yes' : 'no',
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Asteroid trajectory migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
