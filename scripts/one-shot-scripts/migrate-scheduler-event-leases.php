<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$databaseConfig = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/migrate-scheduler-event-leases.php [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$factory = new AppFactory(dirname(__DIR__));
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'sqlite') {
    $columns = $pdo->query('PRAGMA table_info(scheduled_events)')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($columns, 'name');
    if (!in_array('locked_by', $names, true)) {
        $pdo->exec('ALTER TABLE scheduled_events ADD COLUMN locked_by TEXT NULL');
    }
} else {
    $column = $pdo->query("SHOW COLUMNS FROM scheduled_events WHERE Field = 'locked_by'")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($column)) {
        $pdo->exec('ALTER TABLE scheduled_events ADD COLUMN locked_by VARCHAR(255) NULL AFTER locked_at');
    }
}

echo "Scheduler event lease schema is ready.\n";
