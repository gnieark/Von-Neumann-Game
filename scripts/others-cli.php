<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\OthersAuditRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Service\OthersAdminService;

function othersCliOptions(array $argv): array
{
    $options = [];
    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];
        if (!str_starts_with($argument, '--')) {
            throw new InvalidArgumentException('Unknown argument: ' . $argument);
        }
        $parts = explode('=', substr($argument, 2), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('Arguments must use --name=value.');
        }
        $options[$parts[0]] = $parts[1];
    }
    return $options;
}

function othersCliInteger(array $options, string $name): int
{
    $value = $options[$name] ?? null;
    if (!is_string($value) || !preg_match('/^-?\d+$/', $value)) {
        throw new InvalidArgumentException('--' . $name . ' must be an integer.');
    }
    return (int) $value;
}

function othersCliService(?string $config): OthersAdminService
{
    $factory = new AppFactory(dirname(__DIR__));
    $pdo = $factory->pdo($config, initializeSchema: false);
    return new OthersAdminService(new PlayerRepository($pdo), new OthersRepository($pdo), new OthersAuditRepository($pdo));
}
