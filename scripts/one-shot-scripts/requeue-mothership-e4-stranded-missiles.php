<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

// The eighteen impacts left failed after the fatal missile was repaired separately.
$missiles = [
    1818163 => 'missile_2ab1a3f5f80f6756ca47',
    1818164 => 'missile_bd6dec7d405bef28cb25',
    1818165 => 'missile_c076fc9ad20d9631008c',
    1818166 => 'missile_434ece6cc1ea5f5284c1',
    1818167 => 'missile_8b13f86b1e38b6497d69',
    1818168 => 'missile_31949659a8ba01250a78',
    1818203 => 'missile_a0e344cf1d8d01a98945',
    1818205 => 'missile_ac7bc68975f22f17431f',
    1818207 => 'missile_0c989a257266fe8f18b0',
    1818283 => 'missile_e5f9929ad881f4e91f3b',
    1818285 => 'missile_1bb2937cf88fdb40a1c1',
    1820144 => 'missile_a568673a448e87920ed1',
    1820145 => 'missile_91c5d89be0f4ae82f3e5',
    1820146 => 'missile_918ab2ebc0b7709af1b4',
    1820147 => 'missile_5e40ec31dd3207ec7587',
    1820148 => 'missile_6a81ca6c36fec7cb0f57',
    1820149 => 'missile_76b654902e39155b9513',
    1820150 => 'missile_9cea51d5097a17010c98',
];
$shipPublicId = 'mother_e4e6495132d2b1db10f7';
$databaseConfig = 'config/database.json';
$apply = false;
$backupPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { $apply = false; }
    elseif (str_starts_with($argument, '--database-config=')) { $databaseConfig = substr($argument, strlen('--database-config=')); }
    elseif (str_starts_with($argument, '--backup=')) { $backupPath = substr($argument, strlen('--backup=')); }
    elseif ($argument === '--help') {
        echo "Usage: php scripts/one-shot-scripts/requeue-mothership-e4-stranded-missiles.php [--database-config=PATH] [--dry-run|--apply --backup=NEW_FILE]\n";
        exit(0);
    } else { throw new InvalidArgumentException('Unknown argument: ' . $argument); }
}
if ($apply && !$backupPath) { throw new InvalidArgumentException('--apply requires --backup=NEW_FILE.'); }

$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT status,destroyed_at FROM others_ships WHERE public_id=?' . $lock);
    $stmt->execute([$shipPublicId]);
    $ship = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ship || $ship['status'] !== 'destroyed' || $ship['destroyed_at'] === null) {
        throw new RuntimeException('Expected destroyed mothership; no changes.');
    }

    $events = [];
    foreach ($missiles as $eventId => $missileId) {
        $stmt = $pdo->prepare('SELECT * FROM scheduled_events WHERE id=?' . $lock);
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event || $event['type'] !== 'missile.projectile' || $event['entity_type'] !== 'missile_projectile'
            || (json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR)['projectileId'] ?? null) !== $missileId) {
            throw new RuntimeException("Unexpected event identity: $eventId; no changes.");
        }
        $stmt = $pdo->prepare('SELECT p.public_id,p.status,p.target_kind,p.target_public_id,l.status AS launch_status,l.scheduled_event_id FROM others_projectiles p JOIN missile_launches l ON l.id=p.launch_id WHERE p.id=?' . $lock);
        $stmt->execute([$event['entity_id']]);
        $projectile = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($event['status'] === 'done' && !$projectile) { continue; }
        if (!$projectile || $projectile['public_id'] !== $missileId || $projectile['target_public_id'] !== $shipPublicId
            || $projectile['target_kind'] !== 'others_ship' || $projectile['status'] !== 'moving'
            || $projectile['launch_status'] !== 'launched' || (int) $projectile['scheduled_event_id'] !== $eventId) {
            throw new RuntimeException("Unexpected projectile state: $missileId; no changes.");
        }
        if (in_array($event['status'], ['pending', 'running'], true)) { continue; }
        if ($event['status'] !== 'failed' || !str_contains((string) $event['last_error'], 'others_swarm_participants')) {
            throw new RuntimeException("Unexpected failure: $eventId; no changes.");
        }
        $events[] = $event;
    }

    echo json_encode(['apply' => $apply, 'requeueCount' => count($events), 'eventIds' => array_column($events, 'id')], JSON_THROW_ON_ERROR), "\n";
    if (!$apply || $events === []) {
        $pdo->rollBack();
        echo "No changes.\n";
        exit(0);
    }
    $backup = json_encode(['targetId' => $shipPublicId, 'events' => $events], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $oldUmask = umask(0077);
    try { $file = fopen($backupPath, 'x'); } finally { umask($oldUmask); }
    if ($file === false) { throw new RuntimeException('Cannot create exclusive backup file.'); }
    try {
        if (fwrite($file, $backup) !== strlen($backup) || !fflush($file)) { throw new RuntimeException('Incomplete backup; no changes.'); }
    } finally { fclose($file); }

    $now = gmdate('c');
    $stmt = $pdo->prepare("UPDATE scheduled_events SET status='pending',run_at=?,locked_at=NULL,locked_by=NULL,processed_at=NULL,last_error=NULL,updated_at=? WHERE id=? AND status='failed'");
    foreach ($events as $event) {
        $stmt->execute([$now, $now, $event['id']]);
        if ($stmt->rowCount() !== 1) { throw new RuntimeException('Concurrent event change; rolling back.'); }
    }
    $pdo->commit();
    echo 'Committed: ', count($events), " impact events pending and due now. Historical impact times and attempt counts preserved.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $error;
}
