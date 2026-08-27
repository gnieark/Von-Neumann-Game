<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
        continue;
    }
    if ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-others-alerts.php [--database-config=PATH]\n";
        exit(0);
    }

    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

try {
    $factory = new AppFactory(dirname(__DIR__, 2));
    $pdo = $factory->pdo($databaseConfig, initializeSchema: false);
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['sqlite', 'mysql'], true)) {
        throw new RuntimeException("Unsupported database driver: {$driver}");
    }

    $created = !othersAlertsTableExists($pdo, $driver);
    if ($created) {
        $id = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
        $nullableText = $driver === 'mysql' ? 'VARCHAR(255) NULL' : 'TEXT NULL';
        $caseSensitiveText = $driver === 'mysql' ? 'VARCHAR(255) COLLATE utf8mb4_bin' : 'TEXT';
        $pdo->exec(
            "CREATE TABLE others_alerts (
                id {$id},
                public_id {$caseSensitiveText} NOT NULL UNIQUE,
                player_id INTEGER NOT NULL,
                ship_public_id {$caseSensitiveText} NOT NULL,
                type {$text} NOT NULL,
                status {$text} NOT NULL,
                phase {$text} NOT NULL,
                event_key {$caseSensitiveText} NOT NULL,
                message TEXT NOT NULL,
                created_at {$text} NOT NULL,
                updated_at {$text} NOT NULL,
                read_at {$nullableText},
                UNIQUE(player_id, event_key),
                FOREIGN KEY(player_id) REFERENCES players(id)
            )"
        );
        $pdo->exec('CREATE INDEX idx_others_alerts_player_status ON others_alerts(player_id, status, created_at)');
        $pdo->exec('CREATE INDEX idx_others_alerts_ship_status ON others_alerts(ship_public_id, status, created_at)');
    }

    $requiredColumns = ['id', 'public_id', 'player_id', 'ship_public_id', 'type', 'status', 'phase', 'event_key', 'message', 'created_at', 'updated_at', 'read_at'];
    $missingColumns = array_values(array_diff($requiredColumns, othersAlertsColumns($pdo, $driver)));
    if ($missingColumns !== []) {
        throw new RuntimeException('Existing others_alerts table is not canonical; missing columns: ' . implode(', ', $missingColumns));
    }

    printf("Others alerts schema ready: table_created=%s\n", $created ? 'yes' : 'no');
} catch (Throwable $error) {
    fwrite(STDERR, 'Others alerts migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}

function othersAlertsTableExists(PDO $pdo, string $driver): bool
{
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table_name");
        $stmt->execute(['table_name' => 'others_alerts']);

        return (int) $stmt->fetchColumn() > 0;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name');
    $stmt->execute(['table_name' => 'others_alerts']);

    return (int) $stmt->fetchColumn() > 0;
}

/** @return list<string> */
function othersAlertsColumns(PDO $pdo, string $driver): array
{
    if ($driver === 'sqlite') {
        return array_column($pdo->query('PRAGMA table_info(others_alerts)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    return array_column($pdo->query('SHOW COLUMNS FROM others_alerts')->fetchAll(PDO::FETCH_ASSOC), 'Field');
}
