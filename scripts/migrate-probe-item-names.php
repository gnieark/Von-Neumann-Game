<?php

declare(strict_types=1);

use VonNeumannGame\Database\DatabaseConfig;
use VonNeumannGame\Database\DatabaseConnectionFactory;
use VonNeumannGame\Domain\ProbeItem;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(migrateProbeItemNamesRun($argv));
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . migrateProbeItemNamesUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to migrate probe item names: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function migrateProbeItemNamesRun(array $argv): int
{
    $options = migrateProbeItemNamesParseArguments($argv);
    if ($options['help']) {
        echo migrateProbeItemNamesUsage();

        return 0;
    }

    $root = __DIR__ . '/..';
    $configPath = migrateProbeItemNamesAbsolutePath($root, $options['databaseConfig'] ?? 'config/database.json');
    $config = DatabaseConfig::fromFile($configPath);
    $pdo = (new DatabaseConnectionFactory($config, $root))->create();

    $changes = migrateProbeItemNamesPlan($pdo);
    $total = array_sum(array_column($changes, 'count'));

    if ($options['dryRun']) {
        echo "Dry-run: {$total} probe item row(s) would be renamed to canonical English names.\n";
        migrateProbeItemNamesPrintPlan($changes);

        return 0;
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        foreach ($changes as $change) {
            migrateProbeItemNamesApply($pdo, $change['type'], $change['name']);
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

    echo "{$total} probe item row(s) renamed to canonical English names.\n";
    migrateProbeItemNamesPrintPlan($changes);

    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{databaseConfig:?string,dryRun:bool,help:bool}
 */
function migrateProbeItemNamesParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
        'dryRun' => false,
        'help' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $options['dryRun'] = true;
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

function migrateProbeItemNamesUsage(): string
{
    return <<<TXT
Usage:
  php scripts/migrate-probe-item-names.php [--database-config=config/database.json] [--dry-run]

Renames existing probe_items rows for known item types to their canonical
English API names. Run this once after deploying the API item-name
normalization.

TXT;
}

function migrateProbeItemNamesAbsolutePath(string $root, string $path): string
{
    if ($path !== '' && $path[0] === '/') {
        return $path;
    }

    return $root . DIRECTORY_SEPARATOR . $path;
}

/**
 * @return list<array{type:string,name:string,count:int}>
 */
function migrateProbeItemNamesPlan(PDO $pdo): array
{
    $count = $pdo->prepare('SELECT COUNT(*) FROM probe_items WHERE type = :type AND name <> :name');
    $changes = [];
    foreach (ProbeItem::canonicalNames() as $type => $name) {
        $count->execute([
            'type' => $type,
            'name' => $name,
        ]);
        $affected = (int) $count->fetchColumn();
        if ($affected > 0) {
            $changes[] = [
                'type' => $type,
                'name' => $name,
                'count' => $affected,
            ];
        }
    }

    return $changes;
}

function migrateProbeItemNamesApply(PDO $pdo, string $type, string $name): void
{
    $stmt = $pdo->prepare('UPDATE probe_items SET name = :canonical_name, updated_at = :updated_at WHERE type = :type AND name <> :comparison_name');
    $stmt->execute([
        'type' => $type,
        'canonical_name' => $name,
        'comparison_name' => $name,
        'updated_at' => gmdate('c'),
    ]);
}

/**
 * @param list<array{type:string,name:string,count:int}> $changes
 */
function migrateProbeItemNamesPrintPlan(array $changes): void
{
    if ($changes === []) {
        echo "- no probe item rows need renaming\n";
        return;
    }

    foreach ($changes as $change) {
        echo "- {$change['type']}: {$change['count']} row(s) -> {$change['name']}\n";
    }
}
