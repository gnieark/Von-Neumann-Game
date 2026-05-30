<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$limit = 100;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, (int) substr($argument, strlen('--limit=')));
    }
}

$factory = new AppFactory(dirname(__DIR__));
$scheduler = $factory->schedulerService();
$stats = $scheduler->processDueEvents($limit);

echo sprintf(
    "[%s] scheduled events: due=%d processed=%d failed=%d\n",
    gmdate('c'),
    $stats['due'],
    $stats['processed'],
    $stats['failed'],
);

exit($stats['failed'] > 0 ? 1 : 0);
