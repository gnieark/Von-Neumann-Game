<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Database\SchemaInitializer;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
        continue;
    }
    if ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/one-shot-scripts/migrate-others-known-depots.php [--database-config=PATH]\n";
        echo "Creates fleet depot knowledge. Discovery starts with subsequent ship arrivals or completed constructions; no historical data is inferred.\n";
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

try {
    $factory = new AppFactory(dirname(__DIR__, 2));
    $pdo = $factory->pdo($databaseConfig, initializeSchema: false);
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['sqlite', 'mysql'], true)) {
        throw new RuntimeException("Unsupported database driver: {$driver}");
    }
    $pdo->exec((new SchemaInitializer($driver))->othersKnownDepotsStatement());
    echo "Others known-depot schema ready. Existing discoveries preserved; new discoveries recorded on arrival or completed construction.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Others known-depot migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
