<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$options = parseOptions($argv);
if ($options['help']) {
    echo usage();
    exit(0);
}

$root = dirname(__DIR__);
$factory = new AppFactory($root);
$dryRun = $options['dryRun'];

if (!$options['sectorsOnly']) {
    $pdo = $factory->pdo(initializeSchema: true);
    $stats = cleanupDatabase($pdo, $dryRun);

    echo ($dryRun ? '[dry-run] ' : '') . "Database cleanup:\n";
    echo '- probe other_stock rows: ' . $stats['probeRows'] . ' (' . formatAmount($stats['probeAmount']) . " containers)\n";
    echo '- Manny cargo_other rows: ' . $stats['mannyRows'] . ' (' . formatAmount($stats['mannyAmount']) . " containers)\n";
    echo '- Manny task payloads migrated: ' . $stats['payloadRows'] . "\n";
}

if (!$options['databaseOnly']) {
    $appConfig = $factory->appConfig();
    $universePath = $options['universePath']
        ?? absolutePath($root, (string) ($appConfig['universePath'] ?? 'data/universe'));
    $stats = cleanupSectorFiles($universePath, $dryRun);

    echo ($dryRun ? '[dry-run] ' : '') . "Sector JSON cleanup:\n";
    echo '- files scanned: ' . $stats['filesScanned'] . "\n";
    echo '- files updated: ' . $stats['filesUpdated'] . "\n";
    echo '- resource maps migrated: ' . $stats['resourceMapsMigrated'] . "\n";
}

/**
 * @param array<int, string> $argv
 * @return array{dryRun: bool, databaseOnly: bool, sectorsOnly: bool, universePath: ?string, help: bool}
 */
function parseOptions(array $argv): array
{
    $options = [
        'dryRun' => false,
        'databaseOnly' => false,
        'sectorsOnly' => false,
        'universePath' => null,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }
        if ($arg === '--database-only') {
            $options['databaseOnly'] = true;
            continue;
        }
        if ($arg === '--sectors-only') {
            $options['sectorsOnly'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if (str_starts_with($arg, '--universe=')) {
            $options['universePath'] = substr($arg, strlen('--universe='));
            continue;
        }

        fwrite(STDERR, "Unknown option: $arg\n\n" . usage());
        exit(1);
    }

    if ($options['databaseOnly'] && $options['sectorsOnly']) {
        fwrite(STDERR, "--database-only and --sectors-only cannot be combined.\n\n" . usage());
        exit(1);
    }

    return $options;
}

function usage(): string
{
    return <<<TEXT
Usage: php scripts/cleanup-legacy-resources.php [--dry-run] [--database-only|--sectors-only] [--universe=path]

Removes legacy probe/Manny inventory "other" amounts from the database and rewrites
legacy sector JSON resourceAmounts.other into resourceAmounts.carbon_compounds.

TEXT;
}

/**
 * @return array{probeRows: int, probeAmount: float, mannyRows: int, mannyAmount: float, payloadRows: int}
 */
function cleanupDatabase(PDO $pdo, bool $dryRun): array
{
    $stats = [
        'probeRows' => countRows($pdo, 'SELECT COUNT(*) FROM neumann_probes WHERE other_stock <> 0'),
        'probeAmount' => sumAmount($pdo, 'SELECT COALESCE(SUM(other_stock), 0) FROM neumann_probes WHERE other_stock <> 0'),
        'mannyRows' => countRows($pdo, 'SELECT COUNT(*) FROM mannies WHERE cargo_other <> 0'),
        'mannyAmount' => sumAmount($pdo, 'SELECT COALESCE(SUM(cargo_other), 0) FROM mannies WHERE cargo_other <> 0'),
        'payloadRows' => 0,
    ];

    $payloadUpdates = mannyTaskPayloadUpdates($pdo);
    $stats['payloadRows'] = count($payloadUpdates);

    if ($dryRun) {
        return $stats;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE neumann_probes SET other_stock = 0 WHERE other_stock <> 0');
        $pdo->exec('UPDATE mannies SET cargo_other = 0 WHERE cargo_other <> 0');

        if ($payloadUpdates !== []) {
            $update = $pdo->prepare('UPDATE mannies SET task_payload_json = :payload WHERE id = :id');
            foreach ($payloadUpdates as $row) {
                $update->execute([
                    'id' => $row['id'],
                    'payload' => $row['payload'],
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $stats;
}

function countRows(PDO $pdo, string $query): int
{
    $value = $pdo->query($query)?->fetchColumn();

    return (int) ($value ?: 0);
}

function sumAmount(PDO $pdo, string $query): float
{
    $value = $pdo->query($query)?->fetchColumn();

    return round((float) ($value ?: 0), 4);
}

/**
 * @return array<int, array{id: int, payload: string}>
 */
function mannyTaskPayloadUpdates(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, task_payload_json FROM mannies WHERE task_payload_json LIKE '%other%'");
    if ($stmt === false) {
        return [];
    }

    $updates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = json_decode((string) $row['task_payload_json'], true);
        if (!is_array($payload)) {
            continue;
        }

        $stats = ['resourceMapsMigrated' => 0];
        if (!migrateLegacyOtherResource($payload, null, $stats)) {
            continue;
        }

        $updates[] = [
            'id' => (int) $row['id'],
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    return $updates;
}

/**
 * @return array{filesScanned: int, filesUpdated: int, resourceMapsMigrated: int}
 */
function cleanupSectorFiles(string $universePath, bool $dryRun): array
{
    $stats = [
        'filesScanned' => 0,
        'filesUpdated' => 0,
        'resourceMapsMigrated' => 0,
    ];
    $sectorsPath = rtrim($universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
    if (!is_dir($sectorsPath)) {
        return $stats;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sectorsPath, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'json') {
            continue;
        }

        $stats['filesScanned']++;
        $path = $file->getPathname();
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read sector file '$path'.");
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            continue;
        }

        $fileStats = ['resourceMapsMigrated' => 0];
        if (!migrateLegacyOtherResource($data, null, $fileStats)) {
            continue;
        }

        $stats['filesUpdated']++;
        $stats['resourceMapsMigrated'] += $fileStats['resourceMapsMigrated'];
        if ($dryRun) {
            continue;
        }

        $updatedJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporaryPath, $updatedJson, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write temporary sector file '$temporaryPath'.");
        }
        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to replace sector file '$path'.");
        }
    }

    return $stats;
}

/**
 * @param mixed $value
 * @param array{resourceMapsMigrated: int} $stats
 */
function migrateLegacyOtherResource(mixed &$value, ?string $key, array &$stats): bool
{
    $changed = false;

    if (is_string($value) && $key === 'resourceType' && $value === 'other') {
        $value = 'carbon_compounds';

        return true;
    }

    if (!is_array($value)) {
        return false;
    }

    if (in_array($key, ['resourceAmounts', 'resourceComposition', 'resourceProfile', 'extractedResources', 'depositedResources'], true)) {
        if (array_key_exists('other', $value)) {
            $value['carbon_compounds'] = round(
                (float) ($value['carbon_compounds'] ?? 0)
                + (float) $value['other'],
                4,
            );
            unset($value['other']);
            $stats['resourceMapsMigrated']++;
            $changed = true;
        }
    }

    if (in_array($key, ['resourceTypes', 'availableResources'], true) && array_is_list($value)) {
        foreach ($value as $index => $item) {
            if ($item === 'other') {
                $value[$index] = 'carbon_compounds';
                $changed = true;
            }
        }
        if ($changed) {
            $value = array_values(array_unique($value));
        }
    }

    foreach ($value as $childKey => &$childValue) {
        if (migrateLegacyOtherResource($childValue, is_string($childKey) ? $childKey : null, $stats)) {
            $changed = true;
        }
    }
    unset($childValue);

    return $changed;
}

function absolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

function formatAmount(float $amount): string
{
    return rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.');
}
