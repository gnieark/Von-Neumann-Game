<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = parseSectorJsonArguments($argv);
    if ($options['help']) {
        echo sectorJsonUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $coordinates = new SectorCoordinates($options['x'], $options['y'], $options['z']);
    $universePath = $options['universePath'];
    if ($universePath === null) {
        $appConfig = (new AppFactory($root))->appConfig();
        $universePath = (string) ($appConfig['universePath'] ?? 'data/universe');
    }

    $repository = new SectorFileRepository(sectorJsonAbsolutePath($root, $universePath));
    $path = $repository->getPath($coordinates);
    if ($options['pathOnly']) {
        echo $path . "\n";
        exit(0);
    }

    $json = @file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('Sector file not found: ' . $path);
    }

    if ($options['pretty']) {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    echo rtrim($json) . "\n";
    exit(0);
} catch (InvalidArgumentException | InvalidSectorCoordinatesException | JsonException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . sectorJsonUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to read sector JSON: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{x:int,y:int,z:int,universePath:?string,pathOnly:bool,pretty:bool,help:bool}
 */
function parseSectorJsonArguments(array $argv): array
{
    $options = [
        'x' => 0,
        'y' => 0,
        'z' => 0,
        'universePath' => null,
        'pathOnly' => false,
        'pretty' => false,
        'help' => false,
    ];
    $coordinates = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--path-only') {
            $options['pathOnly'] = true;
            continue;
        }
        if ($argument === '--pretty') {
            $options['pretty'] = true;
            continue;
        }
        if (str_starts_with($argument, '--universe-path=')) {
            $value = substr($argument, strlen('--universe-path='));
            $options['universePath'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--sector=')) {
            $coordinates = sectorJsonParseCoordinates(substr($argument, strlen('--sector=')));
            continue;
        }
        if (str_contains($argument, ',') || str_contains($argument, ':')) {
            $coordinates = sectorJsonParseCoordinates($argument);
            continue;
        }
        if (preg_match('/\A-?\d+\z/', $argument) === 1 && count($coordinates) < 3) {
            $coordinates[] = (int) $argument;
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    if (!$options['help'] && count($coordinates) !== 3) {
        throw new InvalidArgumentException('Missing absolute sector coordinates.');
    }
    if (count($coordinates) === 3) {
        [$options['x'], $options['y'], $options['z']] = $coordinates;
    }

    return $options;
}

/**
 * @return array<int>
 */
function sectorJsonParseCoordinates(string $value): array
{
    $parts = preg_split('/[,:]/', $value);
    if ($parts === false || count($parts) !== 3) {
        throw new InvalidArgumentException('Coordinates must be x,y,z or x:y:z.');
    }

    return array_map(static function (string $part): int {
        $part = trim($part);
        if (preg_match('/\A-?\d+\z/', $part) !== 1) {
            throw new InvalidArgumentException('Coordinates must be integers.');
        }

        return (int) $part;
    }, $parts);
}

function sectorJsonUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/sector-json.php <x> <y> <z>
  php scripts/sector-json.php --sector=<x>,<y>,<z>
  php scripts/sector-json.php <x>:<y>:<z>

Options:
  --universe-path=<path>  Use another universe directory instead of config/app.json.
  --path-only            Print only the resolved sector file path.
  --pretty               Decode and re-encode the JSON before printing.
  -h, --help             Show this help.

Coordinates are absolute sector coordinates and must respect the FCC parity rule.

TEXT;
}

function sectorJsonAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}
