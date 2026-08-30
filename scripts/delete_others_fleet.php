<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/others-cli.php';

try {
    $options = othersCliOptions($argv);
    $mothershipId = $options['mothership-id'] ?? throw new InvalidArgumentException('Missing --mothership-id.');
    $result = othersCliService($options['database-config'] ?? null)->deleteFleet($mothershipId);

    echo 'Deleted Others fleet ' . $result['fleetId'] . ' from mothership ' . $result['mothershipId'] . ".\n";
    echo '- ships: ' . $result['ships'] . "\n";
    echo '- auxiliaries: ' . $result['auxiliaries'] . "\n";
    echo '- actions: ' . $result['actions'] . "\n";
    echo '- scheduler events: ' . $result['scheduledEvents'] . "\n";
    echo '- missiles/projectiles: ' . ($result['missileLaunches'] + $result['projectiles']) . "\n";
    echo '- related records: ' . array_sum(array_filter(
        $result,
        static fn(mixed $value, string $key): bool => is_int($value)
            && !in_array($key, ['playerId', 'ships', 'auxiliaries', 'actions', 'scheduledEvents', 'missileLaunches', 'projectiles', 'fleets'], true),
        ARRAY_FILTER_USE_BOTH,
    )) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\nUsage: php scripts/delete_others_fleet.php --mothership-id=ID [--database-config=PATH]\n");
    exit(1);
}
