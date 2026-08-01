<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\CraftingRecipeCatalog;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeImprovementRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\StorageContainerRepository;
use VonNeumannGame\Service\ProbeStorageService;

require_once __DIR__ . '/../vendor/autoload.php';

$databaseConfig = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--database-config=')) {
        $databaseConfig = substr($argument, strlen('--database-config='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php scripts/migrate-active-crafts-to-output-reservations.php [--dry-run] [--database-config=PATH]\n";
        echo "The scheduler must be stopped while this migration runs.\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$root = dirname(__DIR__);
$factory = new AppFactory($root);
$pdo = $factory->pdo($databaseConfig, initializeSchema: false);
$gameplay = $factory->gameplayConfig();
$probes = new NeumannProbeRepository($pdo, $gameplay);
$mannies = new MannyRepository($pdo, $gameplay);
$storage = new ProbeStorageService(
    new StorageContainerRepository($pdo, $gameplay),
    new ProbeItemRepository($pdo),
    $mannies,
    $probes,
    $gameplay,
    new ProbeImprovementRepository($pdo),
);

$rows = $pdo->query(
    "SELECT m.id, m.probe_id, m.current_task, m.reserved_cargo_type,
            m.reserved_cargo_space, m.reserved_storage_container_id,
            se.id AS event_id, se.status AS event_status, se.payload_json
     FROM mannies m
     INNER JOIN scheduled_events se ON se.id = m.task_scheduled_event_id
     WHERE m.current_task IN ('crafting', 'assisting_atomic_printer')
       AND se.type = 'manny.task'
       AND se.entity_type = 'manny'
       AND se.status IN ('pending', 'running', 'failed')
     ORDER BY m.probe_id, m.id"
)->fetchAll(PDO::FETCH_ASSOC);

$pdo->beginTransaction();
try {
    $updateManny = $pdo->prepare(
        'UPDATE mannies
         SET reserved_cargo_type = :type, reserved_cargo_space = :space,
             reserved_storage_container_id = :container_id, updated_at = :updated_at
         WHERE id = :id AND current_task IN (\'crafting\', \'assisting_atomic_printer\')'
    );
    $clearMannyReservation = $pdo->prepare(
        'UPDATE mannies SET reserved_cargo_type = NULL, reserved_cargo_space = 0,
             reserved_storage_container_id = NULL, updated_at = :updated_at
         WHERE id = :id AND current_task IN (\'crafting\', \'assisting_atomic_printer\')'
    );
    $updateEvent = $pdo->prepare(
        "UPDATE scheduled_events
         SET status = 'pending', locked_at = NULL, processed_at = NULL,
             last_error = NULL, updated_at = :updated_at
         WHERE id = :id AND status IN ('pending', 'running', 'failed')"
    );
    $containerExists = $pdo->prepare(
        'SELECT COUNT(*) FROM storage_containers WHERE id = :container_id AND probe_id = :probe_id'
    );
    $now = gmdate('c');
    $changedReservations = 0;
    $resetEvents = 0;

    printf("Active craft reservation migration: active=%d dry_run=%s\n", count($rows), $dryRun ? 'yes' : 'no');
    foreach ($rows as $row) {
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid event payload for Manny ' . $row['id']);
        }
        $output = $payload['output'] ?? null;
        if (!is_array($output)) {
            $recipeId = (string) ($payload['recipe'] ?? '');
            $recipe = CraftingRecipeCatalog::find($recipeId, $gameplay);
            $output = is_array($recipe['output'] ?? null) ? $recipe['output'] : null;
        }
        $type = is_array($output) ? (string) ($output['type'] ?? '') : '';
        if ($type === '') {
            throw new RuntimeException('Unable to resolve crafting output for Manny ' . $row['id']);
        }
        $space = round(max(0.0, (float) ($output['containerSpace'] ?? 0.0)), 4);
        $containerId = $row['reserved_storage_container_id'] !== null
            ? (int) $row['reserved_storage_container_id']
            : null;
        $valid = $containerId !== null
            && (string) ($row['reserved_cargo_type'] ?? '') === $type
            && abs((float) $row['reserved_cargo_space'] - $space) < 0.00001;
        if ($valid) {
            $containerExists->execute(['container_id' => $containerId, 'probe_id' => (int) $row['probe_id']]);
            $valid = (int) $containerExists->fetchColumn() === 1;
        }

        if (!$valid) {
            // Do not let an obsolete partial reservation count against the new
            // capacity calculation. The enclosing transaction makes this atomic.
            $clearMannyReservation->execute(['id' => (int) $row['id'], 'updated_at' => $now]);
            $probe = $probes->findById((int) $row['probe_id'])
                ?? throw new RuntimeException('Probe not found for Manny ' . $row['id']);
            $containerId = $storage->reserveCraftingOutput($probe, $type, $space);
            if ($containerId === null) {
                throw new RuntimeException('No destination container found for Manny ' . $row['id']);
            }
            $updateManny->execute([
                'id' => (int) $row['id'],
                'type' => $type,
                'space' => $space,
                'container_id' => $containerId,
                'updated_at' => $now,
            ]);
            if ($updateManny->rowCount() !== 1) {
                throw new RuntimeException('Concurrent change detected for Manny ' . $row['id']);
            }
            $changedReservations++;
        }

        $updateEvent->execute(['id' => (int) $row['event_id'], 'updated_at' => $now]);
        $resetEvents += $updateEvent->rowCount();
        printf(
            "manny=%d event=%d previous_status=%s output=%s space=%.4f container=%d reservation=%s\n",
            $row['id'],
            $row['event_id'],
            $row['event_status'],
            $type,
            $space,
            $containerId,
            $valid ? 'kept' : 'created',
        );
    }

    if ($dryRun) {
        $pdo->rollBack();
        printf("Dry run rolled back: reservations=%d events_reset=%d\n", $changedReservations, $resetEvents);
        exit(0);
    }
    $pdo->commit();
    printf("Migration complete: reservations=%d events_reset=%d\n", $changedReservations, $resetEvents);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Migration aborted and rolled back: {$error->getMessage()}\n");
    exit(1);
}
