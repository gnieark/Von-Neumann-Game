<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/others-cli.php';

try {
    $options = othersCliOptions($argv);
    $enabledRaw = strtolower((string) ($options['enabled'] ?? ''));
    if (!in_array($enabledRaw, ['true', 'false'], true)) {
        throw new InvalidArgumentException('--enabled must be true or false.');
    }
    othersCliService($options['database-config'] ?? null)->setControl(othersCliInteger($options, 'player-id'), $enabledRaw === 'true');
    echo 'Others control permission ' . ($enabledRaw === 'true' ? 'enabled' : 'revoked') . ".\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\nUsage: php scripts/set-others-control.php --player-id=ID --enabled=true|false [--database-config=PATH]\n");
    exit(1);
}
