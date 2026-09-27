<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
$apply = false;
$backupPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { $apply = false; }
    elseif (str_starts_with($argument, '--database-config=')) { $databaseConfig = substr($argument, strlen('--database-config=')); }
    elseif (str_starts_with($argument, '--backup=')) { $backupPath = substr($argument, strlen('--backup=')); }
    elseif ($argument === '--help') {
        echo "Usage: php scripts/one-shot-scripts/requeue-bonsi-missile-preparations.php [--database-config=PATH] [--dry-run|--apply --backup=NEW_FILE]\n";
        exit(0);
    } else { throw new InvalidArgumentException('Unknown argument: ' . $argument); }
}
if ($apply && !$backupPath) { throw new InvalidArgumentException('--apply requires --backup=NEW_FILE.'); }

// Only the three preparations audited on Bonsi's Babylon 5 on 2026-09-27.
$repairs = [
    ['manny' => 7412, 'event' => 2006856, 'launch' => 468, 'publicId' => 'missile_1253f512192d2a66f209', 'item' => 764128, 'previous' => 434],
    ['manny' => 7583, 'event' => 2006855, 'launch' => 467, 'publicId' => 'missile_8df5ad6075fcdca0e658', 'item' => 764127, 'previous' => 433],
    ['manny' => 7951, 'event' => 2006857, 'launch' => 469, 'publicId' => 'missile_778e89cd71a69d3b4aec', 'item' => 764129, 'previous' => 435],
];
$targetId = 'ship_0f01bb4b09df96e6eaf8';
$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
$read = static function (string $sql, array $parameters) use ($pdo, $lock): array {
    $statement = $pdo->prepare($sql . $lock);
    $statement->execute($parameters);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('Expected repair row not found.');
};
$pdo->beginTransaction();
try {
    $probe = $read('SELECT n.* FROM neumann_probes n JOIN players p ON p.id=n.player_id WHERE n.id=? AND p.id=? AND p.username=?', [185, 185, 'Bonsi']);
    $backup = ['createdAt' => gmdate('c'), 'probe' => $probe, 'repairs' => []];
    $detach = [];
    $requeue = [];
    foreach ($repairs as $repair) {
        $manny = $read('SELECT * FROM mannies WHERE id=? AND probe_id=?', [$repair['manny'], 185]);
        $event = $read('SELECT * FROM scheduled_events WHERE id=?', [$repair['event']]);
        $launch = $read('SELECT * FROM missile_launches WHERE id=?', [$repair['launch']]);
        $previous = $read('SELECT * FROM missile_launches WHERE id=?', [$repair['previous']]);
        if ($launch['public_id'] !== $repair['publicId'] || (int) $launch['player_id'] !== 185
            || (int) $launch['probe_id'] !== 185 || (int) $launch['manny_id'] !== $repair['manny']
            || (int) $launch['scheduled_event_id'] !== $repair['event']
            || $launch['target_kind'] !== 'others_ship' || $launch['target_public_id'] !== $targetId
            || $event['type'] !== 'manny.task' || $event['entity_type'] !== 'manny' || (int) $event['entity_id'] !== $repair['manny']
            || ($event['status'] !== 'done' && (json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR)['missileLaunchId'] ?? null) !== $repair['publicId'])
            || $previous['status'] !== 'failed' || $previous['result'] !== 'launch_preconditions_lost'
            || (int) $previous['probe_id'] !== 185 || (int) $previous['player_id'] !== 185
            || ($previous['probe_item_id'] !== null && (int) $previous['probe_item_id'] !== $repair['item'])) {
            throw new RuntimeException('Unexpected repair identity or historical state; no changes.');
        }
        $snapshot = compact('manny', 'event', 'launch', 'previous');
        if ($previous['probe_item_id'] !== null) { $detach[] = $repair['previous']; }
        if ($event['status'] === 'failed') {
            $task = $read('SELECT * FROM manny_tasks WHERE manny_id=? AND scheduled_event_id=?', [$repair['manny'], $repair['event']]);
            $item = $read("SELECT * FROM probe_items WHERE id=? AND probe_id=? AND type='missile'", [$repair['item'], 185]);
            if ($manny['current_task'] !== 'preparing_missile' || (int) $manny['task_scheduled_event_id'] !== $repair['event']
                || $task['task_type'] !== 'preparing_missile' || $task['target_object_id'] !== $targetId
                || $launch['status'] !== 'preparing' || (int) $launch['probe_item_id'] !== $repair['item']
                || !str_contains((string) $event['last_error'], '1451')
                || !str_contains((string) $event['last_error'], 'FOREIGN KEY (`probe_item_id`)')
                || !str_contains((string) $event['last_error'], 'missile_launches')
                || strtotime((string) $manny['task_ends_at']) === false || strtotime($manny['task_ends_at']) > time()) {
                throw new RuntimeException('Unexpected failed preparation; no changes.');
            }
            $snapshot += compact('task', 'item');
            $requeue[] = $repair['event'];
        } elseif (!(in_array($event['status'], ['pending', 'running'], true) && $launch['status'] === 'preparing')
            && !($event['status'] === 'done' && $launch['status'] === 'failed' && $launch['result'] === 'launch_preconditions_lost' && $launch['probe_item_id'] === null)) {
            throw new RuntimeException('Unexpected replay state; no changes.');
        }
        $backup['repairs'][] = $snapshot;
    }
    $target = $read('SELECT public_id,status,destroyed_at FROM others_ships WHERE public_id=?', [$targetId]);
    if ($target['status'] !== 'destroyed' || $target['destroyed_at'] === null) {
        throw new RuntimeException('Expected destroyed target; no changes.');
    }
    $backup['target'] = $target;
    echo json_encode(['apply' => $apply, 'detachLaunchIds' => $detach, 'requeueEventIds' => $requeue], JSON_THROW_ON_ERROR), "\n";
    if (!$apply || ($detach === [] && $requeue === [])) {
        $pdo->rollBack();
        echo "No changes.\n";
        exit(0);
    }
    $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $oldUmask = umask(0077);
    try { $file = fopen($backupPath, 'x'); } finally { umask($oldUmask); }
    if ($file === false) { throw new RuntimeException('Cannot create exclusive backup file.'); }
    try {
        if (fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) { throw new RuntimeException('Incomplete backup; no changes.'); }
    } finally { fclose($file); }

    $now = gmdate('c');
    $update = $pdo->prepare("UPDATE missile_launches SET probe_item_id=NULL,updated_at=? WHERE id=? AND status='failed' AND probe_item_id IS NOT NULL");
    foreach ($detach as $id) {
        $update->execute([$now, $id]);
        if ($update->rowCount() !== 1) { throw new RuntimeException('Historical launch changed; rolling back.'); }
    }
    $update = $pdo->prepare("UPDATE scheduled_events SET status='pending',run_at=?,locked_at=NULL,locked_by=NULL,processed_at=NULL,last_error=NULL,updated_at=? WHERE id=? AND status='failed'");
    foreach ($requeue as $id) {
        $update->execute([$now, $now, $id]);
        if ($update->rowCount() !== 1) { throw new RuntimeException('Event changed; rolling back.'); }
    }
    $pdo->commit();
    echo 'Committed: ', count($detach), ' historical references released; ', count($requeue), " events requeued.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $error;
}
