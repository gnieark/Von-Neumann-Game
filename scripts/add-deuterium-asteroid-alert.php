<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SolarSystem;
use VonNeumannGame\Sector\UniverseObject;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $options = deuteriumAsteroidParseArguments($argv);
    if ($options['help']) {
        echo deuteriumAsteroidUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);
    $appConfig = $factory->appConfig();
    $gameplayConfig = $factory->gameplayConfig();
    $universePath = deuteriumAsteroidAbsolutePath(
        $root,
        $options['universePath'] ?? (string) ($appConfig['universePath'] ?? 'data/universe'),
    );

    $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
    $alerts = new ProbeDamageWarningRepository($pdo);
    $sectorRepository = new SectorFileRepository($universePath);
    $generator = new SectorContentGenerator($factory->universeConfig());
    $worldSeed = (string) ($appConfig['worldSeed'] ?? 'default-world');

    [$player, $probe] = deuteriumAsteroidResolvePlayerAndProbe($pdo, $probes, $options['username']);
    $sectorExisted = $sectorRepository->exists($probe->currentSector);
    $sector = $sectorExisted
        ? $sectorRepository->load($probe->currentSector)
        : $generator->generate($probe->currentSector, $worldSeed, []);
    $relative = (new PlayerReferenceFrame($player->homeSector))->globalToRelative($probe->currentSector);

    if (deuteriumAsteroidSectorHasDeuteriumAsteroid($sector)) {
        echo ($options['dryRun'] ? '[dry-run] ' : '') . "Sector already contains a deuterium asteroid for {$player->username}; skipped.\n";
        echo '- probe id: ' . $probe->id . "\n";
        echo '- relative sector: ' . deuteriumAsteroidFormatVector($relative) . "\n";
        echo '- sector existed: ' . ($sectorExisted ? 'yes' : 'no') . "\n";
        exit(0);
    }

    $objectId = deuteriumAsteroidObjectId($probe);
    $label = 'Astéroïde contenant du Deutérium';
    $message = 'A new object has been detected in this sector: an asteroid containing deuterium. It was not detected when you entered the sector.';
    $asteroid = new Asteroid(
        $objectId,
        $label,
        'deuterium',
        ['deuterium'],
        'small',
        0.00001,
        0.0001,
        'Astéroïde riche en deutérium détecté après l’arrivée dans le secteur.',
        [
            ResourceComposition::DEUTERIUM => $options['amount'],
            ResourceComposition::METALS => 0.0,
            ResourceComposition::ICE => 0.0,
            ResourceComposition::CARBON_COMPOUNDS => 0.0,
        ],
    );

    if ($options['dryRun']) {
        echo "[dry-run] A deuterium asteroid would be added for {$player->username}.\n";
        echo '- probe id: ' . $probe->id . "\n";
        echo '- relative sector: ' . deuteriumAsteroidFormatVector($relative) . "\n";
        echo '- sector existed: ' . ($sectorExisted ? 'yes' : 'no') . "\n";
        echo '- object id: ' . $objectId . "\n";
        echo '- deuterium amount: ' . $options['amount'] . "\n";
        echo "- alert type: sector_object_detected\n";
        exit(0);
    }

    $sector->addObject($asteroid);
    $sectorRepository->save($sector);

    $movementId = deuteriumAsteroidEnsureMovementId($pdo, $probe);
    $alert = $alerts->createSectorObjectDetectedAlert(
        $probe->id,
        $movementId,
        $probe->currentSector,
        $objectId,
        'asteroid',
        $label,
        $message,
    );

    echo "Deuterium asteroid added for {$player->username}.\n";
    echo '- probe id: ' . $probe->id . "\n";
    echo '- relative sector: ' . deuteriumAsteroidFormatVector($relative) . "\n";
    echo '- sector existed: ' . ($sectorExisted ? 'yes' : 'no') . "\n";
    echo '- object id: ' . $objectId . "\n";
    echo '- deuterium amount: ' . $options['amount'] . "\n";
    echo '- alert id: ' . $alert->id . "\n";
    exit(0);
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . deuteriumAsteroidUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to add deuterium asteroid: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{username: string, databaseConfig: ?string, universePath: ?string, amount: float, dryRun: bool, help: bool}
 */
function deuteriumAsteroidParseArguments(array $argv): array
{
    $options = [
        'username' => '',
        'databaseConfig' => null,
        'universePath' => null,
        'amount' => 10.0,
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
        if (str_starts_with($argument, '--amount=')) {
            $options['amount'] = deuteriumAsteroidPositiveFloat(substr($argument, strlen('--amount=')), 'amount');
            continue;
        }
        if (str_starts_with($argument, '--username=')) {
            $options['username'] = deuteriumAsteroidNonEmpty(substr($argument, strlen('--username=')), 'username');
            continue;
        }
        if ($options['username'] === '') {
            $options['username'] = deuteriumAsteroidNonEmpty($argument, 'username');
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$argument}");
    }

    if (!$options['help'] && $options['username'] === '') {
        throw new InvalidArgumentException('Missing player name.');
    }

    return $options;
}

function deuteriumAsteroidUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/add-deuterium-asteroid-alert.php <player-name>
  php scripts/add-deuterium-asteroid-alert.php --username=<player-name>

Options:
  --database-config=<path>  Use another database config.
  --universe-path=<path>    Use another universe storage path.
  --amount=<n>             Deuterium reserve to place on the asteroid (default: 10).
  --dry-run                Show what would be written without saving.
  -h, --help               Show this help.

The player name is resolved against username first, then an exact display name.

TEXT;
}

function deuteriumAsteroidNonEmpty(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException("{$label} cannot be empty.");
    }

    return $value;
}

function deuteriumAsteroidPositiveFloat(string $value, string $label): float
{
    if (!is_numeric($value) || (float) $value <= 0.0) {
        throw new InvalidArgumentException("{$label} must be a positive number.");
    }

    return round((float) $value, 4);
}

/**
 * @return array{0: Player, 1: NeumannProbe}
 */
function deuteriumAsteroidResolvePlayerAndProbe(PDO $pdo, NeumannProbeRepository $probes, string $name): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM players
         WHERE username = :name
         ORDER BY id ASC
         LIMIT 1'
    );
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        $stmt = $pdo->prepare(
            'SELECT * FROM players
             WHERE display_name = :name
             ORDER BY id ASC
             LIMIT 2'
        );
        $stmt->execute(['name' => $name]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) > 1) {
            throw new RuntimeException("Display name '{$name}' is ambiguous; use the username instead.");
        }
        $row = $matches[0] ?? false;
    }

    if ($row === false) {
        throw new RuntimeException("Player '{$name}' not found.");
    }

    $player = new Player(
        (int) $row['id'],
        (string) $row['username'],
        $row['display_name'] !== null ? (string) $row['display_name'] : null,
        new SectorCoordinates((int) $row['home_sector_x'], (int) $row['home_sector_y'], (int) $row['home_sector_z']),
        (string) $row['created_at'],
        (string) $row['updated_at'],
        (bool) ($row['forum_admin'] ?? false),
        (bool) ($row['forum_moderator'] ?? false),
    );
    $probe = $probes->findByPlayerId($player->id);
    if ($probe === null) {
        throw new RuntimeException("Probe not found for player '{$player->username}'.");
    }

    return [$player, $probe];
}

function deuteriumAsteroidObjectId(NeumannProbe $probe): string
{
    return 'deuterium-asteroid-' . substr(hash('sha256', $probe->id . '|' . $probe->currentSector->toKey() . '|' . microtime(true) . '|' . bin2hex(random_bytes(8))), 0, 24);
}

function deuteriumAsteroidEnsureMovementId(PDO $pdo, NeumannProbe $probe): int
{
    $stmt = $pdo->prepare('SELECT id FROM probe_movements WHERE probe_id = :probe_id ORDER BY id DESC LIMIT 1');
    $stmt->execute(['probe_id' => $probe->id]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $now = gmdate('c');
    $insert = $pdo->prepare(
        'INSERT INTO probe_movements
         (probe_id, origin_x, origin_y, origin_z, target_x, target_y, target_z, distance, status, started_at, preparation_ends_at, acceleration_ends_at, cruise_ends_at, deceleration_ends_at, arrival_at, fuel_cost_deuterium, destruction_checked_at, destroyed_at, destruction_reason, created_at, updated_at)
         VALUES (:probe_id, :origin_x, :origin_y, :origin_z, :target_x, :target_y, :target_z, 0, :status, :started_at, :preparation_ends_at, :acceleration_ends_at, :cruise_ends_at, :deceleration_ends_at, :arrival_at, 0, :destruction_checked_at, NULL, NULL, :created_at, :updated_at)'
    );
    $insert->execute([
        'probe_id' => $probe->id,
        'origin_x' => $probe->currentSector->getX(),
        'origin_y' => $probe->currentSector->getY(),
        'origin_z' => $probe->currentSector->getZ(),
        'target_x' => $probe->currentSector->getX(),
        'target_y' => $probe->currentSector->getY(),
        'target_z' => $probe->currentSector->getZ(),
        'status' => 'completed',
        'started_at' => $now,
        'preparation_ends_at' => $now,
        'acceleration_ends_at' => $now,
        'cruise_ends_at' => $now,
        'deceleration_ends_at' => $now,
        'arrival_at' => $now,
        'destruction_checked_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

function deuteriumAsteroidAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

function deuteriumAsteroidSectorHasDeuteriumAsteroid(SectorContent $sector): bool
{
    foreach ($sector->getObjects() as $object) {
        if (deuteriumAsteroidObjectIsDeuteriumAsteroid($object)) {
            return true;
        }

        if (!$object instanceof SolarSystem) {
            continue;
        }

        foreach ($object->getOrbitalBodies() as $body) {
            if (deuteriumAsteroidObjectIsDeuteriumAsteroid($body->getObject())) {
                return true;
            }
        }
    }

    return false;
}

function deuteriumAsteroidObjectIsDeuteriumAsteroid(UniverseObject $object): bool
{
    if (!$object instanceof Asteroid) {
        return false;
    }

    return (float) ($object->getResourceAmounts()[ResourceComposition::DEUTERIUM] ?? 0.0) > 0.0;
}

/**
 * @param array{x: int, y: int, z: int} $vector
 */
function deuteriumAsteroidFormatVector(array $vector): string
{
    return (int) $vector['x'] . ':' . (int) $vector['y'] . ':' . (int) $vector['z'];
}
