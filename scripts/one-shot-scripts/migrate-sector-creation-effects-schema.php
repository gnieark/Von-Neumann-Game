<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\Migration\SectorCreationSchemaMigration;

$usage = "Usage: php scripts/one-shot-scripts/migrate-sector-creation-effects-schema.php --database-config=PATH [--apply|--dry-run]\nMaintenance only. Extends sector_effects to accept create_sector; does not migrate sector data. Dry run by default.\n";
$path = null;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=') && $path === null) { $path = substr($argument, 18); }
    elseif ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { $apply = false; }
    elseif ($argument === '--help') { echo $usage; exit(0); }
    else { fwrite(STDERR, $usage); exit(2); }
}
if (!$path) { fwrite(STDERR, $usage); exit(2); }
try {
    $pdo = (new DatabaseConnectionFactory(DatabaseConfig::fromFile($path), dirname(__DIR__, 2)))->create();
    echo json_encode((new SectorCreationSchemaMigration($pdo))->run($apply), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Sector creation schema migration failed.\n" : $error->getMessage() . "\n");
    exit(1);
}
