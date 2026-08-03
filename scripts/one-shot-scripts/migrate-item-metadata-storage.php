<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\ItemMetadataColumns;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-item-metadata-storage.php [--database-config=PATH]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$root = dirname(__DIR__, 2);
$pdo = (new AppFactory($root))->pdo($databaseConfig, initializeSchema: false);
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$hasColumn = static function (string $table, string $column) use ($pdo, $driver): bool {
    if ($driver === 'sqlite') {
        return in_array($column, array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name');
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $stmt->fetchColumn() === 1;
};

foreach (['probe_items', 'detached_storage_container_items'] as $table) {
    if (!$hasColumn($table, 'metadata_json')) {
        throw new RuntimeException("{$table}.metadata_json is absent; migration has already run or the schema is incompatible.");
    }
}

$definitions = [
    'recipe' => 'VARCHAR(255) NULL', 'crafting_run_id' => 'VARCHAR(255) NULL',
    'crafted_by_manny_id' => 'VARCHAR(255) NULL', 'crafted_by_manny_name' => 'VARCHAR(255) NULL',
    'crafted_at' => 'VARCHAR(255) NULL', 'fabricator' => 'VARCHAR(255) NULL',
    'capacity_bonus' => 'DOUBLE NOT NULL DEFAULT 0',
    'restored_detached_container_source_uid' => 'VARCHAR(255) NULL',
    'audit_metadata_json' => "TEXT NOT NULL DEFAULT '{}'",
];
if ($driver === 'sqlite') {
    $definitions = array_map(static fn(string $definition): string => str_replace(['VARCHAR(255)', 'DOUBLE'], ['TEXT', 'REAL'], $definition), $definitions);
}
foreach (['probe_items', 'detached_storage_container_items'] as $table) {
    foreach ($definitions as $column => $definition) {
        if (!$hasColumn($table, $column)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
}

$pdo->beginTransaction();
try {
    $total = 0;
    foreach (['probe_items', 'detached_storage_container_items'] as $table) {
        $update = $pdo->prepare(
            "UPDATE {$table} SET recipe=:recipe, crafting_run_id=:crafting_run_id,
             crafted_by_manny_id=:crafted_by_manny_id, crafted_by_manny_name=:crafted_by_manny_name,
             crafted_at=:crafted_at, fabricator=:fabricator, capacity_bonus=:capacity_bonus,
             restored_detached_container_source_uid=:restored_detached_container_source_uid,
             audit_metadata_json=:audit_metadata_json WHERE id=:id"
        );
        foreach ($pdo->query("SELECT id, metadata_json FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata = json_decode((string) $row['metadata_json'], true);
            $update->execute(['id' => (int) $row['id']] + ItemMetadataColumns::parameters(is_array($metadata) ? $metadata : []));
            $total++;
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

foreach (['probe_items', 'detached_storage_container_items'] as $table) {
    $pdo->exec("ALTER TABLE {$table} DROP COLUMN metadata_json");
}
$pdo->exec('CREATE INDEX idx_probe_items_restored_source ON probe_items(probe_id, restored_detached_container_source_uid)');
echo "Migrated item metadata rows: {$total}; dropped legacy metadata_json columns.\n";
