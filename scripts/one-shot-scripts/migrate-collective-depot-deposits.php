<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\AppFactory;
use VonNeumannGame\Database\Migration\CollectiveDepotSchemaMigration;

$usage = "Usage: php scripts/one-shot-scripts/migrate-collective-depot-deposits.php [--database-config=PATH] [--apply|--dry-run]\nMaintenance only. Extends sector_storage_transfers to accept others_ship; preserves all transfers and reservations. Stop writers before applying. Applies by default using the project's config/database.json. Use --dry-run to inspect without changes.\n";
$path = null;
$apply = true;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=') && $path === null) { $path = substr($argument, 18); }
    elseif ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { $apply = false; }
    elseif ($argument === '--help') { echo $usage; exit(0); }
    else { fwrite(STDERR, $usage); exit(2); }
}
if ($path === '') { fwrite(STDERR, $usage); exit(2); }
try {
    $pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($path, initializeSchema: false);
    echo json_encode((new CollectiveDepotSchemaMigration($pdo))->run($apply), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Collective depot schema migration failed.\n" : $error->getMessage() . "\n");
    exit(1);
}
