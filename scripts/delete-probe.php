<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Service\ProbeReinstantiationService;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(deleteProbeRun($argv));
} catch (InvalidArgumentException | RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n\n" . deleteProbeUsage());
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Probe deletion failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function deleteProbeRun(array $argv): int
{
    $options = deleteProbeParseArguments($argv);
    if ($options['help']) {
        echo deleteProbeUsage();

        return 0;
    }
    if (!$options['dryRun'] && !$options['yes']) {
        throw new InvalidArgumentException('Refusing to delete without --yes. Run with --dry-run first if you want a preview.');
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $gameplayConfig = $factory->gameplayConfig();
    $appConfig = $factory->appConfig();
    $universeConfig = $factory->universeConfig();
    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);

    $players = new PlayerRepository($pdo);
    $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
    $mannies = new MannyRepository($pdo, $gameplayConfig);
    $visitedSectors = new VisitedSectorRepository($pdo);
    $damageWarnings = new ProbeDamageWarningRepository($pdo);
    $sectorService = new SectorService(
        new SectorFileRepository(deleteProbeAbsolutePath($root, (string) ($appConfig['universePath'] ?? 'data/universe'))),
        new SectorContentGenerator($universeConfig),
        (string) ($appConfig['worldSeed'] ?? 'default-world'),
    );
    $reinstantiation = new ProbeReinstantiationService(
        $pdo,
        $players,
        $probes,
        $mannies,
        $visitedSectors,
        $sectorService,
        $damageWarnings,
        gameplayConfig: $gameplayConfig,
        universeConfig: $universeConfig,
    );

    $probe = $probes->findById($options['probeId']);
    if ($probe === null) {
        throw new RuntimeException("Probe #{$options['probeId']} not found.");
    }
    if (!in_array($probe->status, [ProbeStatus::Dead, ProbeStatus::TrappedByBlackHole], true)) {
        throw new RuntimeException("Probe #{$probe->id} is {$probe->status->value}, not dead or trapped. This script only deletes terminal probes.");
    }

    $player = $players->findById($probe->playerId) ?? throw new RuntimeException("Player #{$probe->playerId} not found.");
    $ownedProbes = $probes->findAllByPlayerId($player->id);
    $ownedProbeCount = count($ownedProbes);
    if ($ownedProbeCount <= 1) {
        throw new RuntimeException("Probe #{$probe->id} is the player's last probe. Keep the mind-snapshot reassignment flow instead.");
    }

    $wasDefault = $player->defaultProbeId === $probe->id;
    if ($options['dryRun']) {
        $alertProbe = deleteProbeAlertTarget($player->defaultProbeId, $probe, $ownedProbes);
        $remainingProbeCount = $ownedProbeCount - 1;
        $defaultProbeId = $wasDefault ? ($alertProbe?->id ?? null) : $player->defaultProbeId;
    } else {
        $pdo->beginTransaction();
        try {
            $alertProbe = $reinstantiation->handleTerminalProbeLoss($probe, $options['reason']);
            $updatedPlayer = $players->findById($player->id) ?? $player;
            $remainingProbeCount = count($probes->findAllByPlayerId($player->id));
            $defaultProbeId = $updatedPlayer->defaultProbeId;
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    echo ($options['dryRun'] ? '[dry-run] Would delete' : 'Deleted')
        . " terminal probe #{$probe->id} ({$probe->name}) for player #{$player->id} ({$player->username}).\n";
    echo '- Reason: ' . $options['reason'] . "\n";
    echo '- Was default probe: ' . ($wasDefault ? 'yes' : 'no') . "\n";
    echo '- Alert target probe: #' . ($alertProbe?->id ?? 0) . "\n";
    echo '- Remaining probes after operation: ' . $remainingProbeCount . "\n";
    echo '- Default probe after operation: #' . ($defaultProbeId ?? 0) . "\n";
    if ($options['dryRun']) {
        echo "No data was written.\n";
    }

    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{probeId:int, databaseConfig:?string, reason:string, dryRun:bool, yes:bool, help:bool}
 */
function deleteProbeParseArguments(array $argv): array
{
    $options = [
        'probeId' => 0,
        'databaseConfig' => null,
        'reason' => ProbeReinstantiationService::TERMINAL_REASON_COLLISION,
        'dryRun' => false,
        'yes' => false,
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
        if ($argument === '--yes' || $argument === '-y') {
            $options['yes'] = true;
            continue;
        }
        if (str_starts_with($argument, '--database-config=')) {
            $value = substr($argument, strlen('--database-config='));
            $options['databaseConfig'] = $value !== '' ? $value : null;
            continue;
        }
        if (str_starts_with($argument, '--probe-id=')) {
            $options['probeId'] = deleteProbePositiveInt(substr($argument, strlen('--probe-id=')), 'probe id');
            continue;
        }
        if (str_starts_with($argument, '--reason=')) {
            $reason = substr($argument, strlen('--reason='));
            if (!in_array($reason, [ProbeReinstantiationService::TERMINAL_REASON_COLLISION, ProbeReinstantiationService::TERMINAL_REASON_BLACK_HOLE], true)) {
                throw new InvalidArgumentException('Reason must be movement_collision or black_hole_trap.');
            }
            $options['reason'] = $reason;
            continue;
        }
        if (str_starts_with($argument, '--')) {
            throw new InvalidArgumentException("Unexpected option: {$argument}");
        }

        $positionals[] = $argument;
    }

    if ($positionals !== []) {
        $options['probeId'] = deleteProbePositiveInt((string) array_shift($positionals), 'probe id');
    }
    if ($positionals !== []) {
        throw new InvalidArgumentException('Too many positional arguments.');
    }
    if (!$options['help'] && $options['probeId'] <= 0) {
        throw new InvalidArgumentException('Missing --probe-id.');
    }

    return $options;
}

function deleteProbeUsage(): string
{
    return <<<TEXT
Usage:
  php scripts/delete-probe.php --probe-id=<probe-id> --dry-run
  php scripts/delete-probe.php --probe-id=<probe-id> --yes
  php scripts/delete-probe.php <probe-id> --database-config=config/database.json --dry-run

Options:
  --database-config=<path>  Use another database config.
  --reason=<reason>         movement_collision or black_hole_trap. Default: movement_collision.
  --dry-run                 Preview the cleanup without writing data.
  --yes, -y                 Apply the deletion.
  -h, --help                Show this help.

Deletes one terminal probe by id through the same cleanup path as runtime
probe loss. The player, their other probes, player-owned visited sectors,
missions, sessions and forum data are kept. If the probe is the player's last
probe, the script refuses to delete it so the mind-snapshot reassignment flow
can be used instead.

TEXT;
}

function deleteProbePositiveInt(string $value, string $label): int
{
    if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        throw new InvalidArgumentException("Invalid {$label}.");
    }

    return (int) $value;
}

/**
 * @param array<object> $ownedProbes
 */
function deleteProbeAlertTarget(?int $defaultProbeId, object $terminalProbe, array $ownedProbes): ?object
{
    $survivors = array_values(array_filter(
        $ownedProbes,
        static fn(object $candidate): bool => $candidate->id !== $terminalProbe->id
            && !in_array($candidate->status, [ProbeStatus::Dead, ProbeStatus::TrappedByBlackHole], true),
    ));
    if ($survivors === []) {
        return null;
    }
    if ($defaultProbeId !== $terminalProbe->id) {
        foreach ($survivors as $candidate) {
            if ($candidate->id === $defaultProbeId) {
                return $candidate;
            }
        }
    }

    $grid = new SectorGrid();
    usort(
        $survivors,
        fn(object $left, object $right): int => [
            $grid->getDistance($left->currentSector, $terminalProbe->currentSector),
            $left->id,
        ] <=> [
            $grid->getDistance($right->currentSector, $terminalProbe->currentSector),
            $right->id,
        ],
    );

    return $survivors[0];
}

function deleteProbeAbsolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return $root . DIRECTORY_SEPARATOR . $path;
}
