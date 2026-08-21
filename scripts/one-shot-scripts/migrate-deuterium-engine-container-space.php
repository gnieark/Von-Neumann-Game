<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Service\DeuteriumEngineContainerSpaceMigration;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
$universePath = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--universe-path=')) {
        $universePath = substr($argument, strlen('--universe-path='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-deuterium-engine-container-space.php [--dry-run] [--database-config=PATH] [--universe-path=PATH]\n";
        echo "The application and scheduler must be stopped while this migration runs.\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$root = dirname(__DIR__, 2);
$factory = new AppFactory($root);
$appConfig = $factory->appConfig();
$configuredUniversePath = $universePath ?? (string) ($appConfig['universePath'] ?? 'data/universe');
if (!str_starts_with($configuredUniversePath, DIRECTORY_SEPARATOR)) {
    $configuredUniversePath = $root . DIRECTORY_SEPARATOR . $configuredUniversePath;
}

try {
    $pdo = $factory->pdo($databaseConfig, initializeSchema: false);
    $migration = new DeuteriumEngineContainerSpaceMigration();
    $sectorPlan = $migration->migrateSectorFiles($configuredUniversePath, true);
    $databasePlan = migrateDeuteriumEngineDatabase($pdo, $migration, true);

    if (!$dryRun) {
        $pdo->beginTransaction();
        try {
            $databasePlan = migrateDeuteriumEngineDatabase($pdo, $migration, false);
            $sectorPlan = $migration->migrateSectorFiles($configuredUniversePath, false);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    printf(
        "Deuterium engine container-space migration complete: database_rows=%d json_payloads=%d json_values=%d files_scanned=%d files_changed=%d drifting_items=%d dry_run=%s\n",
        $databasePlan['typedRows'] + $databasePlan['mannyReservations'],
        $databasePlan['jsonPayloads'],
        $databasePlan['jsonValues'],
        $sectorPlan['filesScanned'],
        $sectorPlan['filesChanged'],
        $sectorPlan['driftingItemsChanged'],
        $dryRun ? 'yes' : 'no',
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Deuterium engine container-space migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/**
 * @return array{typedRows:int,mannyReservations:int,jsonPayloads:int,jsonValues:int}
 */
function migrateDeuteriumEngineDatabase(PDO $pdo, DeuteriumEngineContainerSpaceMigration $migration, bool $dryRun): array
{
    $space = CraftingRecipeCatalog::DEUTERIUM_ENGINE_CONTAINER_SPACE;
    $report = ['typedRows' => 0, 'mannyReservations' => 0, 'jsonPayloads' => 0, 'jsonValues' => 0];
    foreach (['probe_items', 'detached_storage_container_items', 'manny_task_consumed_items'] as $table) {
        if (!deuteriumEngineMigrationColumnExists($pdo, $table, 'container_space')) {
            continue;
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE type = :type AND ABS(container_space - :space) > 0.00001");
        $count->execute(['type' => ProbeItem::TYPE_DEUTERIUM_ENGINE, 'space' => $space]);
        $affected = (int) $count->fetchColumn();
        $report['typedRows'] += $affected;
        if (!$dryRun && $affected > 0) {
            $update = $pdo->prepare("UPDATE {$table} SET container_space = :space WHERE type = :type AND ABS(container_space - :comparison_space) > 0.00001");
            $update->execute(['type' => ProbeItem::TYPE_DEUTERIUM_ENGINE, 'space' => $space, 'comparison_space' => $space]);
        }
    }

    if (
        deuteriumEngineMigrationColumnExists($pdo, 'mannies', 'reserved_cargo_type')
        && deuteriumEngineMigrationColumnExists($pdo, 'mannies', 'reserved_cargo_space')
    ) {
        $reservationCount = $pdo->prepare(
            'SELECT COUNT(*) FROM mannies WHERE reserved_cargo_type = :type AND ABS(reserved_cargo_space - :space) > 0.00001'
        );
        $reservationCount->execute(['type' => ProbeItem::TYPE_DEUTERIUM_ENGINE, 'space' => $space]);
        $report['mannyReservations'] = (int) $reservationCount->fetchColumn();
        if (!$dryRun && $report['mannyReservations'] > 0) {
            $reservationUpdate = $pdo->prepare(
                'UPDATE mannies SET reserved_cargo_space = :space, updated_at = :updated_at WHERE reserved_cargo_type = :type AND ABS(reserved_cargo_space - :comparison_space) > 0.00001'
            );
            $reservationUpdate->execute([
                'type' => ProbeItem::TYPE_DEUTERIUM_ENGINE,
                'space' => $space,
                'comparison_space' => $space,
                'updated_at' => gmdate('c'),
            ]);
        }
    }

    foreach ([['mannies', 'task_payload_json'], ['scheduled_events', 'payload_json']] as [$table, $column]) {
        if (!deuteriumEngineMigrationColumnExists($pdo, $table, $column)) {
            continue;
        }
        $select = $pdo->prepare("SELECT id, {$column} FROM {$table} WHERE {$column} LIKE :needle");
        $select->execute(['needle' => '%' . ProbeItem::TYPE_DEUTERIUM_ENGINE . '%']);
        $update = $pdo->prepare("UPDATE {$table} SET {$column} = :payload WHERE id = :id");
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) $row[$column], true, 512, JSON_THROW_ON_ERROR);
            $changed = $migration->normalizeJsonPayload($payload);
            if ($changed === 0) {
                continue;
            }
            $report['jsonPayloads']++;
            $report['jsonValues'] += $changed;
            if (!$dryRun) {
                $update->execute([
                    'id' => (int) $row['id'],
                    'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }

    return $report;
}

function deuteriumEngineMigrationColumnExists(PDO $pdo, string $table, string $column): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $columns = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $candidate) {
            if (($candidate['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}
