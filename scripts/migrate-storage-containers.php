<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\StorageContainerRepository;
use VonNeumannGame\Service\ProbeStorageService;

require_once __DIR__ . '/../vendor/autoload.php';

$factory = new AppFactory(dirname(__DIR__));
$dbFactory = $factory->databaseFactory();
$pdo = $dbFactory->create();
$dbFactory->initializeSchema($pdo);

$probes = new NeumannProbeRepository($pdo);
$storage = new ProbeStorageService(
    new StorageContainerRepository($pdo),
    new ProbeItemRepository($pdo),
    new MannyRepository($pdo),
    $probes,
);

$ids = $pdo->query('SELECT id FROM neumann_probes ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
$migrated = 0;
foreach ($ids as $id) {
    $probe = $probes->findById((int) $id);
    if ($probe === null) {
        continue;
    }

    $storage->ensureProbeStorage($probe);
    $storage->migrateLegacyProbe($probe);
    $migrated++;
}

echo "Storage containers migrated for {$migrated} probes.\n";
