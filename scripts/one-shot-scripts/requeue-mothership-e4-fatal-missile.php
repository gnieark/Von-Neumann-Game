<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

// Explicit repair of the September 16, 2026 failed mothership destruction.
$shipPublicId = 'mother_e4e6495132d2b1db10f7';
$missilePublicId = 'missile_1e4f658ff254d3822c9c';
$eventId = 1820151;
$databaseConfig = 'config/database.json';
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { $apply = false; }
    elseif (str_starts_with($argument, '--database-config=')) { $databaseConfig = substr($argument, strlen('--database-config=')); }
    elseif ($argument === '--help') {
        echo "Usage: php scripts/one-shot-scripts/requeue-mothership-e4-fatal-missile.php [--database-config=PATH] [--dry-run|--apply]\n";
        exit(0);
    } else { throw new InvalidArgumentException('Unknown argument: ' . $argument); }
}

$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM others_ships WHERE public_id=?' . $lock);
    $stmt->execute([$shipPublicId]);
    $ship = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT * FROM scheduled_events WHERE id=?' . $lock);
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ship || !$event) { throw new RuntimeException('Expected mothership and event not found.'); }
    if ($ship['status'] === 'destroyed' && $ship['destroyed_at'] !== null && $event['status'] === 'done') {
        $pdo->rollBack();
        echo "Destruction already completed; no changes.\n";
        exit(0);
    }
    if ($ship['type'] !== 'mothership' || $ship['destroyed_at'] !== null || $ship['status'] !== 'inactive'
        || (int) $ship['max_integrity'] !== 100 || (int) $ship['integrity'] < 1) {
        throw new RuntimeException('Unexpected mothership state; no changes.');
    }
    if ($event['type'] !== 'missile.projectile' || $event['entity_type'] !== 'missile_projectile'
        || $event['status'] !== 'failed' || !str_contains((string) $event['last_error'], 'others_swarm_participants')) {
        throw new RuntimeException('Event is not the expected failed fatal missile; no changes.');
    }
    $stmt = $pdo->prepare('SELECT p.*,l.status AS launch_status,l.scheduled_event_id FROM others_projectiles p JOIN missile_launches l ON l.id=p.launch_id WHERE p.id=?' . $lock);
    $stmt->execute([$event['entity_id']]);
    $projectile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$projectile || $projectile['public_id'] !== $missilePublicId || $projectile['target_public_id'] !== $shipPublicId
        || $projectile['target_kind'] !== 'others_ship' || $projectile['status'] !== 'moving'
        || $projectile['launch_status'] !== 'launched' || (int) $projectile['scheduled_event_id'] !== $eventId
        || json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR)['projectileId'] !== $missilePublicId) {
        throw new RuntimeException('Missile identity or state mismatch; no changes.');
    }
    foreach (['sector_x', 'sector_y', 'sector_z'] as $axis) {
        if ((int) $ship[$axis] !== (int) $projectile[$axis]) { throw new RuntimeException('Missile target has moved; no changes.'); }
    }
    $roll = hexdec(substr(hash('sha256', $missilePublicId . '|' . $shipPublicId . '|' . $projectile['impact_at'] . '|hit'), 0, 8)) / 4294967296;
    if ($roll >= (!empty($ship['departure_engaged']) ? 0.5 : 0.95)) { throw new RuntimeException('Missile would miss; no changes.'); }
    echo json_encode(['apply' => $apply, 'shipId' => $shipPublicId, 'integrityBefore' => (int) $ship['integrity'], 'integrityAfter' => 1, 'eventId' => $eventId, 'eventBefore' => $event, 'missileId' => $missilePublicId, 'originalImpactAt' => $projectile['impact_at']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    if (!$apply) {
        $pdo->rollBack();
        echo "Dry run; no changes.\n";
        exit(0);
    }
    $now = gmdate('c');
    $stmt = $pdo->prepare('UPDATE others_ships SET integrity=1,updated_at=? WHERE id=? AND destroyed_at IS NULL');
    $stmt->execute([$now, $ship['id']]);
    $stmt = $pdo->prepare("UPDATE scheduled_events SET status='pending',run_at=?,locked_at=NULL,locked_by=NULL,processed_at=NULL,last_error=NULL,updated_at=? WHERE id=? AND status='failed'");
    $stmt->execute([$now, $now, $eventId]);
    if ($stmt->rowCount() !== 1) { throw new RuntimeException('Concurrent event change; rolling back.'); }
    $pdo->commit();
    echo "Committed: mothership integrity=1/100; event 1820151 pending and due now. Historical impact time and attempt count preserved.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $error;
}
