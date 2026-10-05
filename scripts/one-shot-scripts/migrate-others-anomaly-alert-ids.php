<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

$databaseConfig = 'config/database.json';
$apply = false;
$backupPath = null;
$fleetId = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $apply = true; }
    elseif ($argument === '--dry-run') { $apply = false; }
    elseif (str_starts_with($argument, '--database-config=')) { $databaseConfig = substr($argument, strlen('--database-config=')); }
    elseif (str_starts_with($argument, '--backup=')) { $backupPath = substr($argument, strlen('--backup=')); }
    elseif (str_starts_with($argument, '--fleet-id=')) { $fleetId = substr($argument, strlen('--fleet-id=')); }
    elseif ($argument === '--help') {
        echo "Usage: php scripts/one-shot-scripts/migrate-others-anomaly-alert-ids.php [--database-config=PATH] [--fleet-id=ID] [--dry-run|--apply --backup=NEW_FILE]\n";
        echo "Deploy the canonical alert generator first. Only public identifiers change; alerts remain unread/read as before.\n";
        exit(0);
    } else { throw new InvalidArgumentException('Unknown argument: ' . $argument); }
}
if ($apply && !$backupPath) { throw new InvalidArgumentException('--apply requires --backup=NEW_FILE.'); }

$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
$pdo->beginTransaction();
try {
    $parameters = [];
    $scope = '';
    if ($fleetId !== null) {
        $fleet = $pdo->prepare('SELECT id FROM others_fleets WHERE public_id=?');
        $fleet->execute([$fleetId]);
        $internalFleetId = $fleet->fetchColumn();
        if ($internalFleetId === false) { throw new RuntimeException('Fleet not found; no changes.'); }
        $scope = ' AND ship_public_id IN (SELECT public_id FROM others_ships WHERE fleet_id=?)';
        $parameters[] = $internalFleetId;
    }
    $select = $pdo->prepare("SELECT * FROM others_alerts WHERE type='anomaly_detected'" . $scope . ' ORDER BY id' . $lock);
    $select->execute($parameters);
    $repairs = [];
    $targets = [];
    $collision = $pdo->prepare('SELECT id FROM others_alerts WHERE public_id=?');
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $alert) {
        if (preg_match('/^oalert_[a-f0-9]{20}$/D', $alert['public_id']) === 1) { continue; }
        if (preg_match('/^oalert_[a-f0-9]{24}$/D', $alert['public_id']) !== 1 || $alert['phase'] !== 'detection') {
            throw new RuntimeException('Unexpected anomaly alert format; no changes.');
        }
        $canonicalId = substr($alert['public_id'], 0, 27);
        $collision->execute([$canonicalId]);
        if (isset($targets[$canonicalId]) || $collision->fetchColumn() !== false) {
            throw new RuntimeException('Canonical identifier collision; no changes.');
        }
        $targets[$canonicalId] = true;
        $repairs[] = ['before' => $alert, 'publicId' => $canonicalId];
    }
    echo json_encode(['apply' => $apply, 'fleetId' => $fleetId, 'alerts' => count($repairs)], JSON_THROW_ON_ERROR), "\n";
    if (!$apply || $repairs === []) {
        $pdo->rollBack();
        echo "No changes.\n";
        exit(0);
    }
    $json = json_encode(['savedAt' => gmdate('c'), 'fleetId' => $fleetId, 'repairs' => $repairs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $oldUmask = umask(0077);
    try { $file = fopen($backupPath, 'x'); } finally { umask($oldUmask); }
    if ($file === false) { throw new RuntimeException('Cannot create exclusive backup file; no changes.'); }
    try {
        if (fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) {
            throw new RuntimeException('Incomplete backup; no changes.');
        }
    } finally { fclose($file); }
    $update = $pdo->prepare('UPDATE others_alerts SET public_id=? WHERE id=? AND public_id=?');
    $check = $pdo->prepare('SELECT * FROM others_alerts WHERE id=?');
    foreach ($repairs as $repair) {
        $before = $repair['before'];
        $update->execute([$repair['publicId'], $before['id'], $before['public_id']]);
        if ($update->rowCount() !== 1) { throw new RuntimeException('Alert changed; rolling back.'); }
        $check->execute([$before['id']]);
        $expected = $before;
        $expected['public_id'] = $repair['publicId'];
        if ($check->fetch(PDO::FETCH_ASSOC) !== $expected) { throw new RuntimeException('Unexpected changes to alert; rolling back.'); }
    }
    $pdo->commit();
    echo 'Committed: ' . count($repairs) . " canonical alert identifiers; all other fields preserved.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $error;
}
