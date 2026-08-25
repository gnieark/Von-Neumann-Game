<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\PlayerRepository;

require_once __DIR__ . '/../../vendor/autoload.php';

$playerId = null; $databaseConfig = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--player-id=')) { $playerId = (int) substr($argument, 12); }
    elseif (str_starts_with($argument, '--database-config=')) { $databaseConfig = substr($argument, 18); }
    else { fwrite(STDERR, "Usage: php scripts/one-shot-scripts/migrate-others-fleet-owner.php --player-id=ID [--database-config=PATH]\n"); exit(2); }
}
if ($playerId === null || $playerId <= 0) { fwrite(STDERR, "An explicit valid --player-id is required.\n"); exit(2); }
$factory = new AppFactory(dirname(__DIR__, 2));
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$players = new PlayerRepository($pdo);
if ($players->findById($playerId) === null) { fwrite(STDERR, "Player not found.\n"); exit(1); }
$pdo->beginTransaction();
try {
    $updated = $pdo->exec('UPDATE others_fleets SET player_id = ' . $playerId . ' WHERE player_id IS NULL');
    $players->setOthersControl($playerId, true);
    $pdo->commit();
    echo "Attached {$updated} Others fleet(s) to player {$playerId}.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
