<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\SchemaInitializer;

$usage = "Usage: php scripts/one-shot-scripts/migrate-mineable-object-stock-schema.php --database-config=PATH [--apply|--dry-run]\nCreates only the identity and stock tables. Does not import data or authorize a storage cutover. Dry run by default.\n";
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
    if (!in_array($config->driver, ['sqlite', 'mysql'], true)) { throw new InvalidArgumentException('Unsupported database driver.'); }
    $statements = (new SchemaInitializer($config->driver))->mineableObjectStockStatements();
    if (!$apply) { echo "Dry run; no connection opened.\n", implode(";\n", $statements), ";\n"; exit(0); }
    $pdo = (new DatabaseConnectionFactory($config, dirname(__DIR__, 2)))->create();
    foreach ($statements as $statement) { $pdo->exec($statement); }
    echo "Mineable stock schema created; no resources imported or sector files modified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Mineable stock schema migration failed.\n" : $error->getMessage() . "\n");
    exit(1);
}
