<?php

declare(strict_types=1);

require_once __DIR__ . '/OthersTestFixture.php';

// Uses the launcher's two independent connections and pipe barrier, on both engines.
$db = $connect();
[$repo, $service] = othersTestFixture($db, $directory);
$fleet = $repo->createFleet(1, 3, 4, 5);
$carrier = $fleet['ship'];
$escort = $repo->createStandardShip($carrier);
$db->prepare('UPDATE others_inventory_resources SET amount=10000 WHERE ship_id=?')->execute([$carrier['id']]);
$db->prepare('UPDATE others_ships SET deuterium_stock=20 WHERE id IN (?,?)')->execute([$carrier['id'], $escort['id']]);
$aux = $db->query('SELECT * FROM others_auxiliaries WHERE ship_id=' . (int) $carrier['id'] . ' ORDER BY id')->fetch(PDO::FETCH_ASSOC);
$db = $repo = $service = null;
$craft = static function (PDO $db) use ($directory, $carrier, $aux): array {
    [, $service] = othersTestFixture($db, $directory);
    return $service->startCraft($carrier, ['recipeId' => 'missile', 'assistantAuxiliaryId' => $aux['public_id']]);
};
$results = $race([$craft, $craft]);
$winners = array_values(array_filter($results, static fn(array $r): bool => $r['ok']));
$assert(count($winners) === 1, 'Others: concurrent crafts claim one assistant once: ' . json_encode($results));
$action = $winners[0]['result']['action'];
$complete = static function (PDO $db) use ($directory, $action): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $action['scheduled_event_id']));
    return true;
};
$results = $race([$complete, $complete]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: duplicate craft completions are harmless: ' . json_encode($results));
$db = $connect();
$assert((int) $db->query('SELECT COUNT(*) FROM others_inventory_items WHERE ship_id=' . (int) $carrier['id'])->fetchColumn() === 1, 'Others: one crafted item survives two workers');
$assert((float) $db->query('SELECT inventory_reserved FROM others_ships WHERE id=' . (int) $carrier['id'])->fetchColumn() === 0.0, 'Others: craft releases output capacity exactly once');
$assert((float) $db->query("SELECT amount FROM others_inventory_resources WHERE resource_type='metals' AND ship_id=" . (int) $carrier['id'])->fetchColumn() === 9980.0, 'Others: craft ingredients are consumed once');
$item = $db->query('SELECT public_id FROM others_inventory_items WHERE ship_id=' . (int) $carrier['id'])->fetchColumn();
$db = null;
$launch = static function (PDO $db) use ($directory, $carrier, $escort, $item): array {
    [, $service] = othersTestFixture($db, $directory);
    return $service->launchOthersMissile($carrier, ['missileItemId' => $item, 'targetId' => $escort['public_id']]);
};
$results = $race([$launch, $launch]);
$winners = array_values(array_filter($results, static fn(array $r): bool => $r['ok']));
$assert(count($winners) === 1, 'Others: concurrent launches reserve one missile: ' . json_encode($results));
$action = $winners[0]['result']['action'];
$fire = static function (PDO $db) use ($directory, $action): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $action['scheduled_event_id']));
    return true;
};
$results = $race([$fire, $fire]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: duplicate launch events finish without error: ' . json_encode($results));
$db = $connect();
$projectiles = $db->query('SELECT * FROM others_projectiles WHERE action_id IN (SELECT id FROM others_actions WHERE ship_id=' . (int) $carrier['id'] . ')')->fetchAll(PDO::FETCH_ASSOC);
$assert(count($projectiles) === 1, 'Others: one projectile and no duplicate ammunition');
$projectile = $projectiles[0];
$projectile['scheduled_event_id'] = (int) $db->query("SELECT id FROM scheduled_events WHERE entity_type='missile_projectile' AND entity_id=" . (int) $projectile['id'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
$db = null;
$impact = static function (PDO $db) use ($directory, $projectile): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledProjectile($events->findById((int) $projectile['scheduled_event_id']));
    return true;
};
$results = $race([$impact, $impact]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: duplicate impact workers converge: ' . json_encode($results));
$db = $connect();
$assert((int) $db->query('SELECT integrity FROM others_ships WHERE id=' . (int) $escort['id'])->fetchColumn() === 10, 'Others: impact applies ten damage exactly once');
$assert((int) $db->query("SELECT COUNT(*) FROM others_projectile_history WHERE projectile_public_id=" . $db->quote($projectile['public_id']))->fetchColumn() === 1, 'Others: impact history is unique');
$db = null;
$move = static function (PDO $db) use ($directory, $escort): array {
    [, $service] = othersTestFixture($db, $directory);
    return $service->moveShip($escort, ['target' => ['x' => 1, 'y' => 1, 'z' => 0]], new \VonNeumannGame\Sector\SectorCoordinates(3, 4, 5));
};
$results = $race([$move, $move]);
$winners = array_values(array_filter($results, static fn(array $r): bool => $r['ok']));
$assert(count($winners) === 1, 'Others: two movement commands create one departure: ' . json_encode($results));
$moveAction = $winners[0]['result'];
$depart = static function (PDO $db) use ($directory, $moveAction): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $moveAction['scheduled_event_id']));
    return true;
};
$results = $race([$depart, $depart]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: duplicate departure events do not apply arrival');
$db = $connect();
$assert($db->query('SELECT status FROM others_ships WHERE id=' . (int) $escort['id'])->fetchColumn() === 'transit', 'Others: movement retains its causal stage after replay');
$db = null;
$destroy = static function (PDO $db) use ($directory, $carrier): bool {
    [, $service] = othersTestFixture($db, $directory);
    $service->damageShip($carrier['public_id'], 100, 'others-race-destruction', ['type' => 'missile', 'missileId' => 'race-impact'], responsiblePlayerId: 1);
    return true;
};
$results = $race([$destroy, $destroy]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: repeated destruction is idempotent: ' . json_encode($results));
$db = $connect();
$assert((int) $db->query("SELECT COUNT(*) FROM others_damage_events WHERE event_key='others-race-destruction'")->fetchColumn() === 1, 'Others: one destruction event is recorded');
$assert((int) $db->query('SELECT others_motherships_destroyed FROM players WHERE id=1')->fetchColumn() === 1, 'Others: one mothership victory is credited');
$before = (int) $db->query('SELECT COUNT(*) FROM others_fleets')->fetchColumn();
$db = null;
$command = static function (PDO $db): array {
    $commands = new \VonNeumannGame\Service\OthersCommandService(new \VonNeumannGame\Database\StorageTransaction($db), new \VonNeumannGame\Repository\OthersIdempotencyRepository($db), new \VonNeumannGame\Repository\OthersAuditRepository($db));
    $player = (new \VonNeumannGame\Repository\PlayerRepository($db))->findById(1);
    $response = $commands->execute($player, 'POST', '/test/others', 'race-command', hash('sha256', '{}'), static function () use ($db): \VonNeumannGame\Http\ApiResponse {
        $fleet = (new \VonNeumannGame\Repository\OthersRepository($db))->createFleet(1, 3, 4, 5);
        return new \VonNeumannGame\Http\ApiResponse(201, ['fleetId' => $fleet['public_id']]);
    });
    return ['status' => $response->status, 'body' => $response->body];
};
$results = $race([$command, $command]);
$assert($results[0]['ok'] && $results[1]['ok'] && $results[0]['result'] === $results[1]['result'], 'Others: simultaneous idempotency keys replay the same response: ' . json_encode($results));
$db = $connect();
$assert((int) $db->query('SELECT COUNT(*) FROM others_fleets')->fetchColumn() === $before + 1, 'Others: idempotent command writes one business operation');
$assert((int) $db->query("SELECT COUNT(*) FROM others_operator_audit WHERE command='POST /test/others'")->fetchColumn() === 1, 'Others: idempotent command writes one audit entry');
$db = null;

// Capacity contention, then destruction racing the accepted transfer's completion.
$db = $connect();
[$repo] = othersTestFixture($db, $directory);
$capacityFleet = $repo->createFleet(1, 3, 4, 5);
$destination = $repo->createStandardShip($capacityFleet['ship']);
$sources = [$repo->createStandardShip($capacityFleet['ship']), $repo->createStandardShip($capacityFleet['ship'])];
$db->prepare('UPDATE others_ships SET inventory_capacity=1 WHERE id=?')->execute([$destination['id']]);
$actors = [];
foreach ($sources as $source) {
    $db->prepare("UPDATE others_inventory_resources SET amount=1 WHERE ship_id=? AND resource_type='metals'")->execute([$source['id']]);
    $actors[] = $db->query('SELECT * FROM others_auxiliaries WHERE ship_id=' . (int) $source['id'])->fetch();
}
$db = $repo = null;
$operations = [];
foreach ($sources as $index => $source) {
    $actor = $actors[$index];
    $operations[] = static function (PDO $db) use ($directory, $source, $actor, $destination): array {
        [, $service] = othersTestFixture($db, $directory);
        return $service->createInventoryTransfer($source, ['actorAuxiliaryId' => $actor['public_id'], 'targetShipId' => $destination['public_id'], 'kind' => 'resource', 'resourceType' => 'metals', 'amount' => 1]);
    };
}
$results = $race($operations);
$accepted = array_values(array_filter($results, static fn(array $r): bool => $r['ok']));
$assert(count($accepted) === 1, 'Others: two sources cannot reserve the last destination capacity twice: ' . json_encode($results));
$transferAction = $accepted[0]['result']['action'];
$settle = static function (PDO $db) use ($directory, $transferAction): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $transferAction['scheduled_event_id']));
    return true;
};
$killDestination = static function (PDO $db) use ($directory, $destination): bool {
    [, $service] = othersTestFixture($db, $directory);
    $service->damageShip($destination['public_id'], 20, 'capacity-destination-kill', ['type' => 'missile', 'missileId' => 'capacity-race']);
    return true;
};
$results = $race([$settle, $killDestination]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: transfer versus destruction reaches a coherent terminal state: ' . json_encode($results));
$db = $connect();
$assert((float) $db->query('SELECT SUM(inventory_reserved) FROM others_ships WHERE fleet_id=' . (int) $capacityFleet['id'])->fetchColumn() === 0.0, 'Others: no destination capacity remains reserved after destruction race');
$assert((float) $db->query('SELECT SUM(reserved_amount) FROM others_inventory_resources WHERE ship_id IN (' . (int) $sources[0]['id'] . ',' . (int) $sources[1]['id'] . ')')->fetchColumn() === 0.0, 'Others: no source resource reservation remains after destruction race');
[$repo, $service, $events] = othersTestFixture($db, $directory);
$moving = $sources[0];
$db->prepare('UPDATE others_ships SET deuterium_stock=10 WHERE id=?')->execute([$moving['id']]);
$moveAction = $service->moveShip($repo->findShipByPublicId($moving['public_id']), ['target' => ['x' => 1, 'y' => 1, 'z' => 0]], new \VonNeumannGame\Sector\SectorCoordinates(3, 4, 5));
$cancel = static function (PDO $db) use ($directory, $moving): bool {
    [, $service] = othersTestFixture($db, $directory);
    try { $service->cancelMove($moving); }
    catch (\VonNeumannGame\Service\OthersActionException $error) { if ($error->errorCode !== 'movement_cancellation_window_closed') { throw $error; } }
    return true;
};
$advance = static function (PDO $db) use ($directory, $moveAction): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $moveAction['scheduled_event_id']));
    return true;
};
$db = $repo = $service = $events = null;
$results = $race([$cancel, $advance]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: cancellation versus departure is serialized: ' . json_encode($results));
$db = $connect();
$state = $db->query('SELECT status,deuterium_stock FROM others_ships WHERE id=' . (int) $moving['id'])->fetch();
$assert(($state['status'] === 'transit' && (float) $state['deuterium_stock'] === 8.0) || ($state['status'] === 'inactive' && (float) $state['deuterium_stock'] === 10.0), 'Others: cancellation refunds fuel once or departure consumes it once');

// Double harvest completion cannot duplicate extraction from the sector or output stocks.
[$repo, $service, $events] = othersTestFixture($db, $directory);
$harvestFleet = $repo->createFleet(1, 7, 8, 9);
$harvester = $harvestFleet['ship'];
$sectorCoordinates = new \VonNeumannGame\Sector\SectorCoordinates(7, 8, 9);
(new \VonNeumannGame\Sector\SectorFileRepository($directory))->save(new \VonNeumannGame\Sector\SectorContent($sectorCoordinates, [new \VonNeumannGame\Sector\Planet('concurrent-harvest', 'Harvest', 'rocky', 1, 1, true, 0, ['metals'], resourceAmounts: ['metals' => 100.0, 'deuterium' => 0, 'ice' => 0, 'carbon_compounds' => 0])]));
$harvest = $service->startHarvest($harvester, ['targetObjectId' => 'concurrent-harvest', 'auxiliaryCount' => 1]);
$finishHarvest = static function (PDO $db) use ($directory, $harvest): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $harvest['scheduled_event_id']));
    return true;
};
$db = $repo = $service = $events = null;
$results = $race([$finishHarvest, $finishHarvest]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: duplicate harvest completions converge: ' . json_encode($results));
$db = $connect();
$harvestStock = (float) $db->query("SELECT amount FROM others_inventory_resources WHERE resource_type='metals' AND ship_id=" . (int) $harvester['id'])->fetchColumn();
$remaining = (new \VonNeumannGame\Sector\SectorFileRepository($directory))->load($sectorCoordinates)->findObjectById('concurrent-harvest')->getResourceAmounts()['metals'];
$assert(abs($harvestStock / 0.9 + $remaining - 100.0) < 0.0001 && $harvestStock > 0, 'Others: harvested output plus ten percent consumption equals the unique sector debit');
$assert((float) $db->query('SELECT inventory_reserved FROM others_ships WHERE id=' . (int) $harvester['id'])->fetchColumn() === 0.0, 'Others: harvest replay leaves no capacity reservation');
$db = null;

// Repair shares the same actor and inventory locks as crafting and destruction.
$db = $connect();
[$repo] = othersTestFixture($db, $directory);
$repairFleet = $repo->createFleet(1, 3, 4, 5);
$repairShip = $repairFleet['ship'];
$repairActor = $db->query('SELECT * FROM others_auxiliaries WHERE ship_id=' . (int) $repairShip['id'])->fetch();
$db->prepare('UPDATE others_ships SET integrity=50 WHERE id=?')->execute([$repairShip['id']]);
$db->prepare('UPDATE others_inventory_resources SET amount=1000 WHERE ship_id=?')->execute([$repairShip['id']]);
$db = $repo = null;
$repair = static function (PDO $db) use ($directory, $repairShip, $repairActor): array {
    [, $service] = othersTestFixture($db, $directory);
    return $service->startAuxiliaryTask($repairShip, $repairActor, 'repair', ['integrityPercent' => 2]);
};
$results = $race([$repair, $repair]);
$accepted = array_values(array_filter($results, static fn(array $r): bool => $r['ok']));
$assert(count($accepted) === 1, 'Others: concurrent repairs reserve one actor: ' . json_encode($results));
$repairAction = $accepted[0]['result'];
$repairComplete = static function (PDO $db) use ($directory, $repairAction): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $repairAction['scheduled_event_id']));
    return true;
};
$results = $race([$repairComplete, $repairComplete]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: double repair completion converges: ' . json_encode($results));
$db = $connect();
$assert((int) $db->query('SELECT integrity FROM others_ships WHERE id=' . (int) $repairShip['id'])->fetchColumn() === 52, 'Others: duplicate repair adds integrity once');
$assert((float) $db->query('SELECT SUM(reserved_amount) FROM others_inventory_resources WHERE ship_id=' . (int) $repairShip['id'])->fetchColumn() === 0.0, 'Others: repair completion releases all reserved ingredients');

// Queued missile preparation and destruction of its carrier may win in either order.
[$repo, $service, $events] = othersTestFixture($db, $directory);
$launcher = $repo->createStandardShip($repairShip);
$target = $repo->createStandardShip($repairShip);
$db->prepare("INSERT INTO others_inventory_items(public_id,ship_id,type,container_space,created_at,updated_at) VALUES('preparation-race-missile',?,'missile',2,?,?)")->execute([$launcher['id'], gmdate('c'), gmdate('c')]);
$prepared = $service->launchOthersMissile($launcher, ['missileItemId' => 'preparation-race-missile', 'targetId' => $target['public_id']]);
$ignite = static function (PDO $db) use ($directory, $prepared): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $prepared['action']['scheduled_event_id']));
    return true;
};
$killLauncher = static function (PDO $db) use ($directory, $launcher): bool {
    [, $service] = othersTestFixture($db, $directory);
    $service->damageShip($launcher['public_id'], 20, 'preparation-carrier-kill', ['type' => 'missile', 'missileId' => 'preparation-race']);
    return true;
};
$db = $repo = $service = $events = null;
$results = $race([$ignite, $killLauncher]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: preparation versus carrier destruction converges: ' . json_encode($results));
$db = $connect();
$launchStatus = $db->query('SELECT status FROM missile_launches WHERE id=' . (int) $prepared['missile']['id'])->fetchColumn();
$assert(in_array($launchStatus, ['launched', 'failed'], true), 'Others: a destroyed carrier leaves no queued missile preparation');
$assert((int) $db->query("SELECT COUNT(*) FROM others_inventory_items WHERE public_id='preparation-race-missile'")->fetchColumn() === 0, 'Others: preparation/destruction cannot return duplicate ammunition');

// A laser and departure contend on the emitter; a tick after departure cannot hit.
[$repo, $service, $events] = othersTestFixture($db, $directory);
$probeTarget = (new \VonNeumannGame\Repository\NeumannProbeRepository($db))->createForPlayer(1, 'Laser race target', new \VonNeumannGame\Sector\SectorCoordinates(3, 4, 5));
$db->prepare('UPDATE others_ships SET deuterium_stock=100 WHERE id=?')->execute([$repairShip['id']]);
$laser = $service->startLaser($repo->findShipByPublicId($repairShip['public_id']), ['targetId' => (string) $probeTarget->id]);
$service->processScheduledAction($events->findById((int) $laser['scheduled_event_id']));
$laser = $repo->findActionByPublicId($laser['public_id']);
$db->prepare('UPDATE others_laser_locks SET next_damage_at=? WHERE action_id=?')->execute([gmdate('c', time() - 1), $laser['id']]);
$movement = $service->moveShip($repo->findShipByPublicId($repairShip['public_id']), ['target' => ['x' => 1, 'y' => 1, 'z' => 0]], new \VonNeumannGame\Sector\SectorCoordinates(3, 4, 5));
$tick = static function (PDO $db) use ($directory, $laser): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $laser['scheduled_event_id']));
    return true;
};
$leave = static function (PDO $db) use ($directory, $movement): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $movement['scheduled_event_id']));
    return true;
};
$db = $repo = $service = $events = null;
$results = $race([$tick, $leave]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: laser tick versus departure is serialized: ' . json_encode($results));
$db = $connect();
[$repo, $service, $events] = othersTestFixture($db, $directory);
$laser = $repo->findActionByPublicId($laser['public_id']);
$service->processScheduledAction($events->findById((int) $laser['scheduled_event_id']));
$assert($db->query('SELECT status FROM others_laser_locks WHERE action_id=' . (int) $laser['id'])->fetchColumn() === 'stopped', 'Others: departure terminates the laser on its next tick');
$integrity = (float) $db->query('SELECT integrity_percent FROM neumann_probes WHERE id=' . $probeTarget->id)->fetchColumn();
$assert(in_array($integrity, [95.0, 100.0], true), 'Others: the laser applies at most its one pre-departure tick');
$db = $repo = $service = $events = null;

// Departure versus inventory completion preserves one causal settlement.
$db = $connect();
[$repo, $service] = othersTestFixture($db, $directory);
$departureFleet = $repo->createFleet(1, 3, 4, 5);
$departureSource = $repo->createStandardShip($departureFleet['ship']);
$departureTarget = $repo->createStandardShip($departureFleet['ship']);
$db->prepare('UPDATE others_ships SET deuterium_stock=10 WHERE id=?')->execute([$departureSource['id']]);
$db->prepare("UPDATE others_inventory_resources SET amount=1 WHERE ship_id=? AND resource_type='metals'")->execute([$departureSource['id']]);
$departureActor = $db->query('SELECT * FROM others_auxiliaries WHERE ship_id=' . (int) $departureSource['id'])->fetch();
$departureTransfer = $service->createInventoryTransfer($departureSource, ['actorAuxiliaryId' => $departureActor['public_id'], 'targetShipId' => $departureTarget['public_id'], 'kind' => 'resource', 'resourceType' => 'metals', 'amount' => 1]);
$finishBeforeDeparture = static function (PDO $db) use ($directory, $departureTransfer): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $departureTransfer['action']['scheduled_event_id']));
    return true;
};
$engageDeparture = static function (PDO $db) use ($directory, $departureSource): bool {
    [, $service] = othersTestFixture($db, $directory);
    $service->moveShip($departureSource, ['target' => ['x' => 1, 'y' => 1, 'z' => 0]], new \VonNeumannGame\Sector\SectorCoordinates(3, 4, 5));
    return true;
};
$db = $repo = $service = null;
$results = $race([$finishBeforeDeparture, $engageDeparture]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: departure versus transfer completion converges: ' . json_encode($results));
$db = $connect();
$assert((float) $db->query("SELECT SUM(amount) FROM others_inventory_resources WHERE resource_type='metals' AND ship_id IN (" . (int) $departureSource['id'] . ',' . (int) $departureTarget['id'] . ')')->fetchColumn() === 1.0, 'Others: departure/transfer race conserves the resource total');
$assert((float) $db->query('SELECT SUM(inventory_reserved) FROM others_ships WHERE fleet_id=' . (int) $departureFleet['id'])->fetchColumn() === 0.0, 'Others: departure/transfer leaves no destination reservation');

// Cancellation versus harvest completion, including all cancellation phases.
[$repo, $service] = othersTestFixture($db, $directory);
$cancelFleet = $repo->createFleet(1, 11, 12, 13);
$cancelShip = $cancelFleet['ship'];
$cancelCoordinates = new \VonNeumannGame\Sector\SectorCoordinates(11, 12, 13);
(new \VonNeumannGame\Sector\SectorFileRepository($directory))->save(new \VonNeumannGame\Sector\SectorContent($cancelCoordinates, [new \VonNeumannGame\Sector\Planet('cancel-harvest', 'Harvest', 'rocky', 1, 1, true, 0, ['metals'], resourceAmounts: ['metals' => 100.0, 'deuterium' => 0, 'ice' => 0, 'carbon_compounds' => 0])]));
$cancelAction = $service->startHarvest($cancelShip, ['targetObjectId' => 'cancel-harvest', 'auxiliaryCount' => 1]);
$cancelHarvest = static function (PDO $db) use ($directory, $cancelShip): bool {
    [, $service] = othersTestFixture($db, $directory);
    try { $service->cancelHarvest($cancelShip); }
    catch (\VonNeumannGame\Service\OthersActionException $error) { if ($error->httpStatus !== 404 && $error->httpStatus !== 409) { throw $error; } }
    return true;
};
$completeHarvest = static function (PDO $db) use ($directory, $cancelAction): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $cancelAction['scheduled_event_id']));
    return true;
};
$db = $repo = $service = null;
$results = $race([$cancelHarvest, $completeHarvest]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: harvest cancellation versus completion converges: ' . json_encode($results));
$db = $connect();
[$repo, $service, $events] = othersTestFixture($db, $directory);
for ($step = 0; $step < 3; $step++) {
    $action = $repo->findActionByPublicId($cancelAction['public_id']);
    if (in_array($action['status'], ['succeeded','failed','canceled'], true)) { break; }
    $service->processScheduledAction($events->findById((int) $action['scheduled_event_id']));
}
$assert(in_array($repo->findActionByPublicId($cancelAction['public_id'])['status'], ['succeeded','canceled'], true), 'Others: harvest cancellation ends after its bounded recall phases');
$stock = (float) $db->query("SELECT amount FROM others_inventory_resources WHERE resource_type='metals' AND ship_id=" . (int) $cancelShip['id'])->fetchColumn();
$left = (new \VonNeumannGame\Sector\SectorFileRepository($directory))->load($cancelCoordinates)->findObjectById('cancel-harvest')->getResourceAmounts()['metals'];
$assert(abs($stock / 0.9 + $left - 100.0) < 0.0001, 'Others: canceled/completed harvest preserves extraction accounting');
$assert((float) $db->query('SELECT inventory_reserved FROM others_ships WHERE id=' . (int) $cancelShip['id'])->fetchColumn() === 0.0, 'Others: harvest cancellation releases reserved capacity');

// Workshop completion and carrier destruction must never leave an orphan output.
$craftFleet = $repo->createFleet(1, 15, 16, 17);
$craftShip = $craftFleet['ship'];
(new \VonNeumannGame\Sector\SectorFileRepository($directory))->save(new \VonNeumannGame\Sector\SectorContent(new \VonNeumannGame\Sector\SectorCoordinates(15, 16, 17)));
$db->prepare('UPDATE others_inventory_resources SET amount=10000 WHERE ship_id=?')->execute([$craftShip['id']]);
$craftActor = $db->query('SELECT * FROM others_auxiliaries WHERE ship_id=' . (int) $craftShip['id'])->fetch();
$craftResult = $service->startCraft($craftShip, ['recipeId' => 'missile', 'assistantAuxiliaryId' => $craftActor['public_id']]);
$completeDestroyedCraft = static function (PDO $db) use ($directory, $craftResult): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $craftResult['action']['scheduled_event_id']));
    return true;
};
$destroyWorkshop = static function (PDO $db) use ($directory, $craftShip): bool {
    [, $service] = othersTestFixture($db, $directory);
    $service->damageShip($craftShip['public_id'], 100, 'workshop-destruction', ['type' => 'missile', 'missileId' => 'workshop-race']);
    return true;
};
$db = $repo = $service = $events = null;
$results = $race([$completeDestroyedCraft, $destroyWorkshop]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: craft completion versus destruction converges: ' . json_encode($results));
$db = $connect();
$assert((int) $db->query('SELECT COUNT(*) FROM others_inventory_items WHERE ship_id=' . (int) $craftShip['id'])->fetchColumn() === 0, 'Others: destroyed workshop has no orphan output');
$assert((float) $db->query('SELECT inventory_reserved FROM others_ships WHERE id=' . (int) $craftShip['id'])->fetchColumn() === 0.0, 'Others: destroyed workshop has no output reservation');
$craftSector = (new \VonNeumannGame\Sector\SectorFileRepository($directory))->load(new \VonNeumannGame\Sector\SectorCoordinates(15, 16, 17));
$drifting = $craftSector->findObjectById(\VonNeumannGame\Sector\SectorDriftingItem::objectIdForItemType('missile'));
$assert($drifting === null || $drifting->getQuantity() === 1, 'Others: a completed workshop missile reaches the wreck at most once');
$db = null;

// Impact versus target departure uses the target's freshly locked position.
$db = $connect();
[$repo, $service, $events] = othersTestFixture($db, $directory);
$combatFleet = $repo->createFleet(1, 3, 4, 5);
$combatCarrier = $combatFleet['ship'];
$movingTarget = $repo->createStandardShip($combatCarrier);
$db->prepare('UPDATE others_ships SET deuterium_stock=10 WHERE id=?')->execute([$movingTarget['id']]);
$db->prepare("INSERT INTO others_inventory_items(public_id,ship_id,type,container_space,created_at,updated_at) VALUES('moving-target-missile',?,'missile',2,?,?)")->execute([$combatCarrier['id'], gmdate('c'), gmdate('c')]);
$launch = $service->launchOthersMissile($combatCarrier, ['missileItemId' => 'moving-target-missile', 'targetId' => $movingTarget['public_id']]);
$service->processScheduledAction($events->findById((int) $launch['action']['scheduled_event_id']));
$projectile = $db->query('SELECT * FROM others_projectiles WHERE action_id=' . (int) $launch['action']['id'])->fetch();
$impactEventId = (int) $db->query("SELECT id FROM scheduled_events WHERE entity_type='missile_projectile' AND entity_id=" . (int) $projectile['id'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
$targetMovement = $service->moveShip($movingTarget, ['target' => ['x' => 1, 'y' => 1, 'z' => 0]], new \VonNeumannGame\Sector\SectorCoordinates(3, 4, 5));
$movingImpact = static function (PDO $db) use ($directory, $impactEventId): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledProjectile($events->findById($impactEventId));
    return true;
};
$targetDeparture = static function (PDO $db) use ($directory, $targetMovement): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $targetMovement['scheduled_event_id']));
    return true;
};
$db = $repo = $service = $events = null;
$results = $race([$movingImpact, $targetDeparture]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: missile impact versus target departure converges: ' . json_encode($results));
$db = $connect();
$targetState = $db->query('SELECT status,integrity,deuterium_stock FROM others_ships WHERE id=' . (int) $movingTarget['id'])->fetch();
$assert($targetState['status'] === 'transit' && in_array((int) $targetState['integrity'], [10,20], true) && (float) $targetState['deuterium_stock'] === 8.0, 'Others: impact/departure preserves one fuel debit and at most one hit');
$assert((int) $db->query('SELECT COUNT(*) FROM others_projectile_history WHERE projectile_public_id=' . $db->quote($projectile['public_id']))->fetchColumn() === 1, 'Others: moving target has one projectile resolution');
[$repo, $service, $events] = othersTestFixture($db, $directory);
$arrival = $repo->findActionByPublicId($targetMovement['public_id']);
$arrive = static function (PDO $db) use ($directory, $arrival): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $arrival['scheduled_event_id']));
    return true;
};
$lateCancel = static function (PDO $db) use ($directory, $movingTarget): bool {
    [, $service] = othersTestFixture($db, $directory);
    try { $service->cancelMove($movingTarget); }
    catch (\VonNeumannGame\Service\OthersActionException $error) {
        if (!in_array($error->errorCode, ['active_movement_not_found', 'movement_cancellation_window_closed'], true)) { throw $error; }
        return true;
    }
    throw new RuntimeException('A departed movement must reject cancellation.');
};
$db = $repo = $service = $events = null;
$results = $race([$arrive, $lateCancel]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: cancellation versus arrival rejects the late cancellation: ' . json_encode($results));
$db = $connect();
$targetState = $db->query('SELECT status,deuterium_stock,current_action_id FROM others_ships WHERE id=' . (int) $movingTarget['id'])->fetch();
$assert($targetState['status'] === 'inactive' && (float) $targetState['deuterium_stock'] === 8.0 && $targetState['current_action_id'] === null, 'Others: arrival clears the actor without a late fuel refund');

// A departure worker must not resurrect a ship destroyed at the same time.
[$repo, $service] = othersTestFixture($db, $directory);
$doomed = $repo->createStandardShip($combatCarrier);
$db->prepare('UPDATE others_ships SET deuterium_stock=10 WHERE id=?')->execute([$doomed['id']]);
$doomedMovement = $service->moveShip($doomed, ['target' => ['x' => 1, 'y' => 1, 'z' => 0]], new \VonNeumannGame\Sector\SectorCoordinates(3, 4, 5));
$doomedDeparture = static function (PDO $db) use ($directory, $doomedMovement): bool {
    [, $service, $events] = othersTestFixture($db, $directory);
    $service->processScheduledAction($events->findById((int) $doomedMovement['scheduled_event_id']));
    return true;
};
$destroyDeparting = static function (PDO $db) use ($directory, $doomed): bool {
    [, $service] = othersTestFixture($db, $directory);
    $service->damageShip($doomed['public_id'], 20, 'departing-destruction', ['type' => 'missile', 'missileId' => 'departure-race']);
    return true;
};
$db = $repo = $service = null;
$results = $race([$doomedDeparture, $destroyDeparting]);
$assert($results[0]['ok'] && $results[1]['ok'], 'Others: destruction versus departure converges: ' . json_encode($results));
$db = $connect();
$doomedState = $db->query('SELECT destroyed_at,current_action_id FROM others_ships WHERE id=' . (int) $doomed['id'])->fetch();
$assert($doomedState['destroyed_at'] !== null && $doomedState['current_action_id'] === null, 'Others: departure cannot resurrect its destroyed actor');
$assert((int) $db->query("SELECT COUNT(*) FROM scheduled_events WHERE status='pending' AND entity_type='others_action' AND entity_id=" . (int) $doomedMovement['id'])->fetchColumn() === 0, 'Others: destroyed departing ship has no pending movement event');
$db = null;
