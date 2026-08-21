<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\ResourceComposition;

require_once __DIR__ . '/../../vendor/autoload.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(cleanupLegacyResourcesMain($argv));
}

/** @param array<int, string> $arguments */
function cleanupLegacyResourcesMain(array $arguments): int
{
    $databaseConfig = null;
    $universePath = null;
    $dryRun = false;
    $databaseOnly = false;
    $sectorsOnly = false;
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--dry-run') {
            $dryRun = true;
        } elseif ($argument === '--database-only') {
            $databaseOnly = true;
        } elseif ($argument === '--sectors-only') {
            $sectorsOnly = true;
        } elseif (str_starts_with($argument, '--database-config=')) {
            $databaseConfig = substr($argument, strlen('--database-config='));
        } elseif (str_starts_with($argument, '--universe-path=')) {
            $universePath = substr($argument, strlen('--universe-path='));
        } elseif ($argument === '--help' || $argument === '-h') {
            echo "Usage: php scripts/one-shot-scripts/cleanup-legacy-resources.php [--dry-run] [--database-only|--sectors-only] [--database-config=PATH] [--universe-path=PATH]\n";
            echo "The application, scheduler and workers must be stopped while the migration writes.\n";

            return 0;
        } else {
            fwrite(STDERR, "Unknown argument: {$argument}\n");

            return 2;
        }
    }
    if ($databaseOnly && $sectorsOnly) {
        fwrite(STDERR, "--database-only and --sectors-only cannot be combined.\n");

        return 2;
    }

    try {
        $root = dirname(__DIR__, 2);
        $factory = new AppFactory($root);
        if (!$sectorsOnly) {
            $pdo = $factory->pdo($databaseConfig, initializeSchema: false);
            $database = cleanupLegacyOtherDatabase($pdo, $dryRun);
            printf(
                "Legacy other database: probe_rows=%d manny_rows=%d manny_payloads=%d scheduled_payloads=%d filter_payloads=%d resource_rows=%d resource_rules=%d task_projections=%d columns_dropped=%d dry_run=%s\n",
                $database['probeRows'],
                $database['mannyRows'],
                $database['mannyPayloads'],
                $database['scheduledPayloads'],
                $database['filterPayloads'],
                $database['resourceRows'],
                $database['resourceRules'],
                $database['taskProjections'],
                $database['columnsDropped'],
                $dryRun ? 'yes' : 'no',
            );
        }

        if (!$databaseOnly) {
            $appConfig = $factory->appConfig();
            $configuredPath = $universePath ?? (string) ($appConfig['universePath'] ?? 'data/universe');
            $absolutePath = str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
                ? $configuredPath
                : $root . DIRECTORY_SEPARATOR . $configuredPath;
            $sectors = cleanupLegacyOtherSectorFiles($absolutePath, $dryRun);
            printf(
                "Legacy other sectors: files_scanned=%d files_changed=%d resource_maps=%d asteroid_maps=%d dry_run=%s\n",
                $sectors['filesScanned'],
                $sectors['filesChanged'],
                $sectors['resourceMaps'],
                $sectors['asteroidMaps'],
                $dryRun ? 'yes' : 'no',
            );
        }

        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Legacy other resource migration failed: ' . $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * @return array{probeRows:int,mannyRows:int,mannyPayloads:int,scheduledPayloads:int,filterPayloads:int,resourceRows:int,resourceRules:int,taskProjections:int,columnsDropped:int}
 */
function cleanupLegacyOtherDatabase(PDO $pdo, bool $dryRun): array
{
    $report = [
        'probeRows' => 0,
        'mannyRows' => 0,
        'mannyPayloads' => 0,
        'scheduledPayloads' => 0,
        'filterPayloads' => 0,
        'resourceRows' => 0,
        'resourceRules' => 0,
        'taskProjections' => 0,
        'columnsDropped' => 0,
    ];
    if (!legacyOtherTableExists($pdo, 'mannies')) {
        throw new RuntimeException('Required table mannies is absent.');
    }

    $probeOther = legacyOtherColumnExists($pdo, 'neumann_probes', 'other_stock');
    if ($probeOther) {
        if (!legacyOtherColumnExists($pdo, 'neumann_probes', 'organic_compounds_stock')) {
            throw new RuntimeException('neumann_probes.other_stock exists without organic_compounds_stock; run the historical resource-stock migration first.');
        }
        $report['probeRows'] = legacyOtherCount($pdo, 'SELECT COUNT(*) FROM neumann_probes WHERE ABS(other_stock) > 0.00001');
    }

    $mannyOther = legacyOtherColumnExists($pdo, 'mannies', 'cargo_other');
    $mannyRows = [];
    if ($mannyOther) {
        $columns = ['id', 'cargo_other'];
        foreach (['cargo_ice', 'cargo_organic_compounds', 'task_payload_json', 'task_scheduled_event_id'] as $column) {
            if (legacyOtherColumnExists($pdo, 'mannies', $column)) {
                $columns[] = $column;
            }
        }
        $mannyRows = $pdo->query('SELECT ' . implode(', ', $columns) . ' FROM mannies ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $report['mannyRows'] = count(array_filter(
            $mannyRows,
            static fn(array $row): bool => abs((float) ($row['cargo_other'] ?? 0.0)) > 0.00001,
        ));
    }

    $mannyPayloadUpdates = legacyOtherJsonUpdates($pdo, 'mannies', 'task_payload_json');
    $scheduledPayloadUpdates = legacyOtherJsonUpdates($pdo, 'scheduled_events', 'payload_json');
    $filterUpdates = [];
    foreach (['priority_filter_json', 'exclusion_filter_json', 'strict_exclusion_filter_json'] as $column) {
        foreach (legacyOtherJsonUpdates($pdo, 'storage_containers', $column, true) as $update) {
            $filterUpdates[] = ['column' => $column] + $update;
        }
    }
    $report['mannyPayloads'] = count($mannyPayloadUpdates);
    $report['scheduledPayloads'] = count($scheduledPayloadUpdates);
    $report['filterPayloads'] = count($filterUpdates);

    $amountTables = [
        ['storage_container_resources', 'container_id'],
        ['detached_storage_container_resources', 'container_object_id'],
    ];
    foreach ($amountTables as [$table, $ownerColumn]) {
        if (legacyOtherColumnExists($pdo, $table, 'resource_type')) {
            $report['resourceRows'] += legacyOtherCount(
                $pdo,
                "SELECT COUNT(*) FROM {$table} WHERE resource_type = 'other'",
            );
        }
    }
    if (legacyOtherColumnExists($pdo, 'detached_storage_container_resource_rules', 'resource_type')) {
        $report['resourceRules'] = legacyOtherCount(
            $pdo,
            "SELECT COUNT(*) FROM detached_storage_container_resource_rules WHERE resource_type = 'other'",
        );
    }
    if (legacyOtherColumnExists($pdo, 'manny_tasks', 'resource_type')) {
        $report['taskProjections'] = legacyOtherCount($pdo, "SELECT COUNT(*) FROM manny_tasks WHERE resource_type = 'other'");
    }

    if ($dryRun) {
        return $report;
    }

    $transactionalDdl = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($transactionalDdl) {
        $pdo->beginTransaction();
    }
    try {
        if ($probeOther) {
            $nonNegativeOther = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'MAX(other_stock, 0)'
                : 'GREATEST(other_stock, 0)';
            $pdo->exec("UPDATE neumann_probes SET organic_compounds_stock = organic_compounds_stock + {$nonNegativeOther} WHERE ABS(other_stock) > 0.00001");
            legacyOtherDropColumn($pdo, 'neumann_probes', 'other_stock');
            $report['columnsDropped']++;
        }

        if ($mannyOther) {
            if (!legacyOtherColumnExists($pdo, 'mannies', 'cargo_ice')) {
                legacyOtherAddMannyCargoColumn($pdo, 'cargo_ice');
            }
            if (!legacyOtherColumnExists($pdo, 'mannies', 'cargo_organic_compounds')) {
                legacyOtherAddMannyCargoColumn($pdo, 'cargo_organic_compounds');
            }
            $eventPayloads = legacyOtherScheduledPayloadsById($pdo, $mannyRows);
            $updateCargo = $pdo->prepare(
                'UPDATE mannies SET cargo_ice = :ice, cargo_organic_compounds = :organic WHERE id = :id'
            );
            foreach ($mannyRows as $row) {
                $payload = [];
                $eventId = isset($row['task_scheduled_event_id']) ? (int) $row['task_scheduled_event_id'] : 0;
                $payloadJson = $eventPayloads[$eventId] ?? ($row['task_payload_json'] ?? '{}');
                if (is_string($payloadJson) && $payloadJson !== '') {
                    $decoded = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
                    $payload = is_array($decoded) ? $decoded : [];
                }
                $cargo = legacyOtherMannyCargoAmounts($row, $payload);
                $updateCargo->execute(['id' => (int) $row['id'], 'ice' => $cargo['ice'], 'organic' => $cargo['organic']]);
            }
            legacyOtherDropColumn($pdo, 'mannies', 'cargo_other');
            $report['columnsDropped']++;
        }

        legacyOtherApplyJsonUpdates($pdo, 'mannies', 'task_payload_json', $mannyPayloadUpdates);
        legacyOtherApplyJsonUpdates($pdo, 'scheduled_events', 'payload_json', $scheduledPayloadUpdates);
        foreach ($filterUpdates as $update) {
            legacyOtherApplyJsonUpdates($pdo, 'storage_containers', (string) $update['column'], [$update]);
        }
        foreach ($amountTables as [$table, $ownerColumn]) {
            legacyOtherMergeResourceRows($pdo, $table, $ownerColumn);
        }
        legacyOtherMigrateRules($pdo);
        if (legacyOtherColumnExists($pdo, 'manny_tasks', 'resource_type')) {
            $pdo->exec("UPDATE manny_tasks SET resource_type = 'carbon_compounds' WHERE resource_type = 'other'");
        }
        if ($transactionalDdl) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return $report;
}

/** @return array<int, array{id:int,payload:string}> */
function legacyOtherJsonUpdates(PDO $pdo, string $table, string $column, bool $replaceEveryString = false): array
{
    if (!legacyOtherColumnExists($pdo, $table, $column)) {
        return [];
    }
    $statement = $pdo->prepare("SELECT id, {$column} FROM {$table} WHERE {$column} LIKE :needle ORDER BY id");
    $statement->execute(['needle' => '%other%']);
    $updates = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = json_decode((string) $row[$column], true, 512, JSON_THROW_ON_ERROR);
        $stats = ['resourceMaps' => 0];
        $changed = $replaceEveryString
            ? legacyOtherReplaceEveryString($payload)
            : migrateLegacyOtherResource($payload, null, $stats);
        if ($changed) {
            $updates[] = [
                'id' => (int) $row['id'],
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
        }
    }

    return $updates;
}

/** @param array<int, array{id:int,payload:string}> $updates */
function legacyOtherApplyJsonUpdates(PDO $pdo, string $table, string $column, array $updates): void
{
    if ($updates === [] || !legacyOtherColumnExists($pdo, $table, $column)) {
        return;
    }
    $update = $pdo->prepare("UPDATE {$table} SET {$column} = :payload WHERE id = :id");
    foreach ($updates as $row) {
        $update->execute(['id' => $row['id'], 'payload' => $row['payload']]);
    }
}

/** @param array<int, array<string, mixed>> $mannyRows @return array<int, string> */
function legacyOtherScheduledPayloadsById(PDO $pdo, array $mannyRows): array
{
    if (!legacyOtherColumnExists($pdo, 'scheduled_events', 'payload_json')) {
        return [];
    }
    $ids = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int) ($row['task_scheduled_event_id'] ?? 0),
        $mannyRows,
    ))));
    if ($ids === []) {
        return [];
    }
    $rows = $pdo->query('SELECT id, payload_json FROM scheduled_events WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC);

    return array_column($rows, 'payload_json', 'id');
}

/** @param array<string, mixed> $row @param array<string, mixed> $payload @return array{ice:float,organic:float} */
function legacyOtherMannyCargoAmounts(array $row, array $payload): array
{
    $ice = round(max(0.0, (float) ($row['cargo_ice'] ?? 0.0)), 4);
    $organic = round(max(0.0, (float) ($row['cargo_organic_compounds'] ?? 0.0)), 4);
    $other = round(max(0.0, (float) ($row['cargo_other'] ?? 0.0)), 4);
    if ($ice + $organic > 0.0 || $other <= 0.0) {
        return ['ice' => $ice, 'organic' => $organic];
    }
    $profile = is_array($payload['resourceProfile'] ?? null) ? $payload['resourceProfile'] : [];
    $iceShare = max(0.0, (float) ($profile['ice'] ?? 0.0));
    $organicShare = max(0.0, (float) ($profile['carbon_compounds'] ?? 0.0))
        + max(0.0, (float) ($profile['other'] ?? 0.0));
    $total = $iceShare + $organicShare;
    if ($total <= 0.0) {
        $resourceType = strtolower(str_replace(['-', ' '], '_', (string) ($payload['resourceType'] ?? '')));

        return $resourceType === 'ice' || $resourceType === 'water' || $resourceType === 'water_ice'
            ? ['ice' => $other, 'organic' => 0.0]
            : ['ice' => 0.0, 'organic' => $other];
    }
    $ice = round($other * ($iceShare / $total), 4);

    return ['ice' => $ice, 'organic' => round($other - $ice, 4)];
}

function legacyOtherMergeResourceRows(PDO $pdo, string $table, string $ownerColumn): void
{
    if (!legacyOtherColumnExists($pdo, $table, 'resource_type')) {
        return;
    }
    $rows = $pdo->query("SELECT {$ownerColumn}, amount FROM {$table} WHERE resource_type = 'other'")->fetchAll(PDO::FETCH_ASSOC);
    $find = $pdo->prepare("SELECT amount FROM {$table} WHERE {$ownerColumn} = :owner AND resource_type = 'carbon_compounds'");
    $update = $pdo->prepare("UPDATE {$table} SET amount = :amount WHERE {$ownerColumn} = :owner AND resource_type = 'carbon_compounds'");
    $rename = $pdo->prepare("UPDATE {$table} SET resource_type = 'carbon_compounds', amount = :amount WHERE {$ownerColumn} = :owner AND resource_type = 'other'");
    $delete = $pdo->prepare("DELETE FROM {$table} WHERE {$ownerColumn} = :owner AND resource_type = 'other'");
    foreach ($rows as $row) {
        $owner = $row[$ownerColumn];
        $find->execute(['owner' => $owner]);
        $existing = $find->fetchColumn();
        $legacyAmount = max(0.0, (float) $row['amount']);
        if ($existing === false) {
            $rename->execute(['owner' => $owner, 'amount' => $legacyAmount]);
        } else {
            $update->execute(['owner' => $owner, 'amount' => round(max(0.0, (float) $existing) + $legacyAmount, 4)]);
            $delete->execute(['owner' => $owner]);
        }
    }
}

function legacyOtherMigrateRules(PDO $pdo): void
{
    $table = 'detached_storage_container_resource_rules';
    if (!legacyOtherColumnExists($pdo, $table, 'resource_type')) {
        return;
    }
    $rows = $pdo->query("SELECT container_object_id, rule_kind FROM {$table} WHERE resource_type = 'other'")->fetchAll(PDO::FETCH_ASSOC);
    $exists = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE container_object_id = :container AND rule_kind = :kind AND resource_type = 'carbon_compounds'");
    $rename = $pdo->prepare("UPDATE {$table} SET resource_type = 'carbon_compounds' WHERE container_object_id = :container AND rule_kind = :kind AND resource_type = 'other'");
    $delete = $pdo->prepare("DELETE FROM {$table} WHERE container_object_id = :container AND rule_kind = :kind AND resource_type = 'other'");
    foreach ($rows as $row) {
        $params = ['container' => $row['container_object_id'], 'kind' => $row['rule_kind']];
        $exists->execute($params);
        ((int) $exists->fetchColumn() > 0 ? $delete : $rename)->execute($params);
    }
}

/** @return array{filesScanned:int,filesChanged:int,resourceMaps:int,asteroidMaps:int} */
function cleanupLegacyOtherSectorFiles(string $universePath, bool $dryRun): array
{
    $report = ['filesScanned' => 0, 'filesChanged' => 0, 'resourceMaps' => 0, 'asteroidMaps' => 0];
    $sectorsPath = rtrim($universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
    if (!is_dir($sectorsPath)) {
        throw new RuntimeException("Sector directory not found: {$sectorsPath}");
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sectorsPath, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'json') {
            continue;
        }
        $report['filesScanned']++;
        $json = file_get_contents($file->getPathname());
        if ($json === false) {
            throw new RuntimeException('Unable to read ' . $file->getPathname());
        }
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $stats = ['resourceMaps' => 0, 'asteroidMaps' => 0];
        $changed = migrateLegacyAsteroidResources($payload, $stats);
        $changed = migrateLegacyOtherResource($payload, null, $stats) || $changed;
        if (!$changed) {
            continue;
        }
        $report['filesChanged']++;
        $report['resourceMaps'] += $stats['resourceMaps'];
        $report['asteroidMaps'] += $stats['asteroidMaps'];
        if (!$dryRun) {
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temporary = $file->getPathname() . '.tmp.' . bin2hex(random_bytes(6));
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $file->getPathname())) {
                @unlink($temporary);
                throw new RuntimeException('Unable to replace ' . $file->getPathname());
            }
        }
    }

    return $report;
}

/** @param array{resourceMaps:int,asteroidMaps:int} $stats */
function migrateLegacyAsteroidResources(mixed &$value, array &$stats): bool
{
    if (!is_array($value)) {
        return false;
    }
    $changed = false;
    if (($value['type'] ?? null) === 'asteroid' && is_array($value['resourceAmounts'] ?? null) && array_key_exists('other', $value['resourceAmounts'])) {
        $amounts = $value['resourceAmounts'];
        $total = max(0.0, (float) ($amounts['other'] ?? 0.0));
        foreach (ResourceComposition::TYPES as $type) {
            $total += max(0.0, (float) ($amounts[$type] ?? 0.0));
        }
        $value['resourceAmounts'] = legacyOtherAmountsForTotal($total, ResourceComposition::fromHints((array) ($value['estimatedResources'] ?? [])));
        $stats['asteroidMaps']++;
        $changed = true;
    }
    foreach ($value as &$child) {
        $changed = migrateLegacyAsteroidResources($child, $stats) || $changed;
    }
    unset($child);

    return $changed;
}

/** @param array{resourceMaps:int,asteroidMaps?:int} $stats */
function migrateLegacyOtherResource(mixed &$value, ?string $key, array &$stats): bool
{
    if (is_string($value) && in_array($key, ['resourceType', 'reservedCargoType'], true) && $value === 'other') {
        $value = 'carbon_compounds';

        return true;
    }
    if (!is_array($value)) {
        return false;
    }
    $changed = false;
    if ($key === 'cargo' && (array_key_exists('other', $value) || array_key_exists('organic_compounds', $value) || array_key_exists('carbon_compounds', $value))) {
        $value['organicCompounds'] = round(
            (float) ($value['organicCompounds'] ?? $value['organic_compounds'] ?? $value['carbon_compounds'] ?? 0.0)
            + (float) ($value['other'] ?? 0.0),
            4,
        );
        unset($value['other'], $value['organic_compounds'], $value['carbon_compounds']);
        $stats['resourceMaps']++;
        $changed = true;
    }
    if (in_array($key, ['resourceAmounts', 'resources', 'resourceComposition', 'resourceProfile', 'extractedResources', 'depositedResources'], true) && array_key_exists('other', $value)) {
        $value['carbon_compounds'] = round((float) ($value['carbon_compounds'] ?? 0.0) + (float) $value['other'], 4);
        unset($value['other']);
        $stats['resourceMaps']++;
        $changed = true;
    }
    if (in_array($key, ['resourceTypes', 'availableResources'], true) && array_is_list($value)) {
        foreach ($value as &$item) {
            if ($item === 'other') {
                $item = 'carbon_compounds';
                $changed = true;
            }
        }
        unset($item);
        if ($changed) {
            $value = array_values(array_unique($value));
        }
    }
    foreach ($value as $childKey => &$child) {
        $changed = migrateLegacyOtherResource($child, is_string($childKey) ? $childKey : null, $stats) || $changed;
    }
    unset($child);

    return $changed;
}

function legacyOtherReplaceEveryString(mixed &$value): bool
{
    if (is_string($value)) {
        if ($value !== 'other') {
            return false;
        }
        $value = 'carbon_compounds';

        return true;
    }
    if (!is_array($value)) {
        return false;
    }
    $changed = false;
    foreach ($value as &$child) {
        $changed = legacyOtherReplaceEveryString($child) || $changed;
    }
    unset($child);

    return $changed;
}

/** @param array<string, float> $composition @return array<string, float> */
function legacyOtherAmountsForTotal(float $amount, array $composition): array
{
    $amounts = array_fill_keys(ResourceComposition::TYPES, 0.0);
    $types = array_values(array_filter(ResourceComposition::TYPES, static fn(string $type): bool => ($composition[$type] ?? 0.0) > 0.0));
    $remaining = round(max(0.0, $amount), 4);
    foreach ($types as $index => $type) {
        $amounts[$type] = $index === count($types) - 1
            ? $remaining
            : round($amount * (float) $composition[$type], 4);
        $remaining = round($remaining - $amounts[$type], 4);
    }

    return $amounts;
}

function legacyOtherTableExists(PDO $pdo, string $table): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $statement->execute(['table' => $table]);

    return (int) $statement->fetchColumn() === 1;
}

function legacyOtherColumnExists(PDO $pdo, string $table, string $column): bool
{
    if (!legacyOtherTableExists($pdo, $table)) {
        return false;
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return in_array($column, array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
    $statement->execute(['table' => $table, 'column' => $column]);

    return (int) $statement->fetchColumn() === 1;
}

function legacyOtherCount(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

function legacyOtherDropColumn(PDO $pdo, string $table, string $column): void
{
    $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
}

function legacyOtherAddMannyCargoColumn(PDO $pdo, string $column): void
{
    $type = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'REAL' : 'DOUBLE';
    $pdo->exec("ALTER TABLE mannies ADD COLUMN {$column} {$type} NOT NULL DEFAULT 0");
}
