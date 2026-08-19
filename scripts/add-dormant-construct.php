<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = addDormantConstructParseArguments($argv);
    if ($options['help']) {
        echo addDormantConstructUsage();
        exit(0);
    }
    if ($options['listTypes']) {
        echo addDormantConstructTypeList();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $appConfig = $factory->appConfig();
    $worldSeed = (string) ($appConfig['worldSeed'] ?? 'default-world');
    $universePath = addDormantConstructAbsolutePath(
        $root,
        $options['universePath'] ?? (string) ($appConfig['universePath'] ?? 'data/universe'),
    );
    $repository = new SectorFileRepository($universePath);
    $coordinates = new SectorCoordinates($options['x'], $options['y'], $options['z']);
    $sectorExisted = $repository->exists($coordinates);
    $sector = $sectorExisted
        ? $repository->load($coordinates)
        : (new SectorContentGenerator($factory->universeConfig()))->generate($coordinates, $worldSeed, []);

    $existing = addDormantConstructExistingObject($sector);
    $objectId = $options['id'] ?? DormantConstruct::objectIdForSector($coordinates, $worldSeed);
    $construct = addDormantConstructCreate($objectId, $options['type']);
    $action = 'already_present';
    if ($existing === null) {
        $sector->addObject($construct);
        $action = 'added';
        if (!$options['dryRun']) {
            $repository->save($sector);
        }
    }
    $reportedConstruct = $existing ?? $construct;

    echo ($options['dryRun'] ? '[dry-run] ' : '') . 'Dormant construct in sector ' . $coordinates->toKey() . ".\n";
    echo '- sector file: ' . $repository->getPath($coordinates) . "\n";
    echo '- sector existed: ' . ($sectorExisted ? 'yes' : 'no') . "\n";
    echo '- action: ' . ($existing === null ? $action : 'already present') . "\n";
    echo '- object id: ' . $reportedConstruct->getId() . "\n";
    echo '- structure type: ' . ($reportedConstruct->getInspectionScenario() ?? 'random on inspection') . "\n";
    exit(0);
} catch (InvalidArgumentException | InvalidSectorCoordinatesException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . addDormantConstructUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to add dormant construct: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{x:int,y:int,z:int,id:?string,type:?string,universePath:?string,dryRun:bool,listTypes:bool,help:bool}
 */
function addDormantConstructParseArguments(array $argv): array
{
    $options = [
        'x' => 0,
        'y' => 0,
        'z' => 0,
        'id' => null,
        'type' => null,
        'universePath' => null,
        'dryRun' => false,
        'listTypes' => false,
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
        if ($argument === '--list-types') {
            $options['listTypes'] = true;
            continue;
        }
        if (str_starts_with($argument, '--sector=')) {
            $coordinates = addDormantConstructParseCoordinates(substr($argument, strlen('--sector=')));
            continue;
        }
        if (str_starts_with($argument, '--id=')) {
            $options['id'] = addDormantConstructNonEmpty(substr($argument, strlen('--id=')), 'object id');
            continue;
        }
        if (str_starts_with($argument, '--type=')) {
            $options['type'] = addDormantConstructParseType(substr($argument, strlen('--type=')));
            continue;
        }
        if (str_starts_with($argument, '--universe-path=')) {
            $value = substr($argument, strlen('--universe-path='));
            $options['universePath'] = $value !== '' ? $value : null;
            continue;
        }

        $coordinates[] = $argument;
    }

    if ($options['help'] || $options['listTypes']) {
        return $options;
    }
    if (count($coordinates) === 1) {
        $coordinates = addDormantConstructParseCoordinates((string) $coordinates[0]);
    }
    if (count($coordinates) !== 3) {
        throw new InvalidArgumentException('Missing sector coordinates.');
    }

    $options['x'] = addDormantConstructInteger((string) $coordinates[0], 'x');
    $options['y'] = addDormantConstructInteger((string) $coordinates[1], 'y');
    $options['z'] = addDormantConstructInteger((string) $coordinates[2], 'z');

    return $options;
}

function addDormantConstructUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/add-dormant-construct.php <x> <y> <z>
  php scripts/add-dormant-construct.php --sector=<x:y:z>
  php scripts/add-dormant-construct.php --sector=<x,y,z>

Options:
  --id=<id>                Object id. Default is opaque and stable for the sector.
  --type=<type>            Force the structure type instead of choosing it on inspection.
  --list-types             List the accepted structure types and exit.
  --universe-path=<path>   Use another universe storage path.
  --dry-run                Show what would be written without saving.
  -h, --help               Show this help.

Coordinates are absolute sector coordinates and must respect the FCC parity rule.
The script creates the sector if needed, then adds one Dormant construct unless one is already present.
When a construct is already present, --type does not replace or modify it.

TEXT;
}

/**
 * @return list<string>
 */
function addDormantConstructParseCoordinates(string $value): array
{
    $separator = str_contains($value, ':') ? ':' : ',';

    return array_map('trim', explode($separator, $value));
}

function addDormantConstructInteger(string $value, string $label): int
{
    if (preg_match('/\A-?\d+\z/', $value) !== 1) {
        throw new InvalidArgumentException("Invalid {$label} coordinate.");
    }

    return (int) $value;
}

function addDormantConstructNonEmpty(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException("{$label} cannot be empty.");
    }

    return $value;
}

function addDormantConstructParseType(string $value): string
{
    $type = strtolower(str_replace([' ', '-'], '_', addDormantConstructNonEmpty($value, 'structure type')));
    if (!in_array($type, DormantConstruct::inspectionScenarios(), true)) {
        throw new InvalidArgumentException(
            'Unknown structure type "' . $value . '". Accepted types: '
            . implode(', ', DormantConstruct::inspectionScenarios()) . '.',
        );
    }

    return $type;
}

function addDormantConstructCreate(string $objectId, ?string $type): DormantConstruct
{
    if ($type === DormantConstruct::INSPECTION_SCENARIO_DISTRIBUTED_THRUST_ANCHORING) {
        return new DormantConstruct(
            $objectId,
            DormantConstruct::THRUST_ANCHORED_ASTEROID_NAME,
            description: DormantConstruct::THRUST_ANCHORED_ASTEROID_DESCRIPTION,
            inspectionScenario: $type,
        );
    }
    if ($type === DormantConstruct::INSPECTION_SCENARIO_ANATIFORM_ASTEROID_SCULPTING) {
        return new DormantConstruct(
            $objectId,
            DormantConstruct::ANATIFORM_ASTEROID_NAME,
            description: DormantConstruct::ANATIFORM_ASTEROID_DESCRIPTION,
            inspectionScenario: $type,
        );
    }

    return new DormantConstruct($objectId, inspectionScenario: $type);
}

function addDormantConstructTypeList(): string
{
    return "Available dormant construct types:\n"
        . '- ' . DormantConstruct::INSPECTION_SCENARIO_DEUTERIUM_COMPRESSION . " (Dormant construct)\n"
        . '- ' . DormantConstruct::INSPECTION_SCENARIO_REINFORCED_CONTAINER_COUPLINGS . " (Dormant construct)\n"
        . '- ' . DormantConstruct::INSPECTION_SCENARIO_DISTRIBUTED_THRUST_ANCHORING . ' (' . DormantConstruct::THRUST_ANCHORED_ASTEROID_NAME . ")\n"
        . '- ' . DormantConstruct::INSPECTION_SCENARIO_ANATIFORM_ASTEROID_SCULPTING . ' (' . DormantConstruct::ANATIFORM_ASTEROID_NAME . ")\n";
}

function addDormantConstructExistingObject(\VonNeumannGame\Sector\SectorContent $sector): ?DormantConstruct
{
    foreach ($sector->getObjects() as $object) {
        if ($object instanceof DormantConstruct) {
            return $object;
        }
    }

    return null;
}

function addDormantConstructAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}
