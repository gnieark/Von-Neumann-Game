<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\SectorContent;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = backfillDormantConstructsParseArguments($argv);
    if ($options['help']) {
        echo backfillDormantConstructsUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $appConfig = $factory->appConfig();
    $worldSeed = (string) ($appConfig['worldSeed'] ?? 'default-world');
    $universePath = backfillDormantConstructsAbsolutePath(
        $root,
        $options['universePath'] ?? (string) ($appConfig['universePath'] ?? 'data/universe'),
    );
    $sectorDirectory = rtrim($universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';
    if (!is_dir($sectorDirectory)) {
        echo 'No sector directory found: ' . $sectorDirectory . "\n";
        exit(0);
    }

    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);
    $visitedKeys = backfillDormantConstructsVisitedKeys($pdo);
    $stats = [
        'files' => 0,
        'visitedSkipped' => 0,
        'alreadyPresent' => 0,
        'rollSkipped' => 0,
        'added' => 0,
    ];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sectorDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'json') {
            continue;
        }

        $stats['files']++;
        $json = file_get_contents($file->getPathname());
        if ($json === false) {
            throw new RuntimeException('Unable to read sector file: ' . $file->getPathname());
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid sector JSON root: ' . $file->getPathname());
        }

        $sector = SectorContent::fromArray($data, 'loaded');
        $sectorKey = $sector->getCoordinates()->toKey();
        if (isset($visitedKeys[$sectorKey])) {
            $stats['visitedSkipped']++;
            continue;
        }
        if (backfillDormantConstructsHasConstruct($sector)) {
            $stats['alreadyPresent']++;
            continue;
        }
        if (random_int(1, $options['chanceDenominator']) !== 1) {
            $stats['rollSkipped']++;
            continue;
        }

        $sector->addObject(new DormantConstruct(DormantConstruct::objectIdForSector($sector->getCoordinates(), $worldSeed)));
        $stats['added']++;
        if (!$options['dryRun']) {
            $json = json_encode($sector->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($file->getPathname(), $json, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write sector file: ' . $file->getPathname());
            }
        }
    }

    echo ($options['dryRun'] ? '[dry-run] ' : '') . "Dormant construct backfill complete.\n";
    echo '- universe path: ' . $universePath . "\n";
    echo '- chance denominator: ' . $options['chanceDenominator'] . "\n";
    echo '- sector files scanned: ' . $stats['files'] . "\n";
    echo '- visited sectors skipped: ' . $stats['visitedSkipped'] . "\n";
    echo '- already present skipped: ' . $stats['alreadyPresent'] . "\n";
    echo '- roll skipped: ' . $stats['rollSkipped'] . "\n";
    echo '- dormant constructs added: ' . $stats['added'] . "\n";
    exit(0);
} catch (InvalidArgumentException | JsonException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . backfillDormantConstructsUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to backfill dormant constructs: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{databaseConfig:?string,universePath:?string,chanceDenominator:int,dryRun:bool,help:bool}
 */
function backfillDormantConstructsParseArguments(array $argv): array
{
    $options = [
        'databaseConfig' => null,
        'universePath' => null,
        'chanceDenominator' => 200,
        'dryRun' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }
        if (str_starts_with($argument, '--database-config=')) {
            $value = substr($argument, strlen('--database-config='));
            $options['databaseConfig'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--universe-path=')) {
            $value = substr($argument, strlen('--universe-path='));
            $options['universePath'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--chance-denominator=')) {
            $options['chanceDenominator'] = backfillDormantConstructsPositiveInteger(
                substr($argument, strlen('--chance-denominator=')),
                'chance denominator',
            );
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    return $options;
}

function backfillDormantConstructsUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/backfill-dormant-constructs.php

Options:
  --database-config=<path>       Use another database config.
  --universe-path=<path>         Use another universe storage path.
  --chance-denominator=<n>       Roll 1/n for each unvisited sector (default: 200).
  --dry-run                      Show what would be written without saving.
  -h, --help                     Show this help.

The script scans every generated sector JSON file, skips sectors already present
in visited_sectors, then adds one Dormant construct on a positive random roll.

TEXT;
}

function backfillDormantConstructsPositiveInteger(string $value, string $label): int
{
    if (preg_match('/\A[1-9]\d*\z/', $value) !== 1) {
        throw new InvalidArgumentException("Invalid {$label}; expected a positive integer.");
    }

    return (int) $value;
}

/**
 * @return array<string, true>
 */
function backfillDormantConstructsVisitedKeys(PDO $pdo): array
{
    $keys = [];
    foreach ($pdo->query('SELECT DISTINCT sector_x, sector_y, sector_z FROM visited_sectors')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $keys[(int) $row['sector_x'] . ':' . (int) $row['sector_y'] . ':' . (int) $row['sector_z']] = true;
    }

    return $keys;
}

function backfillDormantConstructsHasConstruct(SectorContent $sector): bool
{
    foreach ($sector->getObjects() as $object) {
        if ($object instanceof DormantConstruct) {
            return true;
        }
    }

    return false;
}

function backfillDormantConstructsAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}
