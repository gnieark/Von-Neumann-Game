<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Database\SchemaInitializer;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--dry-run') {
        $apply = false;
    } elseif ($argument === '--help') {
        echo "Usage: php scripts/one-shot-scripts/migrate-detached-container-mannies.php [--database-config=PATH] [--apply|--dry-run]\nStop workers and API writes before applying. No existing Manny is relocated.\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}
$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$schema = new SchemaInitializer((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
foreach ($schema->detachedMannyStatements() as $statement) {
    if ($apply) { $pdo->exec($statement); }
}
echo $apply
    ? "Detached-container Manny storage and inspection tables are ready. Existing Mannies were not changed.\n"
    : "Dry run: create detached-container Manny storage and inspection tables if absent; no existing data changes.\n";
