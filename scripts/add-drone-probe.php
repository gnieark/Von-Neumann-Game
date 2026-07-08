<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeImprovementRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\StorageContainerRepository;
use VonNeumannGame\Service\ProbeStorageService;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(addDroneProbeRun($argv));
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . addDroneProbeUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to add drone probe: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function addDroneProbeRun(array $argv): int
{
    $options = addDroneProbeParseArguments($argv);
    if ($options['help']) {
        echo addDroneProbeUsage();

        return 0;
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $gameplayConfig = $factory->gameplayConfig();
    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);

    $players = new PlayerRepository($pdo);
    $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
    $mannies = new MannyRepository($pdo, $gameplayConfig);
    $items = new ProbeItemRepository($pdo);
    $containers = new StorageContainerRepository($pdo, $gameplayConfig);
    $improvements = new ProbeImprovementRepository($pdo);
    $storage = new ProbeStorageService($containers, $items, $mannies, $probes, $gameplayConfig, $improvements);

    $player = addDroneProbeResolvePlayer($players, $options['player'])
        ?? throw new RuntimeException("Player '{$options['player']}' not found.");

    $existingDrone = addDroneProbeFindExistingDrone($probes, $player);
    if ($existingDrone !== null) {
        throw new RuntimeException("Player #{$player->id} ({$player->username}) already has a probe named drone (#{$existingDrone->id}).");
    }

    $pdo->beginTransaction();
    try {
        $probe = $probes->createForPlayer($player->id, 'drone', $player->homeSector);
        $probe->excludeFromStats = true;
        $probes->save($probe);

        $manny = $mannies->createForProbe($probe->id, 'manny-drone');
        if (!$storage->placeMannyOnProbe($probe, $manny)) {
            throw new RuntimeException('Insufficient probe cargo capacity for the Manny.');
        }
        $manny->taskPayload = [
            'debugAddedBy' => 'scripts/add-drone-probe.php',
            'debugAddedAt' => gmdate('c'),
        ];
        $mannies->save($manny);

        if ($options['dryRun']) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    echo ($options['dryRun'] ? '[dry-run] Would create' : 'Created')
        . " probe #{$probe->id} (drone) for player #{$player->id} ({$player->username}).\n";
    echo "- Probe excluded from public stats.\n";
    echo "- Manny #{$manny->id} ({$manny->name}, uid {$manny->uid}) placed in probe storage.\n";
    if ($options['dryRun']) {
        echo "No data was written.\n";
    }

    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{player:string, databaseConfig:?string, dryRun:bool, help:bool}
 */
function addDroneProbeParseArguments(array $argv): array
{
    $options = [
        'player' => '',
        'databaseConfig' => null,
        'dryRun' => false,
        'help' => false,
    ];
    $positionals = [];

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
        if (str_starts_with($argument, '--player=')) {
            $options['player'] = addDroneProbeNonEmpty(substr($argument, strlen('--player=')), 'player');
            continue;
        }
        if (str_starts_with($argument, '--')) {
            throw new InvalidArgumentException("Unexpected option: {$argument}");
        }

        $positionals[] = $argument;
    }

    if ($positionals !== []) {
        $options['player'] = addDroneProbeNonEmpty((string) array_shift($positionals), 'player');
    }
    if ($positionals !== []) {
        throw new InvalidArgumentException('Too many positional arguments.');
    }
    if (!$options['help'] && $options['player'] === '') {
        throw new InvalidArgumentException('Missing player id or username.');
    }

    return $options;
}

function addDroneProbeUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/add-drone-probe.php <player-id-or-username>
  php scripts/add-drone-probe.php --player=<player-id-or-username>

Examples:
  php scripts/add-drone-probe.php 42
  php scripts/add-drone-probe.php remi

Options:
  --database-config=<path>  Use another database config.
  --dry-run                 Validate the operation and roll it back.
  -h, --help                Show this help.

TEXT;
}

function addDroneProbeResolvePlayer(PlayerRepository $players, string $identifier): ?Player
{
    if (preg_match('/^[1-9][0-9]*$/', $identifier) === 1) {
        return $players->findById((int) $identifier);
    }

    return $players->findByUsername($identifier);
}

function addDroneProbeFindExistingDrone(NeumannProbeRepository $probes, Player $player): ?NeumannProbe
{
    foreach ($probes->findAllByPlayerId($player->id) as $probe) {
        if (strcasecmp($probe->name, 'drone') === 0) {
            return $probe;
        }
    }

    return null;
}

function addDroneProbeNonEmpty(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException("{$label} cannot be empty.");
    }

    return $value;
}
