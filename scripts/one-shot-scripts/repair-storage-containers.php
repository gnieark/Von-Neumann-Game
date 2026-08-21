<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\StorageContainerRepository;
use VonNeumannGame\Service\ProbeStorageService;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/repair-storage-containers.php [--database-config=PATH]\n";
        echo "The application and scheduler must be stopped while this repair runs.\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

try {
    $root = dirname(__DIR__, 2);
    $factory = new AppFactory($root);
    $pdo = $factory->pdo($databaseConfig, initializeSchema: false);
    $gameplayConfig = $factory->gameplayConfig();
    $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
    $storage = new ProbeStorageService(
        new StorageContainerRepository($pdo, $gameplayConfig),
        new ProbeItemRepository($pdo),
        new MannyRepository($pdo, $gameplayConfig),
        $probes,
        $gameplayConfig,
    );

    $probeIds = $pdo->query('SELECT id FROM neumann_probes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $repaired = 0;
    foreach ($probeIds as $probeId) {
        $pdo->beginTransaction();
        try {
            $probe = $probes->findById((int) $probeId);
            if ($probe !== null) {
                $storage->repairProbeStorage($probe);
                $repaired++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    printf("Storage containers repaired for %d probes.\n", $repaired);
} catch (Throwable $error) {
    fwrite(STDERR, 'Storage-container repair failed: ' . $error->getMessage() . "\n");
    exit(1);
}
