<?php

declare(strict_types=1);

use VonNeumannGame\Sector\SectorCoordinates;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/others-cli.php';

try {
    $options = othersCliOptions($argv);
    $fleet = othersCliService($options['database-config'] ?? null)->createFleet(
        othersCliInteger($options, 'player-id'),
        new SectorCoordinates(othersCliInteger($options, 'sector-x'), othersCliInteger($options, 'sector-y'), othersCliInteger($options, 'sector-z')),
    );
    echo 'Created Others fleet ' . $fleet['public_id'] . ' with mothership ' . $fleet['ship']['public_id'] . ".\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\nUsage: php scripts/create-others-fleet.php --player-id=ID --sector-x=X --sector-y=Y --sector-z=Z [--database-config=PATH]\n");
    exit(1);
}
