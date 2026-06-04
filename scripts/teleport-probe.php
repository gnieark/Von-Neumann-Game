<?php

declare(strict_types=1);

/*
Facilité Pour du debug
Exemples d'usage:
php scripts/teleport-probe.php 42 --absolute 10 8 0
php scripts/teleport-probe.php 42 --relative 1 1 0
php scripts/teleport-probe.php 42 --relative=1,1,0 --dry-run

avec 42 l'id de a sonde

*/

use VonNeumannGame\AppFactory;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeDirection;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Service\MovementDurationCalculator;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\SchedulerService;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = parseOptions($argv);
    if ($options['help']) {
        echo usage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $pdo = $factory->pdo(initializeSchema: true);
    $appConfig = $factory->appConfig();
    $gameplayConfig = $factory->gameplayConfig();

    $players = new PlayerRepository($pdo);
    $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
    $movements = new ProbeMovementRepository($pdo);
    $visitedSectors = new VisitedSectorRepository($pdo);
    $scheduledEvents = new ScheduledEventRepository($pdo);
    $mannies = new MannyRepository($pdo, $gameplayConfig);
    $sectorService = buildSectorService($factory, $root);
    $durations = new MovementDurationCalculator(Config::getArray($gameplayConfig, 'movement'));
    $movementService = new ProbeMovementService(
        $probes,
        $movements,
        $visitedSectors,
        $scheduledEvents,
        $sectorService,
        durations: $durations,
        worldSeed: (string) ($appConfig['worldSeed'] ?? 'default-world'),
        gameplayConfig: $gameplayConfig,
    );

    $probe = $probes->findById($options['probeId']);
    if ($probe === null) {
        throw new RuntimeException('Probe #' . $options['probeId'] . ' not found in neumann_probes.');
    }

    $player = $players->findById($probe->playerId);
    if ($player === null) {
        throw new RuntimeException('Player #' . $probe->playerId . ' for probe #' . $probe->id . ' not found.');
    }

    $destination = destinationCoordinates($options['mode'], $options['coordinates'], $player->homeSector);
    $relativeDestination = (new PlayerReferenceFrame($player->homeSector))->globalToRelative($destination);
    $activeMovementIds = activeMovementIds($pdo, $probe->id);
    $sameSector = $probe->currentSector->equals($destination);

    if ($options['dryRun']) {
        echo "[dry-run] Probe #{$probe->id} ({$probe->name}) would be teleported.\n";
        echo '- from absolute ' . formatCoordinates($probe->currentSector) . "\n";
        echo '- to absolute ' . formatCoordinates($destination) . "\n";
        echo '- to relative ' . formatCoordinateArray($relativeDestination) . " from player #{$player->id} home\n";
        echo '- active movements to fail: ' . count($activeMovementIds) . "\n";
        echo "- pending movement events to cancel: computed from active movements\n";
        echo "- pending black-hole trap events to cancel: yes, if any\n";
        echo "- destination sector would be marked visited\n";
        echo "- probe navigation/status would be reset to idle\n";
        exit(0);
    }

    $forgottenMannies = 0;
    if (!$sameSector) {
        $forgottenMannies = registerForgottenMannies($mannies, $sectorService, $probe, $gameplayConfig);
    }

    $pdo->beginTransaction();
    try {
        $failedMovements = failActiveMovements($pdo, $probe->id);
        $cancelledMovementEvents = cancelPendingMovementEvents($scheduledEvents, $activeMovementIds);
        $cancelledTrapEvents = $scheduledEvents->cancelPending(
            SchedulerService::PROBE_BLACK_HOLE_TRAP,
            'probe',
            $probe->id,
        );

        $probe->currentSector = $destination;
        $probe->status = ProbeStatus::Idle;
        $probe->velocityC = 0.0;
        $probe->accelerationCPerDay = 0.0;
        $probe->direction = new ProbeDirection(0.0, 0.0, 0.0);
        $probe->currentTask = null;
        $probe->enteredCurrentSectorAt = gmdate('c');
        $probes->save($probe);
        $visitedSectors->markVisitedByPlayerId($probe->playerId, $destination);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $probe = $probes->findById($probe->id) ?? $probe;
    $movementService->refreshCurrentSectorHazards($probe);

    echo "Probe #{$probe->id} ({$probe->name}) teleported.\n";
    echo '- absolute: ' . formatCoordinates($destination) . "\n";
    echo '- relative: ' . formatCoordinateArray($relativeDestination) . " from player #{$player->id} home\n";
    echo "- active movements failed: {$failedMovements}\n";
    echo "- pending movement events cancelled: {$cancelledMovementEvents}\n";
    echo "- pending black-hole trap events cancelled: {$cancelledTrapEvents}\n";
    echo "- forgotten Mannys registered in old sector: {$forgottenMannies}\n";
    echo "- status: idle\n";
} catch (InvalidArgumentException | InvalidSectorCoordinatesException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . usage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Teleport failed: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{probeId: int, mode: string, coordinates: array{x: int, y: int, z: int}, dryRun: bool, help: bool}
 */
function parseOptions(array $argv): array
{
    $arguments = array_values(array_filter(
        array_slice($argv, 1),
        static fn(string $argument): bool => $argument !== '',
    ));

    if ($arguments === [] || in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
        return [
            'probeId' => 0,
            'mode' => 'absolute',
            'coordinates' => ['x' => 0, 'y' => 0, 'z' => 0],
            'dryRun' => false,
            'help' => true,
        ];
    }

    $dryRun = false;
    $arguments = array_values(array_filter(
        $arguments,
        static function (string $argument) use (&$dryRun): bool {
            if ($argument === '--dry-run') {
                $dryRun = true;

                return false;
            }

            return true;
        },
    ));

    if (count($arguments) < 2) {
        throw new InvalidArgumentException('Missing arguments.');
    }

    $probeId = parseInteger($arguments[0], 'probe id');
    if ($probeId <= 0) {
        throw new InvalidArgumentException('probe id must be a positive integer.');
    }

    $modeArgument = $arguments[1];
    $coordinateArguments = [];

    if (str_starts_with($modeArgument, '--absolute=')) {
        $mode = 'absolute';
        $coordinateArguments = explode(',', substr($modeArgument, strlen('--absolute=')));
    } elseif (str_starts_with($modeArgument, '--relative=')) {
        $mode = 'relative';
        $coordinateArguments = explode(',', substr($modeArgument, strlen('--relative=')));
    } elseif (in_array($modeArgument, ['--absolute', '--abs', 'absolute', 'abs'], true)) {
        $mode = 'absolute';
        $coordinateArguments = array_slice($arguments, 2);
    } elseif (in_array($modeArgument, ['--relative', '--rel', 'relative', 'rel'], true)) {
        $mode = 'relative';
        $coordinateArguments = array_slice($arguments, 2);
    } else {
        throw new InvalidArgumentException('Coordinate mode must be --absolute or --relative.');
    }

    if (count($coordinateArguments) !== 3) {
        throw new InvalidArgumentException('Destination must contain exactly three coordinates: x y z.');
    }

    $coordinates = [
        'x' => parseInteger($coordinateArguments[0], 'x'),
        'y' => parseInteger($coordinateArguments[1], 'y'),
        'z' => parseInteger($coordinateArguments[2], 'z'),
    ];

    if ($mode === 'relative' && (($coordinates['x'] + $coordinates['y'] + $coordinates['z']) % 2 !== 0)) {
        throw new InvalidArgumentException('Relative coordinates are invalid: x + y + z must be even.');
    }

    return [
        'probeId' => $probeId,
        'mode' => $mode,
        'coordinates' => $coordinates,
        'dryRun' => $dryRun,
        'help' => false,
    ];
}

function parseInteger(string $value, string $label): int
{
    if (!preg_match('/^-?\d+$/', $value)) {
        throw new InvalidArgumentException("$label must be an integer.");
    }

    return (int) $value;
}

function usage(): string
{
    return <<<TEXT
Usage:
  php scripts/teleport-probe.php <probe-id> --absolute <x> <y> <z> [--dry-run]
  php scripts/teleport-probe.php <probe-id> --relative <x> <y> <z> [--dry-run]
  php scripts/teleport-probe.php <probe-id> --absolute=x,y,z [--dry-run]
  php scripts/teleport-probe.php <probe-id> --relative=x,y,z [--dry-run]

Coordinates:
  --absolute uses raw sector coordinates from neumann_probes.
  --relative uses coordinates relative to the owning player's home sector, like the API.

The teleport fails active movements, cancels pending movement/trap events, marks the
destination sector visited, resets navigation to idle, and schedules destination
hazards such as black-hole traps.

TEXT;
}

/**
 * @param array{x: int, y: int, z: int} $coordinates
 */
function destinationCoordinates(string $mode, array $coordinates, SectorCoordinates $homeSector): SectorCoordinates
{
    if ($mode === 'relative') {
        return (new PlayerReferenceFrame($homeSector))->relativeToGlobal(
            $coordinates['x'],
            $coordinates['y'],
            $coordinates['z'],
        );
    }

    return new SectorCoordinates($coordinates['x'], $coordinates['y'], $coordinates['z']);
}

function buildSectorService(AppFactory $factory, string $root): SectorService
{
    $appConfig = $factory->appConfig();
    $universePath = resolveProjectPath($root, (string) ($appConfig['universePath'] ?? 'data/universe'));

    return new SectorService(
        new SectorFileRepository($universePath),
        new SectorContentGenerator($factory->universeConfig()),
        (string) ($appConfig['worldSeed'] ?? 'default-world'),
    );
}

function resolveProjectPath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

/**
 * @return array<int>
 */
function activeMovementIds(PDO $pdo, int $probeId): array
{
    $stmt = $pdo->prepare(
        "SELECT id FROM probe_movements
         WHERE probe_id = :probe_id AND status IN ('preparing', 'accelerating', 'cruising', 'decelerating')
         ORDER BY id ASC"
    );
    $stmt->execute(['probe_id' => $probeId]);

    return array_map(static fn(array $row): int => (int) $row['id'], $stmt->fetchAll());
}

function failActiveMovements(PDO $pdo, int $probeId): int
{
    $stmt = $pdo->prepare(
        "UPDATE probe_movements
         SET status = 'failed', updated_at = :updated_at
         WHERE probe_id = :probe_id AND status IN ('preparing', 'accelerating', 'cruising', 'decelerating')"
    );
    $stmt->execute([
        'probe_id' => $probeId,
        'updated_at' => gmdate('c'),
    ]);

    return $stmt->rowCount();
}

/**
 * @param array<int> $movementIds
 */
function cancelPendingMovementEvents(ScheduledEventRepository $events, array $movementIds): int
{
    $cancelled = 0;
    foreach ($movementIds as $movementId) {
        $cancelled += $events->cancelPending(
            SchedulerService::PROBE_MOVEMENT_PHASE,
            'probe_movement',
            $movementId,
        );
    }

    return $cancelled;
}

function registerForgottenMannies(MannyRepository $mannies, SectorService $sectors, NeumannProbe $probe, array $gameplayConfig): int
{
    $sector = null;
    $registered = 0;

    foreach ($mannies->findByProbeId($probe->id) as $manny) {
        if ($manny->isOnProbe() || $manny->sector === null || !$manny->sector->equals($probe->currentSector)) {
            continue;
        }

        $sector ??= $sectors->getOrCreateSector($probe->currentSector);
        $object = new SectorManny(
            SectorManny::objectIdForUid($manny->uid),
            $manny->name,
            $manny->uid,
            SectorManny::STATE_FORGOTTEN,
            array_replace($manny->cargoArray(), [
                'capacity' => max(0.0001, Config::float($gameplayConfig, 'manny.cargoCapacity', \VonNeumannGame\Domain\Manny::CARGO_CAPACITY)),
            ]),
            'Manny left behind by debug teleport.',
        );

        if (!$sector->replaceObject($object)) {
            $sector->addObject($object);
        }

        $registered++;
    }

    if ($registered > 0 && $sector !== null) {
        $sectors->saveSector($sector);
    }

    return $registered;
}

function formatCoordinates(SectorCoordinates $coordinates): string
{
    return sprintf('(%d, %d, %d)', $coordinates->getX(), $coordinates->getY(), $coordinates->getZ());
}

/**
 * @param array{x: int, y: int, z: int} $coordinates
 */
function formatCoordinateArray(array $coordinates): string
{
    return sprintf('(%d, %d, %d)', $coordinates['x'], $coordinates['y'], $coordinates['z']);
}
