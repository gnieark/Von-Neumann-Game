<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/others-cli.php';

try {
    $options = othersCliOptions($argv);
    $id = $options['ship-id'] ?? throw new InvalidArgumentException('Missing --ship-id.');
    $auxiliary = othersCliService($options['database-config'] ?? null)->createAuxiliary($id);
    echo 'Created Others auxiliary ' . $auxiliary['public_id'] . ".\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\nUsage: php scripts/create-others-auxiliary.php --ship-id=ID [--database-config=PATH]\n");
    exit(1);
}
