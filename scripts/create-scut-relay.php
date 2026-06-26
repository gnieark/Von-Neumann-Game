<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ScutNetworkRepository;
use VonNeumannGame\Repository\ScutRelayRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Service\ScutNetworkService;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    exit(createScutRelayRun($argv));
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n\n" . createScutRelayUsage());
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to create SCUT relay: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<int, string> $argv
 */
function createScutRelayRun(array $argv): int
{
    $options = createScutRelayParseArguments($argv);
    if ($options['help']) {
        echo createScutRelayUsage();

        return 0;
    }

    $sector = new SectorCoordinates($options['x'], $options['y'], $options['z']);
    $root = dirname(__DIR__);
    $factory = new AppFactory($root);
    $pdo = $factory->pdo($options['databaseConfig'], initializeSchema: true);
    $gameplayConfig = $factory->gameplayConfig();
    $probes = new NeumannProbeRepository($pdo, $gameplayConfig);
    $service = new ScutNetworkService(
        new ScutRelayRepository($pdo),
        new ScutNetworkRepository($pdo),
        $probes,
    );

    $relay = $service->createOffRelay($sector);
    echo 'Created inactive SCUT relay #' . $relay->id . ' at absolute sector ' . $sector->toKey() . ".\n";

    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{x:int,y:int,z:int,databaseConfig:?string,help:bool}
 */
function createScutRelayParseArguments(array $argv): array
{
    $options = [
        'x' => null,
        'y' => null,
        'z' => null,
        'databaseConfig' => null,
        'help' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--database-config') {
            $options['databaseConfig'] = $argv[++$i] ?? throw new InvalidArgumentException('Missing value for --database-config.');
            continue;
        }
        if (str_starts_with($arg, '--database-config=')) {
            $options['databaseConfig'] = substr($arg, strlen('--database-config='));
            continue;
        }
        foreach (['x', 'y', 'z'] as $axis) {
            if ($arg === '--' . $axis) {
                $value = $argv[++$i] ?? throw new InvalidArgumentException('Missing value for --' . $axis . '.');
                $options[$axis] = createScutRelayInteger($value, $axis);
                continue 2;
            }
            if (str_starts_with($arg, '--' . $axis . '=')) {
                $options[$axis] = createScutRelayInteger(substr($arg, 4), $axis);
                continue 2;
            }
        }

        foreach (['x', 'y', 'z'] as $axis) {
            if ($options[$axis] === null) {
                $options[$axis] = createScutRelayInteger($arg, $axis);
                continue 2;
            }
        }

        throw new InvalidArgumentException('Unknown argument: ' . $arg);
    }

    foreach (['x', 'y', 'z'] as $axis) {
        if ($options[$axis] === null) {
            throw new InvalidArgumentException('Missing target sector coordinate ' . $axis . '.');
        }
    }

    return $options;
}

function createScutRelayInteger(string $value, string $field): int
{
    if ((string) (int) $value !== $value) {
        throw new InvalidArgumentException('Coordinate ' . $field . ' must be an integer.');
    }

    return (int) $value;
}

function createScutRelayUsage(): string
{
    return <<<TXT
Usage:
  php scripts/create-scut-relay.php <x> <y> <z> [--database-config path]
  php scripts/create-scut-relay.php --x <x> --y <y> --z <z> [--database-config path]

Creates an inactive SCUT relay in the absolute target sector.

TXT;
}
