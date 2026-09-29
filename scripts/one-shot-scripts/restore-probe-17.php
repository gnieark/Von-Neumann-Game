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
        echo "Usage: php scripts/one-shot-scripts/restore-probe-17.php [--database-config=PATH] [--dry-run|--apply --backup=NEW_FILE]\n";
        exit(0);
    } else { throw new InvalidArgumentException('Unknown argument: ' . $argument); }
}
if ($apply && !$backupPath) { throw new InvalidArgumentException('--apply requires --backup=NEW_FILE.'); }

$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
$pdo->beginTransaction();
try {
    $query = $pdo->prepare('SELECT * FROM neumann_probes WHERE id=17' . $lock);
    $query->execute();
    $probe = $query->fetch(PDO::FETCH_ASSOC);
    $query = $pdo->prepare('SELECT * FROM probe_movements WHERE probe_id=17 ORDER BY id DESC LIMIT 1' . $lock);
    $query->execute();
    $movement = $query->fetch(PDO::FETCH_ASSOC);
    if (!$probe || !$movement || (int) $probe['player_id'] !== 17
        || (int) $movement['id'] !== 21929 || $movement['status'] !== 'destroyed'
        || $movement['destruction_reason'] !== 'Hull integrity exhausted by intersector dust'
        || $probe['current_task'] !== null || (float) $probe['velocity_c'] !== 0.0 || (float) $probe['acceleration_c_per_day'] !== 0.0
        || [$probe['sector_x'], $probe['sector_y'], $probe['sector_z']] !== [$movement['target_x'], $movement['target_y'], $movement['target_z']]
        || !(($probe['status'] === 'dead' && (float) $probe['integrity_percent'] === 0.0)
            || ($probe['status'] === 'idle' && (float) $probe['integrity_percent'] === 100.0))) {
        throw new RuntimeException('Unexpected probe or movement state; no changes.');
    }
    $activeMovements = (int) $pdo->query("SELECT COUNT(*) FROM probe_movements WHERE probe_id=17 AND status IN ('preparing','accelerating','cruising','decelerating')")->fetchColumn();
    $activeMannies = (int) $pdo->query('SELECT COUNT(*) FROM mannies WHERE probe_id=17 AND current_task IS NOT NULL')->fetchColumn();
    if ($activeMovements !== 0 || $activeMannies !== 0) { throw new RuntimeException('Active tasks found; no changes.'); }
    $needsUpdate = $probe['status'] === 'dead';
    echo json_encode(['probeId' => 17, 'name' => $probe['name'], 'apply' => $apply, 'needsUpdate' => $needsUpdate, 'newStatus' => 'idle', 'newIntegrityPercent' => 100], JSON_THROW_ON_ERROR), "\n";
    if (!$apply || !$needsUpdate) {
        $pdo->rollBack();
        echo "No changes.\n";
        exit(0);
    }
    $json = json_encode(['savedAt' => gmdate('c'), 'probe' => $probe, 'movement' => $movement], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $oldUmask = umask(0077);
    try { $file = fopen($backupPath, 'x'); } finally { umask($oldUmask); }
    if ($file === false) { throw new RuntimeException('Cannot create exclusive backup file.'); }
    try {
        if (fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) { throw new RuntimeException('Incomplete backup; no changes.'); }
    } finally { fclose($file); }

    $now = gmdate('c');
    $update = $pdo->prepare("UPDATE neumann_probes SET status='idle',integrity_percent=100,updated_at=? WHERE id=17 AND status='dead' AND integrity_percent=0");
    $update->execute([$now]);
    if ($update->rowCount() !== 1) { throw new RuntimeException('Probe changed; rolling back.'); }
    $expected = $probe;
    $expected['status'] = 'idle';
    $expected['integrity_percent'] = 100;
    $expected['updated_at'] = $now;
    if ($pdo->query('SELECT * FROM neumann_probes WHERE id=17')->fetch(PDO::FETCH_ASSOC) != $expected) {
        throw new RuntimeException('Unexpected restoration result; rolling back.');
    }
    $pdo->commit();
    echo "Restored probe 17 at 100% integrity; all other probe fields and movement history preserved.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $error;
}
