<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Service\MannyService;

require_once __DIR__ . '/../../vendor/autoload.php';

// Explicit repair of Chris's October 15 failed detachment; simulation by default.
$databaseConfig = 'config/database.json';
$backupPath = null;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--backup=')) {
        $backupPath = substr($argument, strlen('--backup='));
    } elseif ($argument === '--apply') {
        $apply = true;
    } else {
        throw new InvalidArgumentException('Expected --database-config=PATH [--apply --backup=NEW_FILE].');
    }
}
if ($apply && !$backupPath) { throw new InvalidArgumentException('Applying requires a new backup file.'); }
$factory = new AppFactory(dirname(__DIR__, 2));
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$probes = new NeumannProbeRepository($pdo, $factory->gameplayConfig());
$operation = function () use ($pdo, $factory, $probes, $apply, $backupPath): void {
    $query = $pdo->query("SELECT m.uid,m.name,m.current_task,m.location_type,e.*
        FROM mannies m JOIN neumann_probes p ON p.id=m.probe_id
        JOIN scheduled_events e ON e.id=m.task_scheduled_event_id AND e.entity_id=m.id
        WHERE m.id=10885 AND p.id=775 AND p.player_id=167 AND e.id=2240738"
        . ($apply && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['current_task'] !== 'detaching_storage_container' || $row['location_type'] !== 'probe'
        || $row['status'] !== 'failed' || $row['type'] !== 'manny.task' || $row['entity_type'] !== 'manny'
        || $row['last_error'] !== 'Storage transaction retries exhausted.') {
        throw new RuntimeException('Manny is not in the expected failed detachment state.');
    }
    $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $snapshot = $payload['snapshot'] ?? null;
    if (!is_array($snapshot) || (int) ($snapshot['ownerProbeId'] ?? 0) !== 775
        || (int) ($snapshot['ownerPlayerId'] ?? 0) !== 167 || empty($snapshot['sourceContainerId'])) {
        throw new RuntimeException('Missing or inconsistent container snapshot.');
    }
    $query = $pdo->prepare('SELECT COUNT(*) FROM detached_storage_containers WHERE object_id=?');
    $query->execute(['detached-container-' . $snapshot['sourceContainerId']]);
    if ((int) $query->fetchColumn() !== 0) {
        throw new RuntimeException('Container already detached; refusing to duplicate it.');
    }
    printf("manny=10885 name=%s probe=775 event=2240738 apply=%s\n", $row['name'], $apply ? 'yes' : 'no');
    if (!$apply) { return; }
    $backup = json_encode(['createdAt' => gmdate('c'), 'before' => $row], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $mask = umask(0077);
    try { $handle = fopen($backupPath, 'x'); } finally { umask($mask); }
    if ($handle === false) { throw new RuntimeException('Cannot create backup.'); }
    try {
        if (fwrite($handle, $backup) !== strlen($backup) || !fflush($handle) || !fsync($handle)) {
            throw new RuntimeException('Cannot persist backup.');
        }
    } finally { fclose($handle); }

    // Reuse the complete service graph without running any scheduler events.
    $scheduler = $factory->schedulerService($pdo);
    $service = (new ReflectionProperty($scheduler, 'mannyService'))->getValue($scheduler);
    if (!$service instanceof MannyService) { throw new RuntimeException('Manny service unavailable.'); }
    // Permit canonical payload clearing inside the same locked transaction.
    // Workers cannot observe pending status before the recall commits as done.
    $pdo->exec("UPDATE scheduled_events SET status='pending' WHERE id=2240738 AND status='failed'");
    $manny = $service->recallManny($probes->findById(775) ?? throw new RuntimeException('Probe missing.'), $row['uid']);
    if ($manny->currentTask !== null || !$manny->isOnProbe()) {
        throw new RuntimeException('Recall did not make the Manny available on board.');
    }
};
if ($apply) {
    $probes->withProbeLock(775, $operation);
    echo "Committed: Manny available on board; container restored through ordinary recall.\n";
} else {
    $operation();
}
