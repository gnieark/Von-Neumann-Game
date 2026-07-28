<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\DetachedStorageContainerRepository;
use VonNeumannGame\Service\DetachedContainerJsonMigrationService;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(migrateDetachedContainersRun($argv));
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . migrateDetachedContainersUsage());
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to migrate detached containers: ' . $e->getMessage() . "\n");
    exit(2);
}

/**
 * @param array<int, string> $argv
 */
function migrateDetachedContainersRun(array $argv): int
{
    $options = migrateDetachedContainersParseArguments($argv);
    if ($options['help']) {
        echo migrateDetachedContainersUsage();
        return 0;
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $appConfig = $factory->appConfig();
    $universePath = migrateDetachedContainersAbsolutePath(
        $root,
        $options['universePath'] ?? (string) ($appConfig['universePath'] ?? 'data/universe'),
    );
    $pdo = $factory->pdo(
        $options['databaseConfig'],
        initializeSchema: !$options['dryRun'],
    );
    $migration = new DetachedContainerJsonMigrationService(
        $pdo,
        new DetachedStorageContainerRepository($pdo),
    );
    $progress = $options['quiet'] ? null : static function (int $processed, int $total): void {
        fwrite(STDERR, "Processed {$processed}/{$total} sector files.\n");
    };
    $result = $migration->migrate($universePath, $options['dryRun'], $progress);

    echo json_encode(
        ['dryRun' => $options['dryRun'], 'universePath' => $universePath] + $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";

    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{databaseConfig:?string,universePath:?string,dryRun:bool,quiet:bool,help:bool}
 */
function migrateDetachedContainersParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
        'universePath' => null,
        'dryRun' => false,
        'quiet' => false,
        'help' => false,
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
        } elseif ($argument === '--dry-run') {
            $options['dryRun'] = true;
        } elseif ($argument === '--quiet') {
            $options['quiet'] = true;
        } elseif (str_starts_with($argument, '--database-config=')) {
            $options['databaseConfig'] = migrateDetachedContainersNonEmpty(
                substr($argument, strlen('--database-config=')),
                '--database-config',
            );
        } elseif (str_starts_with($argument, '--universe-path=')) {
            $options['universePath'] = migrateDetachedContainersNonEmpty(
                substr($argument, strlen('--universe-path=')),
                '--universe-path',
            );
        } else {
            throw new InvalidArgumentException("Unknown argument: {$argument}");
        }
    }

    return $options;
}

function migrateDetachedContainersUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/migrate-detached-containers-to-database.php [options]

Options:
  --database-config=<path>  Database configuration (default: config/database.json)
  --universe-path=<path>    Universe root (default: app configuration)
  --dry-run                 Validate and report without writing SQL or JSON
  --quiet                   Do not print progress to STDERR
  -h, --help                Show this help

Run once immediately after deploying the SQL-backed detached-container code.
The migration is restart-safe: database upserts happen before each JSON file is
atomically rewritten without detached-container collections.

TEXT;
}

function migrateDetachedContainersNonEmpty(string $value, string $option): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException("Missing value for {$option}.");
    }

    return $value;
}

function migrateDetachedContainersAbsolutePath(string $root, string $path): string
{
    return str_starts_with($path, DIRECTORY_SEPARATOR)
        ? $path
        : rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}
