<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Database\SchemaInitializer;

$usage = "Usage: php scripts/one-shot-scripts/migrate-scheduler-mining-conflicts.php --database-config=PATH [--dry-run|--apply]\nAdds consecutive mining conflict counters. Stop API writers and scheduler workers before applying. Dry run opens no connection.\n";
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
    $columns = (new SchemaInitializer($config->driver))->schedulerConflictColumns();
    if (!$apply) {
        echo "Dry run; no connection opened. Missing columns will be added; existing counters and events are preserved.\n";
        foreach ($columns as $name => $definition) { echo "ALTER TABLE scheduled_events ADD COLUMN $name $definition;\n"; }
        exit(0);
    }
    $pdo = (new DatabaseConnectionFactory($config, dirname(__DIR__, 2)))->create();
    $existing = $config->driver === 'sqlite'
        ? array_column($pdo->query('PRAGMA table_info(scheduled_events)')->fetchAll(PDO::FETCH_ASSOC), 'name')
        : $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='scheduled_events'")->fetchAll(PDO::FETCH_COLUMN);
    if ($existing === []) { throw new RuntimeException('scheduled_events is missing; initialize the database first.'); }
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing, true)) { $pdo->exec("ALTER TABLE scheduled_events ADD COLUMN $name $definition"); }
    }
    echo "Mining conflict counters initialized. No events requeued; no stocks or sectors modified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Scheduler conflict migration failed.\n" : $error->getMessage() . "\n");
    exit(1);
}
