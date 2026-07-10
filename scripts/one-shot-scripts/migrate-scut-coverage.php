<?php

declare(strict_types=1);

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(migrateScutCoverageRun($argv));
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . migrateScutCoverageUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to migrate SCUT coverage: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function migrateScutCoverageRun(array $argv): int
{
    $options = migrateScutCoverageParseArguments($argv);
    if ($options['help']) {
        echo migrateScutCoverageUsage();

        return 0;
    }

    $root = dirname(__DIR__);
    $configPath = migrateScutCoverageAbsolutePath($root, $options['databaseConfig'] ?? 'config/database.json');
    $config = DatabaseConfig::fromFile($configPath);
    $pdo = (new DatabaseConnectionFactory($config, $root))->create();

    migrateScutCoverageEnsureSchema($pdo, $config->driver);
    $hasRelayJson = migrateScutCoverageColumnExists($pdo, $config->driver, 'scut_relays', 'covered_sectors_json');
    $hasNetworkJson = migrateScutCoverageColumnExists($pdo, $config->driver, 'scut_networks', 'covered_sectors_json');
    if (!$hasRelayJson && !$hasNetworkJson) {
        echo "SCUT coverage already uses scut_covered_sectors; no legacy JSON columns found.\n";

        return 0;
    }
    if (!$hasRelayJson) {
        throw new RuntimeException('Missing scut_relays.covered_sectors_json; cannot migrate relay coverage.');
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $migratedRows = migrateScutCoveragePopulate($pdo, $config->driver);
        if (!$options['keepLegacyColumns']) {
            migrateScutCoverageDropColumn($pdo, $config->driver, 'scut_relays', 'covered_sectors_json');
            if ($hasNetworkJson) {
                migrateScutCoverageDropColumn($pdo, $config->driver, 'scut_networks', 'covered_sectors_json');
            }
        }
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo "SCUT coverage migrated to scut_covered_sectors.\n";
    echo "- coverage rows: {$migratedRows}\n";
    echo '- legacy columns: ' . ($options['keepLegacyColumns'] ? 'kept' : 'dropped') . "\n";

    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{databaseConfig:?string,keepLegacyColumns:bool,help:bool}
 */
function migrateScutCoverageParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
        'keepLegacyColumns' => false,
        'help' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--keep-legacy-columns') {
            $options['keepLegacyColumns'] = true;
            continue;
        }
        if ($arg === '--database-config') {
            $options['databaseConfig'] = $argv[++$i] ?? throw new InvalidArgumentException('Missing value for --database-config.');
            continue;
        }
        if (str_starts_with($arg, '--database-config=')) {
            $options['databaseConfig'] = substr($arg, strlen('--database-config='));
            continue;
        }

        throw new InvalidArgumentException('Unknown argument: ' . $arg);
    }

    return $options;
}

function migrateScutCoverageEnsureSchema(PDO $pdo, string $driver): void
{
    $id = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS scut_covered_sectors (
            id $id,
            scut_network_id INTEGER NULL,
            scut_relay_id INTEGER NOT NULL,
            sector_x INTEGER NOT NULL,
            sector_y INTEGER NOT NULL,
            sector_z INTEGER NOT NULL,
            FOREIGN KEY(scut_network_id) REFERENCES scut_networks(id) ON DELETE CASCADE,
            FOREIGN KEY(scut_relay_id) REFERENCES scut_relays(id) ON DELETE CASCADE
        )"
    );
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_scut_covered_sectors_relay_sector ON scut_covered_sectors(scut_relay_id, sector_x, sector_y, sector_z)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scut_covered_sectors_network_sector ON scut_covered_sectors(scut_network_id, sector_x, sector_y, sector_z)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scut_covered_sectors_sector ON scut_covered_sectors(sector_x, sector_y, sector_z)');
}

function migrateScutCoveragePopulate(PDO $pdo, string $driver): int
{
    $pdo->exec('DELETE FROM scut_covered_sectors');
    $insertSql = $driver === 'mysql'
        ? 'INSERT IGNORE INTO scut_covered_sectors (scut_network_id, scut_relay_id, sector_x, sector_y, sector_z) VALUES (:network_id, :relay_id, :x, :y, :z)'
        : 'INSERT OR IGNORE INTO scut_covered_sectors (scut_network_id, scut_relay_id, sector_x, sector_y, sector_z) VALUES (:network_id, :relay_id, :x, :y, :z)';
    $insert = $pdo->prepare($insertSql);
    $rows = $pdo->query(
        'SELECT id, network_id, covered_sectors_json
         FROM scut_relays
         WHERE covered_sectors_json IS NOT NULL'
    )->fetchAll(PDO::FETCH_ASSOC);

    $migrated = 0;
    foreach ($rows as $row) {
        $coverage = migrateScutCoverageDecode((string) ($row['covered_sectors_json'] ?? '[]'), (int) $row['id']);
        foreach ($coverage as $sector) {
            $insert->execute([
                'network_id' => $row['network_id'] !== null ? (int) $row['network_id'] : null,
                'relay_id' => (int) $row['id'],
                'x' => $sector['x'],
                'y' => $sector['y'],
                'z' => $sector['z'],
            ]);
            $migrated += $insert->rowCount();
        }
    }

    return $migrated;
}

/**
 * @return array<int, array{x:int,y:int,z:int}>
 */
function migrateScutCoverageDecode(string $json, int $relayId): array
{
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Invalid covered_sectors_json for SCUT relay #' . $relayId . ': ' . $e->getMessage(), 0, $e);
    }
    if (!is_array($decoded)) {
        return [];
    }

    $coverage = [];
    foreach ($decoded as $sector) {
        if (!is_array($sector)) {
            continue;
        }
        $coverage[(int) ($sector['x'] ?? 0) . ':' . (int) ($sector['y'] ?? 0) . ':' . (int) ($sector['z'] ?? 0)] = [
            'x' => (int) ($sector['x'] ?? 0),
            'y' => (int) ($sector['y'] ?? 0),
            'z' => (int) ($sector['z'] ?? 0),
        ];
    }

    return array_values($coverage);
}

function migrateScutCoverageColumnExists(PDO $pdo, string $driver, string $table, string $column): bool
{
    if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA table_info(' . migrateScutCoverageQuoteString($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . migrateScutCoverageQuoteIdentifier($table, $driver) . ' WHERE Field = :column');
    $stmt->execute(['column' => $column]);

    return $stmt->fetch() !== false;
}

function migrateScutCoverageDropColumn(PDO $pdo, string $driver, string $table, string $column): void
{
    if (!migrateScutCoverageColumnExists($pdo, $driver, $table, $column)) {
        return;
    }

    $pdo->exec(
        'ALTER TABLE '
        . migrateScutCoverageQuoteIdentifier($table, $driver)
        . ' DROP COLUMN '
        . migrateScutCoverageQuoteIdentifier($column, $driver)
    );
}

function migrateScutCoverageQuoteIdentifier(string $identifier, string $driver): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
    }

    return $driver === 'mysql' ? '`' . $identifier . '`' : '"' . $identifier . '"';
}

function migrateScutCoverageQuoteString(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function migrateScutCoverageAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

function migrateScutCoverageUsage(): string
{
    return <<<TXT
Usage:
  php scripts/migrate-scut-coverage.php [--database-config path] [--keep-legacy-columns]

Migrates legacy SCUT coverage from scut_relays.covered_sectors_json into
scut_covered_sectors, then drops the old JSON columns by default.

Run once just after deploying the code that introduces scut_covered_sectors.

TXT;
}
