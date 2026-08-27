<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-others-schema.php [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$factory = new AppFactory(dirname(__DIR__, 2));
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    $columns = array_column($pdo->query('PRAGMA table_info(players)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('can_control_others', $columns, true)) {
        $pdo->exec('ALTER TABLE players ADD COLUMN can_control_others INTEGER NOT NULL DEFAULT 0');
    }
} else {
    $stmt = $pdo->query("SHOW COLUMNS FROM players WHERE Field = 'can_control_others'");
    if (!is_array($stmt->fetch(PDO::FETCH_ASSOC))) {
        $pdo->exec('ALTER TABLE players ADD COLUMN can_control_others BOOLEAN NOT NULL DEFAULT FALSE AFTER forum_moderator');
    }
}
$factory->databaseFactory($databaseConfig)->initializeSchema($pdo);
echo "Others canonical SQL schema is ready; every existing account remains unauthorized.\n";
