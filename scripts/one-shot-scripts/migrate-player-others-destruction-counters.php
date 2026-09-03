<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-player-others-destruction-counters.php [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$factory = new AppFactory(dirname(__DIR__, 2));
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$columns = $driver === 'sqlite'
    ? array_column($pdo->query('PRAGMA table_info(players)')->fetchAll(PDO::FETCH_ASSOC), 'name')
    : array_column($pdo->query('SHOW COLUMNS FROM players')->fetchAll(PDO::FETCH_ASSOC), 'Field');

if (!in_array('others_ships_destroyed', $columns, true)) {
    $pdo->exec('ALTER TABLE players ADD COLUMN others_ships_destroyed INTEGER NOT NULL DEFAULT 0');
}
if (!in_array('others_motherships_destroyed', $columns, true)) {
    $pdo->exec('ALTER TABLE players ADD COLUMN others_motherships_destroyed INTEGER NOT NULL DEFAULT 0');
}

echo "Player Others destruction counters are ready; existing counters start at zero.\n";
