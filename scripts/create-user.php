<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/create-user.php username password \"Display Name\" [\"Probe Name\"]\n");
    exit(1);
}

$username = $argv[1];
$password = $argv[2];
$displayName = $argv[3] ?? null;
$probeName = $argv[4] ?? null;

$factory = new AppFactory(dirname(__DIR__));
$pdo = $factory->pdo(initializeSchema: true);
$player = $factory->authService($pdo)->registerPlayerWithPassword($username, $password, $displayName, $probeName);

echo "Created player #{$player->id} ({$player->username}).\n";
