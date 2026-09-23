<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../../vendor/autoload.php';

// Restore the two probes lost to intersector dust on 2026-09-22.
$expectedMovements = [973 => 19890, 974 => 19891];
$databaseConfig = 'config/database.json';
$apply = false;
$backupPath = null;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--dry-run') {
        $apply = false;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--backup=')) {
        $backupPath = substr($argument, strlen('--backup='));
    } elseif ($argument === '--help') {
        echo "Usage: php scripts/one-shot-scripts/restore-probes-973-974.php [--database-config=PATH] [--dry-run|--apply --backup=NEW_FILE]\n";
        exit(0);
    } else {
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }
}

if ($apply && $backupPath === null) {
    throw new InvalidArgumentException('--apply requires --backup=NEW_FILE.');
}

$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($databaseConfig, initializeSchema: false);
$lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
$pdo->beginTransaction();

try {
    $probes = [];
    $movements = [];
    foreach ($expectedMovements as $probeId => $movementId) {
        $stmt = $pdo->prepare('SELECT * FROM neumann_probes WHERE id = ?' . $lock);
        $stmt->execute([$probeId]);
        $probe = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT * FROM probe_movements WHERE id = ? AND probe_id = ?' . $lock);
        $stmt->execute([$movementId, $probeId]);
        $movement = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($probe === false || $movement === false
            || (int) $probe['player_id'] !== 477
            || $probe['status'] !== 'dead'
            || (float) $probe['integrity_percent'] !== 0.0
            || (int) $probe['exclude_from_stats'] !== 1
            || $probe['current_task'] !== null
            || $movement['status'] !== 'destroyed'
            || $movement['destruction_reason'] !== 'Hull integrity exhausted by intersector dust'
            || $probe['sector_x'] !== $movement['target_x']
            || $probe['sector_y'] !== $movement['target_y']
            || $probe['sector_z'] !== $movement['target_z']) {
            throw new RuntimeException("Unexpected state for probe $probeId; no changes.");
        }

        $probes[$probeId] = $probe;
        $movements[$movementId] = $movement;
    }

    echo json_encode(['apply' => $apply, 'probeIds' => array_keys($probes), 'newStatus' => 'idle', 'newIntegrityPercent' => 100, 'excludeFromStats' => false], JSON_THROW_ON_ERROR), "\n";
    if (!$apply) {
        $pdo->rollBack();
        echo "No changes.\n";
        exit(0);
    }

    $backup = json_encode(['probes' => $probes, 'movements' => $movements], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $oldUmask = umask(0077);
    try {
        $file = fopen($backupPath, 'x');
    } finally {
        umask($oldUmask);
    }
    if ($file === false) {
        throw new RuntimeException('Cannot create exclusive backup file.');
    }
    try {
        if (fwrite($file, $backup) !== strlen($backup) || !fflush($file)) {
            throw new RuntimeException('Incomplete backup; no changes.');
        }
    } finally {
        fclose($file);
    }

    $stmt = $pdo->prepare("UPDATE neumann_probes SET status = 'idle', integrity_percent = 100, exclude_from_stats = 0, updated_at = ? WHERE id = ? AND status = 'dead' AND integrity_percent = 0 AND exclude_from_stats = 1");
    $now = gmdate('c');
    foreach (array_keys($probes) as $probeId) {
        $stmt->execute([$now, $probeId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("Concurrent change to probe $probeId; rolling back.");
        }
    }
    $pdo->commit();
    echo "Restored probes 973 and 974.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
