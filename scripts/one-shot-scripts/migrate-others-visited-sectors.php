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
        echo "Usage: php scripts/one-shot-scripts/migrate-others-visited-sectors.php [--database-config=PATH]\n";
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
    if (!othersVisitedTableExists($pdo, $driver, 'others_fleets') || !othersVisitedTableExists($pdo, $driver, 'others_ships')) {
        throw new RuntimeException('The canonical Others fleet schema must be installed before this migration.');
    }

    $enteredSectorColumnAdded = !othersVisitedColumnExists($pdo, $driver, 'others_ships', 'entered_sector_at');
    if ($enteredSectorColumnAdded) {
        if ($driver === 'mysql') {
            $pdo->exec('ALTER TABLE others_ships ADD COLUMN entered_sector_at VARCHAR(255) NULL AFTER laser_next_target_at');
        } else {
            $pdo->exec("ALTER TABLE others_ships ADD COLUMN entered_sector_at TEXT NOT NULL DEFAULT ''");
        }
    }
    $pdo->exec("UPDATE others_ships SET entered_sector_at=updated_at WHERE entered_sector_at IS NULL OR entered_sector_at=''");
    if ($driver === 'mysql' && $enteredSectorColumnAdded) {
        $pdo->exec('ALTER TABLE others_ships MODIFY entered_sector_at VARCHAR(255) NOT NULL');
    }

    $visitedTableCreated = !othersVisitedTableExists($pdo, $driver, 'others_visited_sectors');
    if ($visitedTableCreated) {
        $id = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
        $pdo->exec(
            "CREATE TABLE others_visited_sectors (
                id {$id},
                fleet_id INTEGER NOT NULL,
                sector_x INTEGER NOT NULL,
                sector_y INTEGER NOT NULL,
                sector_z INTEGER NOT NULL,
                first_visited_at {$text} NOT NULL,
                last_visited_at {$text} NOT NULL,
                visit_count INTEGER NOT NULL DEFAULT 1,
                UNIQUE(fleet_id, sector_x, sector_y, sector_z),
                FOREIGN KEY(fleet_id) REFERENCES others_fleets(id) ON DELETE CASCADE
            )"
        );
    }
    if (!othersVisitedIndexExists($pdo, $driver, 'others_visited_sectors', 'idx_others_visited_sectors_fleet_last')) {
        $pdo->exec('CREATE INDEX idx_others_visited_sectors_fleet_last ON others_visited_sectors(fleet_id, last_visited_at, id)');
    }

    $seedSql = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $seeded = $pdo->exec(
        $seedSql . " INTO others_visited_sectors
            (fleet_id,sector_x,sector_y,sector_z,first_visited_at,last_visited_at,visit_count)
         SELECT fleet_id,sector_x,sector_y,sector_z,
                MIN(entered_sector_at),MAX(entered_sector_at),1
         FROM others_ships
         WHERE destroyed_at IS NULL AND status <> 'removed' AND status <> 'transit'
         GROUP BY fleet_id,sector_x,sector_y,sector_z"
    );

    $requiredColumns = ['id', 'fleet_id', 'sector_x', 'sector_y', 'sector_z', 'first_visited_at', 'last_visited_at', 'visit_count'];
    $missingColumns = array_values(array_diff($requiredColumns, othersVisitedColumns($pdo, $driver, 'others_visited_sectors')));
    if ($missingColumns !== []) {
        throw new RuntimeException('Existing others_visited_sectors table is not canonical; missing columns: ' . implode(', ', $missingColumns));
    }

    printf(
        "Others visited-sector schema ready: table_created=%s entered_sector_column_added=%s current_sectors_seeded=%d\n",
        $visitedTableCreated ? 'yes' : 'no',
        $enteredSectorColumnAdded ? 'yes' : 'no',
        (int) $seeded,
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Others visited-sector migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}

function othersVisitedTableExists(PDO $pdo, string $driver, string $table): bool
{
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table_name");
        $stmt->execute(['table_name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name');
    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function othersVisitedColumnExists(PDO $pdo, string $driver, string $table, string $column): bool
{
    return in_array($column, othersVisitedColumns($pdo, $driver, $table), true);
}

/** @return list<string> */
function othersVisitedColumns(PDO $pdo, string $driver, string $table): array
{
    if ($driver === 'sqlite') {
        return array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    return array_column($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC), 'Field');
}

function othersVisitedIndexExists(PDO $pdo, string $driver, string $table, string $index): bool
{
    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND INDEX_NAME=:index_name');
    $stmt->execute(['table_name' => $table, 'index_name' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}
