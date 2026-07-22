<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Sector\Asteroid;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = asteroidNamesParseArguments($argv);
    if ($options['help']) {
        echo asteroidNamesUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $appConfig = $factory->appConfig();
    $universePath = asteroidNamesAbsolutePath(
        $root,
        $options['universePath'] ?? (string) ($appConfig['universePath'] ?? 'data/universe'),
    );
    $worldSeed = $options['worldSeed'] ?? (string) ($appConfig['worldSeed'] ?? 'default-world');
    $sectorDirectory = rtrim($universePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sectors';

    if (!is_dir($sectorDirectory)) {
        throw new RuntimeException('Sector directory not found: ' . $sectorDirectory);
    }

    $files = asteroidNamesSectorFiles($sectorDirectory);
    $changedFiles = 0;
    $renamedAsteroids = 0;

    foreach ($files as $path) {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Unable to read sector file: ' . $path);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Sector file root must be an object: ' . $path);
        }

        $renamedInFile = asteroidNamesApplyToSectorData($data, $worldSeed, $options['keepExisting']);
        if ($renamedInFile <= 0) {
            continue;
        }

        $changedFiles++;
        $renamedAsteroids += $renamedInFile;

        if ($options['dryRun']) {
            echo '[dry-run] ' . $path . ': ' . $renamedInFile . " asteroid(s) would be named.\n";
            continue;
        }

        asteroidNamesWriteJson($path, $data);
        echo $path . ': ' . $renamedInFile . " asteroid(s) named.\n";
    }

    echo ($options['dryRun'] ? '[dry-run] ' : '')
        . 'Done: ' . count($files) . ' sector file(s) scanned, '
        . $changedFiles . ' file(s) changed, '
        . $renamedAsteroids . " asteroid(s) named.\n";
    exit(0);
} catch (InvalidArgumentException | JsonException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . asteroidNamesUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to name generated asteroids: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{universePath:?string,worldSeed:?string,databaseConfig:?string,dryRun:bool,keepExisting:bool,help:bool}
 */
function asteroidNamesParseArguments(array $argv): array
{
    $options = [
        'universePath' => null,
        'worldSeed' => null,
        'databaseConfig' => null,
        'dryRun' => false,
        'keepExisting' => false,
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
        if ($argument === '--keep-existing') {
            $options['keepExisting'] = true;
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
        if (str_starts_with($argument, '--world-seed=')) {
            $value = substr($argument, strlen('--world-seed='));
            $options['worldSeed'] = $value !== '' ? $value : null;
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    return $options;
}

function asteroidNamesUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/name-generated-asteroids.php [options]

Options:
  --universe-path=<path>    Use another universe storage path.
  --world-seed=<seed>       Override the app world seed used for name hashes.
  --database-config=<path>  Accepted for deployment runbook parity; this script does not touch the database.
  --keep-existing           Only name asteroids whose name is empty or null.
  --dry-run                 Show what would be written without saving.
  -h, --help                Show this help.

TEXT;
}

function asteroidNamesAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

/**
 * @return array<int, string>
 */
function asteroidNamesSectorFiles(string $sectorDirectory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sectorDirectory, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        if (preg_match('/\Asector_.*\.json\z/', $file->getFilename()) !== 1) {
            continue;
        }
        $files[] = $file->getPathname();
    }

    sort($files, SORT_STRING);
    return $files;
}

/**
 * @param array<string, mixed> $data
 */
function asteroidNamesApplyToSectorData(array &$data, string $worldSeed, bool $keepExisting): int
{
    $renamed = 0;
    $sectorKey = asteroidNamesSectorKey($data);
    $nameSeed = $worldSeed . ':sector-content:' . $sectorKey;
    if (is_array($data['objects'] ?? null)) {
        foreach ($data['objects'] as &$object) {
            if (is_array($object)) {
                $renamed += asteroidNamesApplyToObject($object, $nameSeed, $keepExisting);
            }
        }
        unset($object);
    }

    return $renamed;
}

/**
 * @param array<string, mixed> $data
 */
function asteroidNamesSectorKey(array $data): string
{
    $coordinates = is_array($data['coordinates'] ?? null) ? $data['coordinates'] : [];
    return (int) ($coordinates['x'] ?? 0)
        . ':' . (int) ($coordinates['y'] ?? 0)
        . ':' . (int) ($coordinates['z'] ?? 0);
}

/**
 * @param array<string, mixed> $object
 */
function asteroidNamesApplyToObject(array &$object, string $nameSeed, bool $keepExisting): int
{
    $renamed = 0;
    if (($object['type'] ?? null) === 'asteroid') {
        $currentName = $object['name'] ?? null;
        if (!$keepExisting || !is_string($currentName) || trim($currentName) === '') {
            $asteroid = Asteroid::fromArray($object);
            $object['name'] = Asteroid::generatedName(
                $asteroid->getResourceAmounts(),
                $nameSeed . ':' . (string) ($object['id'] ?? ''),
            );
            $renamed++;
        }
    }

    if (($object['type'] ?? null) === 'solar_system' && is_array($object['orbitalBodies'] ?? null)) {
        foreach ($object['orbitalBodies'] as &$body) {
            if (is_array($body) && is_array($body['object'] ?? null)) {
                $renamed += asteroidNamesApplyToObject($body['object'], $nameSeed, $keepExisting);
            }
        }
        unset($body);
    }

    return $renamed;
}

/**
 * @param array<string, mixed> $data
 */
function asteroidNamesWriteJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary sector file: ' . $temporaryPath);
    }
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Unable to replace sector file: ' . $path);
    }
}
