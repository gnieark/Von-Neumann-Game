<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(removeLegacyProbeResourceStocksMain($argv));
}

/**
 * @param array<int, string> $arguments
 */
function removeLegacyProbeResourceStocksMain(array $arguments): int
{
    $databaseConfig = 'config/database.json';
    $dryRun = false;
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--dry-run') {
            $dryRun = true;
        } elseif (str_starts_with($argument, '--database-config=')) {
            $databaseConfig = substr($argument, strlen('--database-config='));
        } elseif ($argument === '--help' || $argument === '-h') {
            echo "Usage: php scripts/one-shot-scripts/remove-legacy-probe-resource-stocks.php [--dry-run] [--database-config=PATH]\n";
            echo "The application and scheduler must be stopped while this migration runs.\n";

            return 0;
        } else {
            fwrite(STDERR, "Unknown argument: {$argument}\n");

            return 2;
        }
    }

    try {
        $root = dirname(__DIR__, 2);
        $pdo = (new AppFactory($root))->pdo($databaseConfig, initializeSchema: false);
        $report = removeLegacyProbeResourceStocks($pdo, $dryRun);
        printf(
            "Legacy probe resource stocks: probes=%d containers=%d resource_rows=%d mismatches=%d columns_dropped=%d dry_run=%s already_migrated=%s\n",
            $report['probes'],
            $report['containers'],
            $report['resourceRows'],
            $report['mismatches'],
            $report['columnsDropped'],
            $dryRun ? 'yes' : 'no',
            $report['alreadyMigrated'] ? 'yes' : 'no',
        );

        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Legacy probe resource-stock migration failed: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * @return array{probes:int,containers:int,resourceRows:int,mismatches:int,columnsDropped:int,alreadyMigrated:bool}
 */
function removeLegacyProbeResourceStocks(PDO $pdo, bool $dryRun): array
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['sqlite', 'mysql'], true)) {
        throw new RuntimeException("Unsupported database driver: {$driver}");
    }

    foreach (['neumann_probes', 'storage_containers', 'storage_container_resources'] as $table) {
        if (!legacyProbeResourceStocksTableExists($pdo, $table)) {
            throw new RuntimeException("Required table {$table} is absent; migrate storage containers before removing legacy stocks.");
        }
    }

    $legacyColumns = ['metals_stock', 'ice_stock', 'organic_compounds_stock'];
    $presentColumns = array_values(array_filter(
        $legacyColumns,
        static fn(string $column): bool => legacyProbeResourceStocksColumnExists($pdo, 'neumann_probes', $column),
    ));
    $report = [
        'probes' => (int) $pdo->query('SELECT COUNT(*) FROM neumann_probes')->fetchColumn(),
        'containers' => (int) $pdo->query('SELECT COUNT(*) FROM storage_containers')->fetchColumn(),
        'resourceRows' => (int) $pdo->query('SELECT COUNT(*) FROM storage_container_resources')->fetchColumn(),
        'mismatches' => 0,
        'columnsDropped' => 0,
        'alreadyMigrated' => $presentColumns === [],
    ];
    if ($presentColumns === []) {
        return $report;
    }
    if (count($presentColumns) !== count($legacyColumns)) {
        throw new RuntimeException(
            'Legacy probe resource-stock columns are only partially present: ' . implode(', ', $presentColumns) . '.'
        );
    }

    $invalidStorage = $pdo->query(
        "SELECT p.id
         FROM neumann_probes p
         LEFT JOIN storage_containers c ON c.probe_id = p.id
         GROUP BY p.id
         HAVING COUNT(c.id) = 0
            OR SUM(CASE WHEN c.uid = 'probe-core' THEN 1 ELSE 0 END) <> 1
         ORDER BY p.id"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($invalidStorage !== []) {
        throw new RuntimeException(
            'Storage containers are incomplete for probe IDs: ' . implode(', ', array_slice($invalidStorage, 0, 20)) . '.'
        );
    }

    $mismatchSql =
        "SELECT p.id
         FROM neumann_probes p
         LEFT JOIN storage_containers c ON c.probe_id = p.id
         LEFT JOIN storage_container_resources r ON r.container_id = c.id
         GROUP BY p.id, p.metals_stock, p.ice_stock, p.organic_compounds_stock
         HAVING ABS(COALESCE(SUM(CASE WHEN r.resource_type = 'metals' THEN r.amount ELSE 0 END), 0) - p.metals_stock) > 0.0001
             OR ABS(COALESCE(SUM(CASE WHEN r.resource_type = 'ice' THEN r.amount ELSE 0 END), 0) - p.ice_stock) > 0.0001
             OR ABS(COALESCE(SUM(CASE WHEN r.resource_type = 'carbon_compounds' THEN r.amount ELSE 0 END), 0) - p.organic_compounds_stock) > 0.0001
         ORDER BY p.id";
    $mismatches = $pdo->query($mismatchSql)->fetchAll(PDO::FETCH_COLUMN);
    $report['mismatches'] = count($mismatches);
    if ($mismatches !== []) {
        throw new RuntimeException(
            'Legacy totals differ from normalized storage for probe IDs: ' . implode(', ', array_slice($mismatches, 0, 20)) . '.'
        );
    }

    if ($dryRun) {
        return $report;
    }

    if ($driver === 'mysql') {
        $pdo->exec(
            'ALTER TABLE neumann_probes
             DROP COLUMN metals_stock,
             DROP COLUMN ice_stock,
             DROP COLUMN organic_compounds_stock'
        );
    } else {
        $pdo->beginTransaction();
        try {
            foreach ($legacyColumns as $column) {
                $pdo->exec("ALTER TABLE neumann_probes DROP COLUMN {$column}");
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    foreach ($legacyColumns as $column) {
        if (legacyProbeResourceStocksColumnExists($pdo, 'neumann_probes', $column)) {
            throw new RuntimeException("Column neumann_probes.{$column} still exists after migration.");
        }
    }
    $report['columnsDropped'] = count($legacyColumns);

    return $report;
}

function legacyProbeResourceStocksTableExists(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table_name");
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $statement->execute(['table_name' => $table]);

    return (int) $statement->fetchColumn() === 1;
}

function legacyProbeResourceStocksColumnExists(PDO $pdo, string $table, string $column): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if (($candidate['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $statement->execute(['table_name' => $table, 'column_name' => $column]);

    return (int) $statement->fetchColumn() === 1;
}
