<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = forceInhabitedPlanetParseArguments($argv);
    if ($options['help']) {
        echo forceInhabitedPlanetUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $appConfig = $factory->appConfig();
    $universeConfig = $factory->universeConfig();
    $worldSeed = (string) ($appConfig['worldSeed'] ?? 'default-world');
    $universePath = forceInhabitedPlanetAbsolutePath($root, (string) ($appConfig['universePath'] ?? 'data/universe'));
    $repository = new SectorFileRepository($universePath);
    $generator = new SectorContentGenerator($universeConfig);

    $coordinates = new SectorCoordinates($options['x'], $options['y'], $options['z']);
    $existed = $repository->exists($coordinates);
    $sector = $existed
        ? $repository->load($coordinates)
        : $generator->generate($coordinates, $worldSeed, []);
    $planetId = $options['id'] ?? forceInhabitedPlanetDefaultId($coordinates, $worldSeed);
    $planetName = array_key_exists('name', $options) ? $options['name'] : null;
    $removedLegacyDebugPlanets = forceInhabitedPlanetRemoveOtherDebugPlanets($sector, $planetId);
    $planet = new Planet(
        $planetId,
        $planetName,
        $options['category'],
        $options['mass'],
        $options['radius'],
        true,
        $options['habitability'],
        $options['resources'],
        intelligentLife: true,
        description: 'Debug-injected inhabited world for first-contact testing.',
    );
    $replaced = $sector->replaceObject($planet);
    if (!$replaced) {
        $sector->addObject($planet);
    }

    if (!$options['dryRun']) {
        $repository->save($sector);
    }

    $path = $repository->getPath($coordinates);
    echo ($options['dryRun'] ? '[dry-run] ' : '') . 'Inhabited planet forced in sector ' . $coordinates->toKey() . ".\n";
    echo '- sector file: ' . $path . "\n";
    echo '- sector existed: ' . ($existed ? 'yes' : 'no') . "\n";
    echo '- planet id: ' . $planetId . "\n";
    echo '- planet name: ' . $planetName . "\n";
    echo '- removed previous debug planets: ' . $removedLegacyDebugPlanets . "\n";
    echo '- action: ' . ($replaced ? 'replaced existing object' : 'added object') . "\n";
    echo '- intelligentLife: true' . "\n";
    echo '- habitabilityScore: ' . $options['habitability'] . "\n";
    exit(0);
} catch (InvalidArgumentException | InvalidSectorCoordinatesException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . forceInhabitedPlanetUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to force inhabited planet: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{
 *     x:int,
 *     y:int,
 *     z:int,
 *     id:?string,
 *     name:?string,
 *     category:string,
 *     mass:float,
 *     radius:float,
 *     habitability:float,
 *     resources:list<string>,
 *     dryRun:bool,
 *     help:bool
 * }
 */
function forceInhabitedPlanetParseArguments(array $argv): array
{
    $options = [
        'x' => 0,
        'y' => 0,
        'z' => 0,
        'id' => null,
        'name' => null,
        'category' => 'ocean',
        'mass' => 1.0,
        'radius' => 1.0,
        'habitability' => 0.92,
        'resources' => ['water_ice', 'carbon', 'organics'],
        'dryRun' => false,
        'help' => false,
    ];
    $coordinates = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }
        if (str_starts_with($argument, '--sector=')) {
            $coordinates = forceInhabitedPlanetParseCoordinates(substr($argument, strlen('--sector=')));
            continue;
        }
        if (str_starts_with($argument, '--id=')) {
            $options['id'] = forceInhabitedPlanetNonEmpty(substr($argument, strlen('--id=')), 'planet id');
            continue;
        }
        if (str_starts_with($argument, '--name=')) {
            $options['name'] = forceInhabitedPlanetNonEmpty(substr($argument, strlen('--name=')), 'planet name');
            continue;
        }
        if (str_starts_with($argument, '--category=')) {
            $options['category'] = forceInhabitedPlanetNonEmpty(substr($argument, strlen('--category=')), 'planet category');
            continue;
        }
        if (str_starts_with($argument, '--mass=')) {
            $options['mass'] = forceInhabitedPlanetPositiveFloat(substr($argument, strlen('--mass=')), 'mass');
            continue;
        }
        if (str_starts_with($argument, '--radius=')) {
            $options['radius'] = forceInhabitedPlanetPositiveFloat(substr($argument, strlen('--radius=')), 'radius');
            continue;
        }
        if (str_starts_with($argument, '--habitability=')) {
            $options['habitability'] = forceInhabitedPlanetBoundedFloat(substr($argument, strlen('--habitability=')), 'habitability', 0.0, 1.0);
            continue;
        }
        if (str_starts_with($argument, '--resources=')) {
            $resources = array_values(array_filter(
                array_map('trim', explode(',', substr($argument, strlen('--resources=')))),
                static fn(string $resource): bool => $resource !== '',
            ));
            if ($resources === []) {
                throw new InvalidArgumentException('resources must contain at least one resource hint.');
            }
            $options['resources'] = $resources;
            continue;
        }

        $coordinates[] = $argument;
    }

    if ($options['help']) {
        return $options;
    }
    if (count($coordinates) === 1) {
        $coordinates = forceInhabitedPlanetParseCoordinates($coordinates[0]);
    }
    if (count($coordinates) !== 3) {
        throw new InvalidArgumentException('Missing sector coordinates.');
    }

    $options['x'] = forceInhabitedPlanetInteger((string) $coordinates[0], 'x');
    $options['y'] = forceInhabitedPlanetInteger((string) $coordinates[1], 'y');
    $options['z'] = forceInhabitedPlanetInteger((string) $coordinates[2], 'z');

    return $options;
}

function forceInhabitedPlanetUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/force-inhabited-planet.php <x> <y> <z>
  php scripts/force-inhabited-planet.php --sector=<x:y:z>
  php scripts/force-inhabited-planet.php --sector=<x,y,z> --name="Pale Signal"

Options:
  --id=<id>                Planet id. Default is opaque and stable for the sector.
  --name=<name>            Planet name. Default is null.
  --category=<category>    Planet category (default: ocean).
  --mass=<n>               Earth masses (default: 1.0).
  --radius=<n>             Earth radii (default: 1.0).
  --habitability=<0..1>    Habitability score (default: 0.92).
  --resources=a,b,c        Resource hints (default: water_ice,carbon,organics).
  --dry-run                Show what would be written without saving.
  -h, --help               Show this help.

Coordinates are absolute sector coordinates and must respect the FCC parity rule.
The script creates the sector if needed, then adds or replaces one inhabited planet.

TEXT;
}

/**
 * @return list<string>
 */
function forceInhabitedPlanetParseCoordinates(string $value): array
{
    $separator = str_contains($value, ':') ? ':' : ',';

    return array_map('trim', explode($separator, $value));
}

function forceInhabitedPlanetInteger(string $value, string $label): int
{
    if (preg_match('/\A-?\d+\z/', $value) !== 1) {
        throw new InvalidArgumentException("Invalid {$label} coordinate.");
    }

    return (int) $value;
}

function forceInhabitedPlanetPositiveFloat(string $value, string $label): float
{
    if (!is_numeric($value) || (float) $value <= 0.0) {
        throw new InvalidArgumentException("{$label} must be a positive number.");
    }

    return (float) $value;
}

function forceInhabitedPlanetBoundedFloat(string $value, string $label, float $min, float $max): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException("{$label} must be a number.");
    }
    $number = (float) $value;
    if ($number < $min || $number > $max) {
        throw new InvalidArgumentException("{$label} must be between {$min} and {$max}.");
    }

    return $number;
}

function forceInhabitedPlanetNonEmpty(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException("{$label} cannot be empty.");
    }

    return $value;
}

function forceInhabitedPlanetDefaultId(SectorCoordinates $coordinates, string $worldSeed): string
{
    return 'debug-inhabited-' . substr(hash('sha256', $worldSeed . '|debug-inhabited-planet|' . $coordinates->toKey()), 0, 20);
}

function forceInhabitedPlanetRemoveOtherDebugPlanets(\VonNeumannGame\Sector\SectorContent $sector, string $planetId): int
{
    $removed = 0;
    foreach ($sector->getObjects() as $object) {
        if (!$object instanceof Planet) {
            continue;
        }
        $objectId = $object->getId();
        if ($objectId === $planetId || !str_starts_with($objectId, 'debug-inhabited-')) {
            continue;
        }
        if ($sector->removeObjectById($objectId)) {
            $removed++;
        }
    }

    return $removed;
}

function forceInhabitedPlanetAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}
