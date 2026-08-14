<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$databaseConfig = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/force-pending-scheduled-events-now.php [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$pdo = (new AppFactory(dirname(__DIR__)))->pdo($databaseConfig, initializeSchema: false);
$now = gmdate('c');

$statement = $pdo->prepare(
    "UPDATE scheduled_events
     SET run_at = :run_at, updated_at = :updated_at
     WHERE status = 'pending'"
);
$statement->execute([
    'run_at' => $now,
    'updated_at' => $now,
]);

echo sprintf("Pending scheduled events moved to %s: %d\n", $now, $statement->rowCount());
