<?php
declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\ProbeOperationRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;

// Explicit repair of the October 22 recall attached to a failed mining event.
$root = dirname(__DIR__, 2);
$databaseConfig = 'config/database.json';
$backupPath = null;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--project-root=')) { $root = substr($argument, 15); }
    elseif (str_starts_with($argument, '--database-config=')) { $databaseConfig = substr($argument, 18); }
    elseif (str_starts_with($argument, '--backup=')) { $backupPath = substr($argument, 9); }
    elseif ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { /* Default. */ }
    else { throw new InvalidArgumentException('Usage: php reschedule-sonek-return.php [--project-root=PATH] [--database-config=PATH] [--apply --backup=NEW_FILE]'); }
}
if ($apply && !$backupPath) { throw new InvalidArgumentException('Applying requires --backup=NEW_FILE.'); }
require $root . '/vendor/autoload.php';
$factory = new AppFactory($root);
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$events = new ScheduledEventRepository($pdo);
$mannies = new MannyRepository($pdo, $factory->gameplayConfig(), $events);
$operation = static function () use ($pdo, $mannies, $events, $apply, $backupPath): void {
    $manny = $mannies->findById(10552);
    $probe = $pdo->query('SELECT id,player_id,status FROM neumann_probes WHERE id=994')->fetch(PDO::FETCH_ASSOC);
    $gate = $pdo->query('SELECT closed FROM probe_operation_gates WHERE probe_id=994')->fetchColumn();
    $event = $pdo->query('SELECT * FROM scheduled_events WHERE id=2767236' . ($apply && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''))->fetch(PDO::FETCH_ASSOC);
    $task = $pdo->query('SELECT * FROM manny_tasks WHERE manny_id=10552')->fetch(PDO::FETCH_ASSOC);
    if ($manny === null || $manny->uid !== 'mny_476dd53c07a9c5216e61050b' || $manny->probeId !== 994
        || !$probe || (int) $probe['player_id'] !== 763 || $probe['status'] !== 'idle' || $gate === false || (int) $gate !== 0
        || $manny->currentTask !== Manny::TASK_RETURNING || $manny->locationType !== Manny::LOCATION_SECTOR
        || $manny->taskScheduledEventId !== 2767236 || !$event || $event['status'] !== 'failed'
        || $event['type'] !== 'manny.task' || $event['entity_type'] !== 'manny' || (int) $event['entity_id'] !== 10552
        || $event['last_error'] !== 'detached_container_not_found' || $event['locked_by'] !== null || $event['locked_at'] !== null
        || !$task || $task['task_type'] !== Manny::TASK_RETURNING || (int) $task['scheduled_event_id'] !== 2767236
        || $manny->taskStartedAt !== '2026-10-22T11:02:35+00:00' || $manny->taskEndsAt !== '2026-10-22T11:17:35+00:00'
        || $manny->cargoDeuterium !== 0.0 || $manny->cargoMetals !== 0.0 || $manny->cargoIce !== 0.0 || $manny->cargoOrganicCompounds !== 0.0
        || $manny->reservedCargoSpace !== 0.0 || $manny->reservedCargoType !== null || $manny->reservedStorageContainerId !== null) {
        throw new RuntimeException('Sonek Manny is no longer in the audited failed-return state; no repair applied.');
    }
    $active = $pdo->query("SELECT COUNT(*) FROM scheduled_events WHERE entity_type='manny' AND entity_id=10552 AND status IN ('pending','running')")->fetchColumn();
    if ((int) $active !== 0) { throw new RuntimeException('An active event already exists for this Manny.'); }
    printf("manny=10552 probe=994 old_event=2767236 task=returning run_at=%s apply=%s\n", $manny->taskEndsAt, $apply ? 'yes' : 'no');
    if (!$apply) { return; }

    $backup = json_encode(['createdAt' => gmdate('c'), 'manny' => $pdo->query('SELECT * FROM mannies WHERE id=10552')->fetch(PDO::FETCH_ASSOC), 'task' => $task, 'event' => $event], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $mask = umask(0077);
    try { $handle = fopen($backupPath, 'x'); } finally { umask($mask); }
    if ($handle === false) { throw new RuntimeException('Cannot create exclusive backup.'); }
    try {
        if (fwrite($handle, $backup) !== strlen($backup) || !fflush($handle) || !fsync($handle)) { throw new RuntimeException('Cannot persist backup.'); }
    } finally { fclose($handle); }

    // Do not recycle the old mining payload or restart the elapsed return journey.
    $manny->taskScheduledEventId = null;
    $manny->taskPayload = ['reason' => 'recall'];
    $mannies->save($manny);
    $new = $events->findById($manny->taskScheduledEventId);
    if ($new === null || $new->status !== 'pending' || $new->runAt !== $manny->taskEndsAt || $new->payload !== ['reason' => 'recall']) {
        throw new RuntimeException('New return event failed validation.');
    }
    printf("new_event=%d status=pending backup=%s\n", $new->id, $backupPath);
};
if ($apply) {
    (new ProbeOperationRepository($pdo))->run([994], fn() => $mannies->withMannyLock(10552, $operation));
    echo "Committed: new return scheduled; failed mining event preserved.\n";
} else {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $pdo->exec('SET SESSION TRANSACTION READ ONLY'); }
    $pdo->beginTransaction();
    try { $operation(); } finally { $pdo->rollBack(); }
}
