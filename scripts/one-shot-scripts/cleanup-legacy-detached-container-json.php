<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(cleanupLegacyDetachedContainerJsonMain($argv));
}

/** @param array<int, string> $arguments */
function cleanupLegacyDetachedContainerJsonMain(array $arguments): int
{
    $universePath = null;
    $dryRun = false;
    $quiet = false;
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--dry-run') {
            $dryRun = true;
        } elseif ($argument === '--quiet') {
            $quiet = true;
        } elseif (str_starts_with($argument, '--universe-path=')) {
            $universePath = trim(substr($argument, strlen('--universe-path=')));
            if ($universePath === '') {
                fwrite(STDERR, "--universe-path cannot be empty.\n");

                return 2;
            }
        } elseif ($argument === '--help' || $argument === '-h') {
            echo cleanupLegacyDetachedContainerJsonUsage();

            return 0;
        } else {
            fwrite(STDERR, "Unknown argument: {$argument}\n\n" . cleanupLegacyDetachedContainerJsonUsage());

            return 2;
        }
    }

    try {
        $root = dirname(__DIR__, 2);
        $factory = new AppFactory($root);
        $appConfig = $factory->appConfig();
        $configuredPath = $universePath ?? (string) ($appConfig['universePath'] ?? 'data/universe');
        $absolutePath = str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            ? $configuredPath
            : $root . DIRECTORY_SEPARATOR . $configuredPath;
        $progress = $quiet ? null : static function (int $processed, int $total): void {
            if ($processed % 1000 === 0 || $processed === $total) {
                fwrite(STDERR, "Processed {$processed}/{$total} sector files.\n");
            }
        };
        $report = cleanupLegacyDetachedContainerJson($absolutePath, $dryRun, $progress);
        echo json_encode(
            ['dryRun' => $dryRun, 'universePath' => $absolutePath] + $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";

        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Legacy detached-container JSON cleanup failed: ' . $error->getMessage() . "\n");

        return 1;
    }
}

function cleanupLegacyDetachedContainerJsonUsage(): string
{
    return <<<TEXT
Usage: php scripts/one-shot-scripts/cleanup-legacy-detached-container-json.php [--dry-run] [--universe-path=PATH] [--quiet]

Removes obsolete detached-container collections and detached_container objects
from sector JSON files. It never reads or writes the database; run it only after
the detached containers have already been migrated to SQL.

TEXT;
}

/**
 * @param null|callable(int, int):void $progress
 * @return array{filesScanned:int,filesChanged:int,collectionsRemoved:int,collectionEntriesRemoved:int,objectReferencesRemoved:int}
 */
function cleanupLegacyDetachedContainerJson(string $universePath, bool $dryRun = false, ?callable $progress = null): array
{
    $sectorDirectory = rtrim($universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
    if (!is_dir($sectorDirectory)) {
        throw new RuntimeException("Sector directory not found: {$sectorDirectory}");
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sectorDirectory, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'json') {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);

    $report = [
        'filesScanned' => 0,
        'filesChanged' => 0,
        'collectionsRemoved' => 0,
        'collectionEntriesRemoved' => 0,
        'objectReferencesRemoved' => 0,
    ];
    $collections = ['detachedContainers', 'hiddenDetachedContainers', 'planetDroppedContainers'];
    foreach ($files as $file) {
        $json = file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException("Unable to read sector file: {$file}");
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("Sector JSON root must be an object: {$file}");
        }

        $changed = false;
        foreach ($collections as $collection) {
            if (!array_key_exists($collection, $data)) {
                continue;
            }
            $report['collectionsRemoved']++;
            if (is_array($data[$collection])) {
                $report['collectionEntriesRemoved'] += count($data[$collection]);
            }
            unset($data[$collection]);
            $changed = true;
        }

        if (array_key_exists('objects', $data) && !is_array($data['objects'])) {
            throw new RuntimeException("Sector objects must be an array: {$file}");
        }
        if (is_array($data['objects'] ?? null)) {
            $objects = [];
            foreach ($data['objects'] as $object) {
                if (is_array($object) && ($object['type'] ?? null) === 'detached_container') {
                    $report['objectReferencesRemoved']++;
                    $changed = true;
                    continue;
                }
                $objects[] = $object;
            }
            $data['objects'] = $objects;
        }

        $report['filesScanned']++;
        if ($changed) {
            $report['filesChanged']++;
            if (!$dryRun) {
                cleanupLegacyDetachedContainerJsonRewrite($file, $data);
            }
        }
        if ($progress !== null) {
            $progress($report['filesScanned'], count($files));
        }
    }

    return $report;
}

/** @param array<string, mixed> $data */
function cleanupLegacyDetachedContainerJsonRewrite(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    $temporary = $file . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write temporary sector file: {$temporary}");
    }
    if (!rename($temporary, $file)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to replace sector file: {$file}");
    }
}
