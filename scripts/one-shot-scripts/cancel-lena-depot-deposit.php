<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\Storage\InventoryTransferRepositoryFactory;
use VonNeumannGame\Repository\Storage\StorageActorRepository;
use VonNeumannGame\Repository\Storage\StorageLockRepository;

require_once __DIR__ . '/../../vendor/autoload.php';

// Administrative rollback of this exact deposit, not a general cancellation policy.
// No delivery has been settled: release all reservations without changing amounts.
$config = 'config/database.json';
$backup = null;
$mode = 'dry-run';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database-config=')) {
        $config = substr($argument, strlen('--database-config='));
    } elseif (str_starts_with($argument, '--backup=')) {
        $backup = substr($argument, strlen('--backup='));
    } elseif (in_array($argument, ['--apply', '--rehearse', '--dry-run'], true)) {
        if ($mode !== 'dry-run') { throw new InvalidArgumentException('Choose only one execution mode.'); }
        $mode = substr($argument, 2);
    } else {
        throw new InvalidArgumentException('Expected --database-config=PATH [--apply|--rehearse --backup=NEW_FILE].');
    }
}
if ($mode !== 'dry-run' && !$backup) { throw new InvalidArgumentException('A new backup file is required.'); }
$pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($config, initializeSchema: false);
$locks = new StorageLockRepository($pdo);
$read = static function (string $sql, array $params = []) use ($pdo): array {
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return $query->fetchAll(PDO::FETCH_ASSOC);
};
$check = static function (bool $valid, string $message): void {
    if (!$valid) { throw new RuntimeException($message); }
};
$pdo->beginTransaction();
try {
    $ship = $locks->lock('ship', 83);
    $auxiliary = $locks->lock('auxiliary', 1163);
    $action = $locks->lock('action', 8075);
    $transfer = $locks->lock('transfer', 25);
    $depot = $locks->lock('depot', 2);
    $check($ship !== null && $auxiliary !== null && $action !== null && $transfer !== null && $depot !== null, 'Missing repair roots.');
    $check($ship['public_id'] === 'mother_47a1a22d1b93f8daab85' && (int) $ship['fleet_id'] === 5
        && $ship['destroyed_at'] === null && $ship['current_action_id'] === null
        && in_array($ship['status'], ['inactive', 'available'], true), 'Carrier changed.');
    $check($action['public_id'] === 'action_3294c625f8ab5ce2e8b5' && $action['type'] === 'depot_deposit'
        && $action['status'] === 'queued' && (int) $action['ship_id'] === 83 && (int) $action['auxiliary_id'] === 1163
        && (int) $action['fleet_id'] === 5 && $action['actor_kind'] === 'others_auxiliary'
        && $action['actor_public_id'] === 'aux_0004095a5be990eceecc'
        && (int) $action['scheduled_event_id'] === 2673559 && $action['result_json'] === null, 'Action changed or already repaired.');
    $check($auxiliary['public_id'] === 'aux_0004095a5be990eceecc' && (int) $auxiliary['ship_id'] === 83
        && (int) $auxiliary['current_action_id'] === 8075 && $auxiliary['destroyed_at'] === null
        && $auxiliary['status'] === 'busy' && $auxiliary['location_type'] === 'deployed', 'Auxiliary changed.');
    foreach (['sector_x', 'sector_y', 'sector_z'] as $column) {
        $check($ship[$column] !== null && $auxiliary[$column] !== null
            && (int) $ship[$column] === (int) $auxiliary[$column]
            && (int) $ship[$column] === (int) $depot[$column], 'Carrier or auxiliary has left the depot sector.');
    }
    $check($transfer['public_id'] === 'storage_transfer_ac3111863e81cc91570c' && (int) $transfer['player_id'] === 477
        && (int) $transfer['others_action_id'] === 8075 && (int) $transfer['others_ship_id'] === 83
        && $transfer['actor_kind'] === 'others_auxiliary' && $transfer['actor_public_id'] === $auxiliary['public_id']
        && $transfer['external_storage_kind'] === 'depot' && (int) $transfer['external_storage_id'] === 2
        && $transfer['direction'] === 'to_storage' && $transfer['status'] === 'queued'
        && (int) $transfer['version'] === 1 && $transfer['result_json'] === null
        && $transfer['ends_at'] === $action['ends_at'] && strtotime($action['ends_at']) > time(), 'Transfer changed.');
    $expected = ['carbon_compounds' => 17054.0, 'deuterium' => 3444.0, 'ice' => 66520.0, 'metals' => 7386.0];
    $resources = json_decode($transfer['resources_json'], true, 512, JSON_THROW_ON_ERROR);
    ksort($resources);
    $check($resources == $expected && json_decode($transfer['items_json'], true, 512, JSON_THROW_ON_ERROR) === [], 'Unexpected cargo.');
    $eventRows = $read('SELECT * FROM scheduled_events WHERE id=2673559' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $event = $eventRows[0] ?? [];
    $check(($event['status'] ?? null) === 'pending' && $event['type'] === 'others.action'
        && $event['entity_type'] === 'others_action' && (int) $event['entity_id'] === 8075
        && (int) $event['attempts'] === 0 && $event['locked_by'] === null && $event['locked_at'] === null
        && $event['run_at'] === $action['ends_at'], 'Scheduler event changed.');
    $reservations = $read('SELECT * FROM sector_storage_resource_reservations WHERE transfer_id=25 ORDER BY resource_type');
    $check(count($reservations) === 4, 'Reservation count changed.');
    foreach ($reservations as $reservation) {
        $check($reservation['inventory_kind'] === 'ship' && (int) $reservation['inventory_id'] === 83
            && isset($expected[$reservation['resource_type']])
            && (float) $reservation['amount'] === $expected[$reservation['resource_type']], 'Reservation changed.');
    }
    foreach (['sector_storage_item_claims', 'sector_storage_capacity_reservations'] as $table) {
        $check($read("SELECT * FROM $table WHERE transfer_id=25") === [], 'Unexpected item/capacity reservation.');
    }
    $check($read('SELECT id FROM others_inventory_items WHERE reserved_action_id=8075') === [], 'Unexpected reserved items.');
    $beforeResources = $read('SELECT * FROM others_inventory_resources WHERE ship_id=83 ORDER BY resource_type');
    $check(count($beforeResources) === 4, 'Inventory resource count changed.');
    foreach ($beforeResources as $row) {
        $check(isset($expected[$row['resource_type']]) && (float) $row['reserved_amount'] === $expected[$row['resource_type']]
            && (float) $row['amount'] >= (float) $row['reserved_amount'], 'Inventory reservation changed.');
    }
    printf("mode=%s action=%s auxiliary=%s release=94404_ECE previous_deadline=%s\n", $mode, $action['public_id'], $auxiliary['public_id'], $action['ends_at']);
    if ($mode === 'dry-run') { $pdo->rollBack(); exit(0); }
    $snapshot = json_encode(['createdAt' => gmdate('c'), 'mode' => $mode, 'ship' => $ship, 'auxiliary' => $auxiliary,
        'action' => $action, 'transfer' => $transfer, 'event' => $event,
        'resources' => $beforeResources, 'reservations' => $reservations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $mask = umask(0077);
    try { $handle = fopen($backup, 'x'); } finally { umask($mask); }
    if ($handle === false) { throw new RuntimeException('Cannot create backup.'); }
    try {
        $check(fwrite($handle, $snapshot) === strlen($snapshot) && fflush($handle) && fsync($handle), 'Cannot persist backup.');
    } finally { fclose($handle); }

    $now = gmdate('c');
    (new InventoryTransferRepositoryFactory($pdo))->create('ship', 83)->release(25, 8075, $now);
    $check((new StorageActorRepository($pdo))->releaseAuxiliary(8075, 1163, $now), 'Auxiliary release failed.');
    // Keep nullable public results empty: no new outcome enum or API contract.
    foreach ([
        "UPDATE others_actions SET status='canceled',completed_at=?,updated_at=? WHERE id=8075 AND status='queued'",
        "UPDATE sector_storage_transfers SET status='canceled',version=version+1,updated_at=? WHERE id=25 AND status='queued'",
        "UPDATE scheduled_events SET status='cancelled',processed_at=?,updated_at=? WHERE id=2673559 AND status='pending'",
    ] as $sql) {
        $query = $pdo->prepare($sql);
        $query->execute(array_fill(0, substr_count($sql, '?'), $now));
        $check($query->rowCount() === 1, 'Terminal transition failed.');
    }
    $afterResources = $read('SELECT * FROM others_inventory_resources WHERE ship_id=83 ORDER BY resource_type');
    foreach ($beforeResources as $index => $row) {
        $expectedRow = $row;
        $expectedRow['reserved_amount'] = 0;
        $check($afterResources[$index] == $expectedRow, 'Inventory amount changed or reservation not released.');
    }
    $check($read('SELECT * FROM sector_storage_resource_reservations WHERE transfer_id=25') === [], 'Reservations remain.');
    $afterAuxiliary = $read('SELECT * FROM others_auxiliaries WHERE id=1163')[0];
    $check($afterAuxiliary['current_action_id'] === null && $afterAuxiliary['location_type'] === 'embarked'
        && $afterAuxiliary['status'] === 'inactive', 'Auxiliary remains blocked.');
    if ($mode === 'rehearse') { $pdo->rollBack(); echo "Rehearsal passed; all database changes rolled back.\n"; }
    else { $pdo->commit(); echo "Committed: deposit and scheduler event canceled; auxiliary embarked; 94404 ECE released, inventory amounts unchanged.\n"; }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $error;
}
