<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\SchemaInitializer;

$usage = "Usage: php scripts/one-shot-scripts/migrate-mining-admission-locks.php --database-config=PATH [--apply|--dry-run]\nCreates per-object mining admission locks. Apply under maintenance before starting the new code. Existing tasks and resources are preserved, including objects above the limit. Dry run by default, without a connection.\n";
$path = null;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=') && $path === null) { $path = substr($argument, 18); }
    elseif ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { $apply = false; }
    elseif ($argument === '--help') { echo $usage; exit(0); }
    else { fwrite(STDERR, $usage); exit(2); }
}
if ($path === null || $path === '') { fwrite(STDERR, $usage); exit(2); }
try {
    $config = DatabaseConfig::fromFile($path);
    $statements = (new SchemaInitializer($config->driver))->miningAdmissionLockStatements();
    if (!$apply) { echo "Dry run; no connection opened.\n", implode(";\n", $statements), ";\n"; exit(0); }
    $pdo = (new DatabaseConnectionFactory($config, dirname(__DIR__, 2)))->create();
    foreach ($statements as $statement) { $pdo->exec($statement); }
    echo "Mining admission lock schema created; existing assignments and resources unchanged.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Mining admission lock migration failed.\n" : $error->getMessage() . "\n");
    exit(1);
}
