<?php

declare(strict_types=1);

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;

require_once __DIR__ . '/../vendor/autoload.php';

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "\nMigration failed: " . $e->getMessage() . "\n");
    exit(1);
});

$options = parseOptions($argv);
if ($options['help']) {
    echo usage();
    exit(0);
}

$root = dirname(__DIR__);
$sourceConfigPath = absolutePath($root, $options['sourceConfig']);
$targetConfigPath = absolutePath($root, $options['targetConfig']);
$backupConfigPath = absolutePath($root, $options['backupConfig']);

preflightConfigFiles($sourceConfigPath, $targetConfigPath, $backupConfigPath, $options['skipConfigSwap']);

$sourceConfig = DatabaseConfig::fromFile($sourceConfigPath);
$targetConfig = DatabaseConfig::fromFile($targetConfigPath);
assertDriver($sourceConfig, 'sqlite', $sourceConfigPath);
assertDriver($targetConfig, 'mysql', $targetConfigPath);

echo "SQLite source: $sourceConfigPath\n";
echo "MySQL target: $targetConfigPath\n";
echo 'Target database: ' . ($targetConfig->database ?? '(none)') . '@' . ($targetConfig->host ?? 'localhost') . ':' . $targetConfig->port . "\n";
echo "\n";

if (!$options['yes']) {
    echo "This will lock the SQLite database, copy all rows to MySQL, and then switch config/database.json.\n";
    echo "Type MIGRATE to continue: ";
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'MIGRATE') {
        echo "Migration cancelled.\n";
        exit(1);
    }
}

$sourceFactory = new DatabaseConnectionFactory($sourceConfig, $root);
$targetFactory = new DatabaseConnectionFactory($targetConfig, $root);

$source = $sourceFactory->create();
$source->exec('PRAGMA busy_timeout = 30000');
$sourceLocked = false;
$target = null;

try {
    echo "Locking SQLite source with BEGIN IMMEDIATE...\n";
    $source->exec('BEGIN IMMEDIATE');
    $sourceLocked = true;

    $sourceTables = sqliteTables($source);
    if ($sourceTables === []) {
        throw new RuntimeException('No source tables found in SQLite database.');
    }

    $sourceCounts = tableCounts($source, $sourceTables, 'sqlite');
    echo 'Source tables: ' . count($sourceTables) . ', rows: ' . array_sum($sourceCounts) . "\n";

    echo "Connecting to MySQL target and initializing schema...\n";
    $target = $targetFactory->create();
    $targetFactory->initializeSchema($target);

    $targetTables = mysqlTables($target);
    $missingTables = array_values(array_diff($sourceTables, $targetTables));
    if ($missingTables !== []) {
        throw new RuntimeException('Target schema is missing table(s): ' . implode(', ', $missingTables));
    }

    assertTargetIsEmptyOrForced($target, $sourceTables, $options['force']);

    echo "Copying rows...\n";
    $copied = copyTables($source, $target, $sourceTables, $options['force']);

    $targetCounts = tableCounts($target, $sourceTables, 'mysql');
    assertCountsMatch($sourceCounts, $targetCounts);

    $source->exec('COMMIT');
    $sourceLocked = false;

    if (!$options['skipConfigSwap']) {
        swapConfigFiles($sourceConfigPath, $targetConfigPath, $backupConfigPath);
    }

    echo "\nMigration complete.\n";
    echo '- tables copied: ' . count($copied) . "\n";
    echo '- rows copied: ' . array_sum($copied) . "\n";
    if (!$options['skipConfigSwap']) {
        echo "- active config: $sourceConfigPath\n";
        echo "- previous config: $backupConfigPath\n";
    }
} catch (Throwable $e) {
    if ($target instanceof PDO && $target->inTransaction()) {
        $target->rollBack();
    }
    if ($sourceLocked) {
        $source->exec('ROLLBACK');
    }

    fwrite(STDERR, "\nMigration failed: " . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{sourceConfig: string, targetConfig: string, backupConfig: string, yes: bool, force: bool, skipConfigSwap: bool, help: bool}
 */
function parseOptions(array $argv): array
{
    $options = [
        'sourceConfig' => 'config/database.json',
        'targetConfig' => 'config/database-futur-local.json',
        'backupConfig' => 'config/database.json.old',
        'yes' => false,
        'force' => false,
        'skipConfigSwap' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--yes' || $arg === '-y') {
            $options['yes'] = true;
            continue;
        }
        if ($arg === '--force') {
            $options['force'] = true;
            continue;
        }
        if ($arg === '--skip-config-swap') {
            $options['skipConfigSwap'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if (str_starts_with($arg, '--source-config=')) {
            $options['sourceConfig'] = substr($arg, strlen('--source-config='));
            continue;
        }
        if (str_starts_with($arg, '--target-config=')) {
            $options['targetConfig'] = substr($arg, strlen('--target-config='));
            continue;
        }
        if (str_starts_with($arg, '--backup-config=')) {
            $options['backupConfig'] = substr($arg, strlen('--backup-config='));
            continue;
        }

        fwrite(STDERR, "Unknown option: $arg\n\n" . usage());
        exit(1);
    }

    return $options;
}

function usage(): string
{
    return <<<TEXT
Usage: php scripts/migrate-sqlite-to-mysql.php [--yes] [--force] [--skip-config-swap]
       [--source-config=path] [--target-config=path] [--backup-config=path]

Copies the current SQLite database to the future MySQL/MariaDB database.

Default flow:
  - source config: config/database.json
  - target config: config/database-futur-local.json
  - lock source SQLite with BEGIN IMMEDIATE
  - initialize target schema
  - refuse a non-empty target unless --force is passed
  - copy rows table by table
  - rename config/database.json to config/database.json.old
  - rename config/database-futur-local.json to config/database.json

TEXT;
}

function absolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

function preflightConfigFiles(string $sourceConfigPath, string $targetConfigPath, string $backupConfigPath, bool $skipConfigSwap): void
{
    if (!is_file($sourceConfigPath)) {
        throw new RuntimeException("Source config not found: $sourceConfigPath");
    }
    if (!is_file($targetConfigPath)) {
        throw new RuntimeException("Target config not found: $targetConfigPath");
    }
    if (!$skipConfigSwap && is_file($backupConfigPath)) {
        throw new RuntimeException("Backup config already exists: $backupConfigPath");
    }
}

function assertDriver(DatabaseConfig $config, string $expected, string $path): void
{
    if ($config->driver !== $expected) {
        throw new RuntimeException("Expected $path to use driver '$expected', got '{$config->driver}'.");
    }
}

/**
 * @return array<int, string>
 */
function sqliteTables(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    return array_map('strval', $rows);
}

/**
 * @return array<int, string>
 */
function mysqlTables(PDO $pdo): array
{
    $rows = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $rows = array_filter($rows, static fn(array $row): bool => (string) ($row[1] ?? '') === 'BASE TABLE');

    return array_map(static fn(array $row): string => (string) $row[0], $rows);
}

/**
 * @param array<int, string> $tables
 * @return array<string, int>
 */
function tableCounts(PDO $pdo, array $tables, string $driver): array
{
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . quoteIdentifier($table, $driver))->fetchColumn();
    }

    return $counts;
}

/**
 * @param array<int, string> $tables
 */
function assertTargetIsEmptyOrForced(PDO $target, array $tables, bool $force): void
{
    $nonEmpty = [];
    foreach ($tables as $table) {
        $count = (int) $target->query('SELECT COUNT(*) FROM ' . quoteIdentifier($table, 'mysql'))->fetchColumn();
        if ($count > 0) {
            $nonEmpty[$table] = $count;
        }
    }

    if ($nonEmpty !== [] && !$force) {
        $parts = [];
        foreach ($nonEmpty as $table => $count) {
            $parts[] = "$table=$count";
        }

        throw new RuntimeException('Target database is not empty. Use --force to delete target rows first: ' . implode(', ', $parts));
    }
}

/**
 * @param array<int, string> $tables
 * @return array<string, int>
 */
function copyTables(PDO $source, PDO $target, array $tables, bool $force): array
{
    $copied = [];
    $target->exec('SET FOREIGN_KEY_CHECKS=0');
    $target->beginTransaction();

    try {
        if ($force) {
            foreach ($tables as $table) {
                $target->exec('DELETE FROM ' . quoteIdentifier($table, 'mysql'));
            }
        }

        foreach ($tables as $table) {
            $sourceColumns = sqliteColumns($source, $table);
            $targetColumns = mysqlColumns($target, $table);
            $columns = array_values(array_intersect($sourceColumns, $targetColumns));
            if ($columns === []) {
                throw new RuntimeException("No common columns for table $table.");
            }

            $select = $source->query(
                'SELECT ' . implode(', ', array_map(static fn(string $column): string => quoteIdentifier($column, 'sqlite'), $columns))
                . ' FROM ' . quoteIdentifier($table, 'sqlite')
            );
            $insert = $target->prepare(
                'INSERT INTO ' . quoteIdentifier($table, 'mysql')
                . ' (' . implode(', ', array_map(static fn(string $column): string => quoteIdentifier($column, 'mysql'), $columns)) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );

            $count = 0;
            while (($row = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $row[$column];
                }
                $insert->execute($values);
                $count++;
            }
            $copied[$table] = $count;
            echo "- $table: $count rows\n";
        }

        $target->commit();
    } catch (Throwable $e) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        throw $e;
    } finally {
        $target->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    return $copied;
}

/**
 * @return array<int, string>
 */
function sqliteColumns(PDO $pdo, string $table): array
{
    $statement = $pdo->query('PRAGMA table_info(' . quoteString($table) . ')');
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static fn(array $row): string => (string) $row['name'], $rows);
}

/**
 * @return array<int, string>
 */
function mysqlColumns(PDO $pdo, string $table): array
{
    $rows = $pdo->query('SHOW COLUMNS FROM ' . quoteIdentifier($table, 'mysql'))->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static fn(array $row): string => (string) $row['Field'], $rows);
}

/**
 * @param array<string, int> $sourceCounts
 * @param array<string, int> $targetCounts
 */
function assertCountsMatch(array $sourceCounts, array $targetCounts): void
{
    foreach ($sourceCounts as $table => $sourceCount) {
        $targetCount = $targetCounts[$table] ?? null;
        if ($targetCount !== $sourceCount) {
            throw new RuntimeException("Copied row count mismatch for $table: source=$sourceCount target=" . var_export($targetCount, true));
        }
    }
}

function swapConfigFiles(string $sourceConfigPath, string $targetConfigPath, string $backupConfigPath): void
{
    echo "Switching database config files...\n";
    if (!rename($sourceConfigPath, $backupConfigPath)) {
        throw new RuntimeException("Unable to rename $sourceConfigPath to $backupConfigPath");
    }

    if (!rename($targetConfigPath, $sourceConfigPath)) {
        rename($backupConfigPath, $sourceConfigPath);
        throw new RuntimeException("Unable to rename $targetConfigPath to $sourceConfigPath; source config was restored.");
    }
}

function quoteIdentifier(string $identifier, string $driver): string
{
    if ($driver === 'mysql') {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    return '"' . str_replace('"', '""', $identifier) . '"';
}

function quoteString(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}
