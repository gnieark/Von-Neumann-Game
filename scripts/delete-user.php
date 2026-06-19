<?php
/*
usage: 
php scripts/delete-user.php <username> --dry-run
php scripts/delete-user.php <username> --yes
php scripts/delete-user.php --id=<player-id> --dry-run
php scripts/delete-user.php --id=<player-id> --yes
*/
declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Auth\AccountDeletionService;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorService;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(run($argv));
} catch (InvalidArgumentException | RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n\n" . usage());
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Delete failed: ' . $error->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function run(array $argv): int
{
    $options = parseArguments($argv);
    if ($options['help']) {
        echo usage();

        return 0;
    }
    if ($options['username'] === null && $options['id'] === null) {
        throw new InvalidArgumentException('Missing username or --id.');
    }

    if (!$options['dryRun'] && !$options['yes']) {
        throw new InvalidArgumentException('Refusing to delete without --yes. Run with --dry-run first if you want a preview.');
    }

    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $pdo = $factory->pdo(initializeSchema: true);
    $players = new PlayerRepository($pdo);
    $player = $options['id'] !== null
        ? $players->findById($options['id'])
        : $players->findByUsername((string) $options['username']);

    if ($player === null) {
        throw new RuntimeException('Player not found.');
    }

    $gameplayConfig = $factory->gameplayConfig();
    $appConfig = $factory->appConfig();
    $universeConfig = $factory->universeConfig();
    $universePath = absolutePath($root, (string) ($appConfig['universePath'] ?? 'data/universe'));
    $sectorService = new SectorService(
        new SectorFileRepository($universePath),
        new SectorContentGenerator($universeConfig),
        (string) ($appConfig['worldSeed'] ?? 'default-world'),
    );

    $deleter = new AccountDeletionService(
        $pdo,
        new NeumannProbeRepository($pdo, $gameplayConfig),
        new MannyRepository($pdo, $gameplayConfig),
        $sectorService,
    );

    $stats = $deleter->deletePlayer($player, $options['dryRun']);

    echo ($options['dryRun'] ? 'Dry run for' : 'Deleted') . " player #{$player->id} ({$player->username}).\n";
    foreach ($stats as $label => $count) {
        echo "- {$label}: {$count}\n";
    }

    if ($options['dryRun']) {
        echo "\nNo data was deleted. Re-run with --yes to delete this player.\n";
    }

    return 0;
}

/**
 * @return array{username: ?string, id: ?int, dryRun: bool, yes: bool, help: bool}
 */
function parseArguments(array $argv): array
{
    $options = [
        'username' => null,
        'id' => null,
        'dryRun' => false,
        'yes' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $options['dryRun'] = true;
            continue;
        }
        if ($arg === '--yes' || $arg === '-y') {
            $options['yes'] = true;
            continue;
        }
        if (str_starts_with($arg, '--id=')) {
            $id = substr($arg, 5);
            if ($id === '' || preg_match('/\A\d+\z/', $id) !== 1) {
                throw new InvalidArgumentException('Invalid --id value.');
            }
            $options['id'] = (int) $id;
            continue;
        }
        if (str_starts_with($arg, '--username=')) {
            $options['username'] = substr($arg, 11);
            continue;
        }
        if (str_starts_with($arg, '#') && preg_match('/\A#\d+\z/', $arg) === 1) {
            $options['id'] = (int) substr($arg, 1);
            continue;
        }
        if ($options['username'] === null) {
            $options['username'] = $arg;
            continue;
        }

        throw new InvalidArgumentException("Unexpected argument: {$arg}");
    }

    if ($options['id'] !== null && $options['username'] !== null) {
        throw new InvalidArgumentException('Use either a username or an id, not both.');
    }

    return $options;
}

function usage(): string
{
    return <<<TEXT
Usage:
  php scripts/delete-user.php <username> --dry-run
  php scripts/delete-user.php <username> --yes
  php scripts/delete-user.php --id=<player-id> --dry-run
  php scripts/delete-user.php --id=<player-id> --yes

Deletes one player, their probe, sessions, API keys, auth methods, visited
sectors, movement history, missions, inventory, storage and onboard Mannys.

Mannys already outside the probe are detached and registered as abandoned sector
objects so another probe can recover them later.

TEXT;
}

function absolutePath(string $root, string $path): string
{
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return $root . DIRECTORY_SEPARATOR . $path;
}
