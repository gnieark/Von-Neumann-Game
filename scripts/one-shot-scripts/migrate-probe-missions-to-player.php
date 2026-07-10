<?php

declare(strict_types=1);

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(migrateProbeMissionsToPlayerRun($argv));
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . migrateProbeMissionsToPlayerUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to migrate probe missions to player ownership: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function migrateProbeMissionsToPlayerRun(array $argv): int
{
    $options = migrateProbeMissionsToPlayerParseArguments($argv);
    if ($options['help']) {
        echo migrateProbeMissionsToPlayerUsage();

        return 0;
    }

    $root = dirname(__DIR__);
    $configPath = migrateProbeMissionsToPlayerAbsolutePath($root, $options['databaseConfig'] ?? 'config/database.json');
    $config = DatabaseConfig::fromFile($configPath);
    $pdo = (new DatabaseConnectionFactory($config, $root))->create();

    migrateProbeMissionsToPlayerEnsureTable($pdo, $config->driver);
    $hasProbeId = migrateProbeMissionsToPlayerColumnExists($pdo, $config->driver, 'probe_missions', 'probe_id');
    $hasPlayerId = migrateProbeMissionsToPlayerColumnExists($pdo, $config->driver, 'probe_missions', 'player_id');
    if (!$hasProbeId && $hasPlayerId) {
        echo "Probe missions already use player_id; no migration needed.\n";

        return 0;
    }
    if (!$hasProbeId) {
        throw new RuntimeException('probe_missions.probe_id is missing and player_id is not ready; cannot migrate.');
    }

    $missionCount = migrateProbeMissionsToPlayerCount($pdo, 'SELECT COUNT(*) FROM probe_missions');
    $orphanCount = migrateProbeMissionsToPlayerCount(
        $pdo,
        'SELECT COUNT(*)
         FROM probe_missions pm
         LEFT JOIN neumann_probes np ON np.id = pm.probe_id
         WHERE np.player_id IS NULL',
    );
    if ($orphanCount > 0) {
        throw new RuntimeException("Found {$orphanCount} mission(s) whose probe has no player; aborting migration.");
    }
    if ($options['dryRun']) {
        echo "Dry-run: {$missionCount} mission(s) can be migrated from probe_id to player_id.\n";

        return 0;
    }

    if ($config->driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = OFF');
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        if ($config->driver === 'sqlite') {
            migrateProbeMissionsToPlayerSqlite($pdo);
        } elseif ($config->driver === 'mysql') {
            migrateProbeMissionsToPlayerMysql($pdo, $hasPlayerId);
        } else {
            throw new RuntimeException('Unsupported database driver: ' . $config->driver);
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
        if ($config->driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($config->driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        throw $e;
    }

    echo "Probe missions migrated to player ownership.\n";
    echo "- missions migrated: {$missionCount}\n";
    echo "- legacy column: dropped\n";

    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{databaseConfig:?string,dryRun:bool,help:bool}
 */
function migrateProbeMissionsToPlayerParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
        'dryRun' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }
        if (str_starts_with($argument, '--database-config=')) {
            $value = substr($argument, strlen('--database-config='));
            if ($value === '') {
                throw new InvalidArgumentException('--database-config requires a path.');
            }
            $options['databaseConfig'] = $value;
            continue;
        }

        throw new InvalidArgumentException('Unknown option: ' . $argument);
    }

    return $options;
}

function migrateProbeMissionsToPlayerUsage(): string
{
    return <<<TXT
Usage:
  php scripts/migrate-probe-missions-to-player.php [--database-config=config/database.json] [--dry-run]

Moves probe_missions ownership from probe_id to player_id. Run this once during
the production git pull that deploys the player-owned mission model.

TXT;
}

function migrateProbeMissionsToPlayerAbsolutePath(string $root, string $path): string
{
    if ($path !== '' && $path[0] === '/') {
        return $path;
    }

    return $root . DIRECTORY_SEPARATOR . $path;
}

function migrateProbeMissionsToPlayerEnsureTable(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'probe_missions'");
        if ($stmt !== false && (int) $stmt->fetchColumn() > 0) {
            return;
        }
    } else {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => 'probe_missions']);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
    }
    throw new RuntimeException('probe_missions table does not exist.');
}

function migrateProbeMissionsToPlayerColumnExists(PDO $pdo, string $driver, string $table, string $column): bool
{
    if ($driver === 'sqlite') {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrateProbeMissionsToPlayerCount(PDO $pdo, string $sql): int
{
    $stmt = $pdo->query($sql);

    return $stmt === false ? 0 : (int) $stmt->fetchColumn();
}

function migrateProbeMissionsToPlayerSqlite(PDO $pdo): void
{
    $pdo->exec('DROP INDEX IF EXISTS idx_probe_missions_probe_status');
    $pdo->exec('DROP INDEX IF EXISTS idx_probe_missions_player_status');
    $pdo->exec('DROP INDEX IF EXISTS idx_probe_missions_uid');
    $pdo->exec(
        'CREATE TABLE probe_missions_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid TEXT NOT NULL UNIQUE,
            player_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            description TEXT NULL,
            status TEXT NOT NULL,
            step_order TEXT NOT NULL,
            metadata_json TEXT NOT NULL,
            created_by_event_json TEXT NULL,
            started_at TEXT NOT NULL,
            completed_at TEXT NULL,
            failed_at TEXT NULL,
            abandoned_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(player_id) REFERENCES players(id)
        )'
    );
    $pdo->exec(
        'INSERT INTO probe_missions_new
         (id, uid, player_id, type, title, description, status, step_order, metadata_json, created_by_event_json, started_at, completed_at, failed_at, abandoned_at, created_at, updated_at)
         SELECT pm.id, pm.uid, np.player_id, pm.type, pm.title, pm.description, pm.status, pm.step_order, pm.metadata_json, pm.created_by_event_json, pm.started_at, pm.completed_at, pm.failed_at, pm.abandoned_at, pm.created_at, pm.updated_at
         FROM probe_missions pm
         INNER JOIN neumann_probes np ON np.id = pm.probe_id'
    );
    $pdo->exec('DROP TABLE probe_missions');
    $pdo->exec('ALTER TABLE probe_missions_new RENAME TO probe_missions');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_missions_player_status ON probe_missions(player_id, status, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_probe_missions_uid ON probe_missions(uid)');
}

function migrateProbeMissionsToPlayerMysql(PDO $pdo, bool $hasPlayerId): void
{
    if (!$hasPlayerId) {
        $pdo->exec('ALTER TABLE probe_missions ADD COLUMN player_id INTEGER NULL AFTER uid');
    }
    $pdo->exec(
        'UPDATE probe_missions pm
         INNER JOIN neumann_probes np ON np.id = pm.probe_id
         SET pm.player_id = np.player_id
         WHERE pm.player_id IS NULL'
    );
    $remaining = migrateProbeMissionsToPlayerCount($pdo, 'SELECT COUNT(*) FROM probe_missions WHERE player_id IS NULL');
    if ($remaining > 0) {
        throw new RuntimeException("Unable to fill player_id for {$remaining} mission(s).");
    }
    migrateProbeMissionsToPlayerDropMysqlForeignKeysForColumn($pdo, 'probe_id');
    migrateProbeMissionsToPlayerDropMysqlIndex($pdo, 'idx_probe_missions_probe_status');
    $pdo->exec('ALTER TABLE probe_missions MODIFY player_id INTEGER NOT NULL');
    $pdo->exec('ALTER TABLE probe_missions DROP COLUMN probe_id');
    if (!migrateProbeMissionsToPlayerMysqlIndexExists($pdo, 'idx_probe_missions_player_status')) {
        $pdo->exec('CREATE INDEX idx_probe_missions_player_status ON probe_missions(player_id, status, created_at)');
    }
    if (!migrateProbeMissionsToPlayerMysqlForeignKeyExists($pdo, 'player_id')) {
        $pdo->exec('ALTER TABLE probe_missions ADD CONSTRAINT fk_probe_missions_player FOREIGN KEY (player_id) REFERENCES players(id)');
    }
}

function migrateProbeMissionsToPlayerDropMysqlForeignKeysForColumn(PDO $pdo, string $column): void
{
    $stmt = $pdo->prepare(
        'SELECT CONSTRAINT_NAME
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column
           AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $stmt->execute(['table' => 'probe_missions', 'column' => $column]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $constraintName) {
        $pdo->exec('ALTER TABLE probe_missions DROP FOREIGN KEY `' . str_replace('`', '``', (string) $constraintName) . '`');
    }
}

function migrateProbeMissionsToPlayerMysqlForeignKeyExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column
           AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $stmt->execute(['table' => 'probe_missions', 'column' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function migrateProbeMissionsToPlayerDropMysqlIndex(PDO $pdo, string $indexName): void
{
    if (!migrateProbeMissionsToPlayerMysqlIndexExists($pdo, $indexName)) {
        return;
    }

    $pdo->exec('DROP INDEX ' . $indexName . ' ON probe_missions');
}

function migrateProbeMissionsToPlayerMysqlIndexExists(PDO $pdo, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND INDEX_NAME = :index_name'
    );
    $stmt->execute(['table' => 'probe_missions', 'index_name' => $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}
