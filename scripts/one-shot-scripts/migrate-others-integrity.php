<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) { $databaseConfig = substr($argument, 18); }
    else { fwrite(STDERR, "Usage: php scripts/one-shot-scripts/migrate-others-integrity.php [--database-config=PATH]\n"); exit(2); }
}
$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$pdo->beginTransaction();
try {
    $updated = $pdo->exec("UPDATE others_ships SET max_integrity = CASE type WHEN 'mothership' THEN 100 WHEN 'standard' THEN 20 ELSE max_integrity END, integrity = CASE type WHEN 'mothership' THEN 100 WHEN 'standard' THEN 20 ELSE integrity END WHERE integrity IS NULL OR max_integrity IS NULL");
    $pdo->commit(); echo "Initialized integrity for {$updated} Others ship(s).\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); } fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
