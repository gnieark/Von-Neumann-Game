<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/others-cli.php';

try {
    $options = othersCliOptions($argv);
    $id = $options['mothership-id'] ?? throw new InvalidArgumentException('Missing --mothership-id.');
    $ship = othersCliService($options['database-config'] ?? null)->createStandardShip($id);
    echo 'Created Others standard ship ' . $ship['public_id'] . ".\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\nUsage: php scripts/create-others-standard-ship.php --mothership-id=ID [--database-config=PATH]\n");
    exit(1);
}
