<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

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
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-player-last-game-action.php [--database-config=PATH] [--apply|--dry-run]\n";
        echo "Dry run by default. Existing players start with NULL (no observed action); no history is reconstructed.\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

try {
    $pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['sqlite', 'mysql'], true)) {
        throw new RuntimeException("Unsupported database driver: {$driver}");
    }
    $columns = $driver === 'sqlite'
        ? array_column($pdo->query('PRAGMA table_info(players)')->fetchAll(PDO::FETCH_ASSOC), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM players')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if ($columns === []) {
        throw new RuntimeException('The players table must exist before this migration.');
    }
    if (in_array('last_game_action_at', $columns, true)) {
        echo "players.last_game_action_at already exists; recorded dates are unchanged.\n";
        exit(0);
    }
    if (!$apply) {
        echo "Dry run: add nullable players.last_game_action_at; existing players will have NULL.\n";
        exit(0);
    }

    $type = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
    $pdo->exec("ALTER TABLE players ADD COLUMN last_game_action_at {$type} NULL");
    echo "Added players.last_game_action_at; existing players have NULL until their first successful authenticated write request.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Player activity migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
