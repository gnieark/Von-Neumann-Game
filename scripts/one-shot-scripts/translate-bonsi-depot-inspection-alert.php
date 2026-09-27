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
        echo "Usage: php scripts/one-shot-scripts/translate-bonsi-depot-inspection-alert.php [--database-config=PATH] [--dry-run|--apply --backup=NEW_FILE]\n";
        exit(0);
    } else { throw new InvalidArgumentException('Unknown argument: ' . $argument); }
}
if ($apply && !$backupPath) { throw new InvalidArgumentException('--apply requires --backup=NEW_FILE.'); }

$french = "Votre Manny décrit une enveloppe parfaitement lisse, impossible à percer avec son outillage. Un impact pourrait permettre d'en savoir plus.";
$english = 'Your Manny reports a perfectly smooth shell that its tools cannot penetrate. An impact might reveal more.';
$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
$pdo->beginTransaction();
try {
    $query = $pdo->prepare('SELECT a.* FROM probe_damage_warnings a JOIN neumann_probes n ON n.id=a.probe_id JOIN players p ON p.id=n.player_id WHERE a.id=? AND a.probe_id=? AND p.id=? AND p.username=?' . $lock);
    $query->execute([6798, 185, 185, 'Bonsi']);
    $alert = $query->fetch(PDO::FETCH_ASSOC);
    if (!$alert || $alert['type'] !== 'manny_report' || $alert['phase'] !== 'manny_report'
        || $alert['object_id'] !== 'structure_ea6ad75c6b2afdc5f213d3aa'
        || $alert['created_at'] !== '2026-09-27T18:11:54+00:00'
        || !in_array($alert['message'], [$french, $english], true)) {
        throw new RuntimeException('Unexpected alert identity or text; no changes.');
    }
    $needsUpdate = $alert['message'] === $french;
    echo json_encode(['alertId' => 6798, 'apply' => $apply, 'needsUpdate' => $needsUpdate, 'message' => $english], JSON_THROW_ON_ERROR), "\n";
    if (!$apply || !$needsUpdate) {
        $pdo->rollBack();
        echo "No changes.\n";
        exit(0);
    }
    $json = json_encode(['savedAt' => gmdate('c'), 'alert' => $alert], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $oldUmask = umask(0077);
    try { $file = fopen($backupPath, 'x'); } finally { umask($oldUmask); }
    if ($file === false) { throw new RuntimeException('Cannot create exclusive backup file.'); }
    try {
        if (fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) { throw new RuntimeException('Incomplete backup; no changes.'); }
    } finally { fclose($file); }

    $update = $pdo->prepare('UPDATE probe_damage_warnings SET message=? WHERE id=? AND message=?');
    $update->execute([$english, 6798, $french]);
    if ($update->rowCount() !== 1) { throw new RuntimeException('Alert changed; rolling back.'); }
    $query->execute([6798, 185, 185, 'Bonsi']);
    $expected = $alert;
    $expected['message'] = $english;
    if ($query->fetch(PDO::FETCH_ASSOC) !== $expected) { throw new RuntimeException('Unexpected changes to alert; rolling back.'); }
    $pdo->commit();
    echo "Committed: alert 6798 translated; all other fields preserved.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $error;
}
