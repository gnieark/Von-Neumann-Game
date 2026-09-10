<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Repository\OthersAuditRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Sector\SectorCoordinates;

require_once __DIR__ . '/../../vendor/autoload.php';

// Explicit administrative data migration: embark idle auxiliaries and move every
// active ship to the absolute origin, without travel time or fuel consumption.
// The audit entry retains the original ship and auxiliary rows in the transaction.
$options = getopt('', ['database-config:', 'mothership-id:', 'apply', 'dry-run', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/one-shot-scripts/relocate-inactive-others-fleet-to-origin.php --database-config=PATH --mothership-id=ID [--dry-run|--apply]\n";
    exit(0);
}
$pdo = null;
try {
    if (empty($options['database-config']) || empty($options['mothership-id']) || (isset($options['apply']) && isset($options['dry-run']))) {
        throw new InvalidArgumentException('Supply --database-config and --mothership-id, and at most one execution mode. Default: dry run.');
    }
    $pdo = (new AppFactory(dirname(__DIR__, 2)))->pdo($options['database-config'], initializeSchema: false);
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    $read = static function (string $sql, array $params) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $mother = $read('SELECT * FROM others_ships WHERE public_id = ?' . $lock, [$options['mothership-id']])[0] ?? null;
    if ($mother === null || $mother['type'] !== 'mothership' || $mother['destroyed_at'] !== null) {
        throw new RuntimeException('Active mothership not found.');
    }
    $fleetId = (int) $mother['fleet_id'];
    $fleet = $read('SELECT * FROM others_fleets WHERE id = ?' . $lock, [$fleetId])[0];
    if ($fleet['status'] !== 'active' || $fleet['dissolved_at'] !== null) {
        throw new RuntimeException('Fleet is not active.');
    }
    $ships = $read("SELECT * FROM others_ships WHERE fleet_id = ? AND destroyed_at IS NULL AND status != 'removed' ORDER BY id" . $lock, [$fleetId]);
    $shipIds = array_column($ships, 'id');
    $in = implode(',', array_fill(0, count($shipIds), '?'));
    $auxiliaries = $read("SELECT * FROM others_auxiliaries WHERE ship_id IN ($in) AND destroyed_at IS NULL AND status != 'removed' ORDER BY id" . $lock, $shipIds);
    foreach ($ships as $ship) {
        if ($ship['status'] !== 'inactive' || $ship['current_action_id'] !== null || (int) $ship['departure_engaged'] !== 0
            || (float) $ship['inventory_reserved'] !== 0.0 || (float) $ship['deuterium_reserved'] !== 0.0) {
            throw new RuntimeException('Ship is busy or has reservations: ' . $ship['public_id']);
        }
    }
    foreach ($auxiliaries as $auxiliary) {
        if ($auxiliary['status'] !== 'inactive' || $auxiliary['current_action_id'] !== null
            || !in_array($auxiliary['location_type'], ['embarked', 'deployed'], true)) {
            throw new RuntimeException('Auxiliary is unavailable: ' . $auxiliary['public_id']);
        }
    }
    if ($read("SELECT id FROM others_actions WHERE fleet_id = ? AND status IN ('queued','running','cancel_requested')" . $lock, [$fleetId]) !== []) {
        throw new RuntimeException('Fleet has unfinished actions.');
    }
    if ($read("SELECT e.id FROM scheduled_events e JOIN others_actions a ON e.entity_type = 'others_action' AND e.entity_id = a.id WHERE a.fleet_id = ? AND e.status IN ('pending','running')" . $lock, [$fleetId]) !== []) {
        throw new RuntimeException('Fleet still has scheduled actions.');
    }
    $recalled = count(array_filter($auxiliaries, static fn(array $a): bool => $a['location_type'] === 'deployed'));
    $moved = array_filter($ships, static fn(array $s): bool => (int) $s['sector_x'] !== 0 || (int) $s['sector_y'] !== 0 || (int) $s['sector_z'] !== 0);
    $summary = ['fleetId' => $fleet['public_id'], 'ships' => count($ships), 'shipsToMove' => count($moved), 'auxiliaries' => count($auxiliaries), 'auxiliariesToRecall' => $recalled];
    if (!isset($options['apply']) || ($moved === [] && $recalled === 0)) {
        $pdo->rollBack();
        echo json_encode(['applied' => false] + $summary, JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }
    $now = gmdate('c');
    $stmt = $pdo->prepare("UPDATE others_auxiliaries SET location_type = 'embarked', spatial_state = 'drifting', sector_x = NULL, sector_y = NULL, sector_z = NULL, object_id = NULL, updated_at = ? WHERE ship_id IN ($in) AND destroyed_at IS NULL AND status = 'inactive' AND location_type = 'deployed'");
    $stmt->execute([$now, ...$shipIds]);
    $stmt = $pdo->prepare("UPDATE others_ships SET sector_x = 0, sector_y = 0, sector_z = 0, entered_sector_at = ?, updated_at = ? WHERE id IN ($in) AND (sector_x != 0 OR sector_y != 0 OR sector_z != 0)");
    $stmt->execute([$now, $now, ...$shipIds]);
    $others = new OthersRepository($pdo);
    foreach ($moved as $ship) {
        $others->markFleetSectorVisited($fleetId, new SectorCoordinates(0, 0, 0), $now);
    }
    $afterShips = $read("SELECT * FROM others_ships WHERE id IN ($in) ORDER BY id", $shipIds);
    $afterAuxiliaries = $read("SELECT * FROM others_auxiliaries WHERE ship_id IN ($in) AND destroyed_at IS NULL AND status != 'removed' ORDER BY id", $shipIds);
    foreach ($afterShips as $ship) {
        if ((int) $ship['sector_x'] !== 0 || (int) $ship['sector_y'] !== 0 || (int) $ship['sector_z'] !== 0) {
            throw new RuntimeException('Ship relocation verification failed.');
        }
    }
    foreach ($afterAuxiliaries as $auxiliary) {
        if ($auxiliary['location_type'] !== 'embarked' || $auxiliary['sector_x'] !== null || $auxiliary['sector_y'] !== null || $auxiliary['sector_z'] !== null) {
            throw new RuntimeException('Auxiliary embarkation verification failed.');
        }
    }
    (new OthersAuditRepository($pdo))->record((int) $fleet['player_id'], 'cli', 'relocate_fleet_to_origin', 'accepted', $options['mothership-id'],
        $summary + ['beforeShips' => $ships, 'beforeAuxiliaries' => $auxiliaries]);
    $pdo->commit();
    echo json_encode(['applied' => true, 'verifiedAt' => $now] + $summary, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
