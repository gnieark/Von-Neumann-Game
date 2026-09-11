<?php

declare(strict_types=1);

// Uses the accessible external storage and API kernel of SectorStorageHttpTests.
$fuelManny = $mannies->createForProbe($probe->id, 'External fuel Manny');
$fuelPath = '/api/probe/' . $probe->id . '/mannies/' . $fuelManny->uid . '/transfer-deuterium-from-external-storage';
$pdo->prepare("INSERT INTO germination_depot_resources(depot_id,resource_type,amount,reserved_amount) VALUES (?,'deuterium',2,0)")->execute([$depot['id']]);
$setTank = static function (float $points) use ($pdo, $probe): void {
    $pdo->prepare('UPDATE neumann_probes SET deuterium_stock=? WHERE id=?')->execute([$points, $probe->id]);
};
$sourceStock = static function () use ($pdo, $depot): array {
    $query = $pdo->prepare("SELECT amount,reserved_amount FROM germination_depot_resources WHERE depot_id=? AND resource_type='deuterium'");
    $query->execute([$depot['id']]);
    return array_map('floatval', $query->fetch(PDO::FETCH_ASSOC));
};
$startFuel = static fn(array $payload, array $extraHeaders = []) => $kernel->handle('POST', $fuelPath, $headers + $extraHeaders, json_encode($payload, JSON_THROW_ON_ERROR));
$baseFuel = ['objectId' => $depot['public_id'], 'amount' => 0.1];
$test->assertEquals(401, $kernel->handle('POST', $fuelPath, [], json_encode($baseFuel))->status, 'external fuel requires authentication');
foreach ([[], ['amount' => 0], ['amount' => -1], ['amount' => '0.1'], ['amount' => 0.00001], ['objectId' => ''], ['containerId' => 'probe-core']] as $invalid) {
    $payload = $invalid === [] ? [] : array_replace($baseFuel, $invalid);
    $test->assertEquals(400, $startFuel($payload)->status, 'external fuel validates raw ECE and canonical fields');
}
$test->assertEquals(404, $startFuel(['objectId' => 'unknown-external-stock', 'amount' => 0.1])->status, 'external fuel does not expose unknown storage');
$setTank(100);
$full = $startFuel($baseFuel);
$test->assertEquals(409, $full->status, 'external fuel refuses a full tank');
$test->assertEquals('probe_deuterium_full', $full->body['error']['code'] ?? null, 'full fuel tank has a stable error');
$setTank(95);
$pdo->prepare("UPDATE neumann_probes SET status='preparing' WHERE id=?")->execute([$probe->id]);
$test->assertEquals(409, $startFuel($baseFuel)->status, 'external fuel refuses a probe preparing departure');
$pdo->prepare("UPDATE neumann_probes SET status='idle' WHERE id=?")->execute([$probe->id]);
$fuelKey = ['Idempotency-Key' => 'external-deuterium-clamp'];
$oversizedFuel = ['objectId' => $depot['public_id'], 'amount' => 10];
$fuelStarted = $startFuel($oversizedFuel, $fuelKey);
$test->assertEquals(202, $fuelStarted->status, 'external fuel accepts more than tank capacity and source stock when the clamped amount fits');
if ($fuelStarted->status !== 202) { throw new RuntimeException(json_encode($fuelStarted->body)); }
$fuelTransfer = $fuelStarted->body['transfer'];
$test->assertEquals(['requestedAmountEce' => 10, 'acceptedAmountEce' => 0.05, 'tankPoints' => 5, 'clamped' => true], $fuelTransfer['tankTransfer'], 'external fuel reports requested and clamped ECE and tank points');
$test->assertEquals(null, $fuelTransfer['containerId'], 'external fuel has no onboard container destination');
$test->assertEquals(600, $fuelTransfer['durationSeconds'], 'external fuel always takes ten minutes');
$test->assertEquals(1, $fuelTransfer['tripCount'], 'external fuel uses one round trip');
$test->assertEquals(600, strtotime($fuelTransfer['endsAt']) - strtotime($fuelTransfer['startedAt']), 'external fuel deadlines are ten minutes apart');
$test->assertEquals(95.0, $probes->findById($probe->id)->deuteriumStock, 'external fuel does not fill the tank at acceptance');
$test->assertEquals(['amount' => 2.0, 'reserved_amount' => 0.05], $sourceStock(), 'external fuel reserves only the clamped raw stock');
$test->assertEquals($fuelStarted->body, $startFuel($oversizedFuel, $fuelKey)->body, 'external fuel idempotent retry returns the original response');
$test->assertEquals(409, $startFuel($baseFuel, $fuelKey)->status, 'external fuel rejects reuse of an idempotency key with another amount');
$test->assertEquals(409, $startFuel($baseFuel)->status, 'external fuel keeps its Manny busy');
$otherFuelManny = $mannies->createForProbe($probe->id, 'Second external fuel Manny');
$otherFuelPath = str_replace($fuelManny->uid, $otherFuelManny->uid, $fuelPath);
$test->assertEquals(409, $kernel->handle('POST', $otherFuelPath, $headers, json_encode($baseFuel))->status, 'concurrent external fuel cannot reserve the same tank capacity twice');
$beforeReturn = (new DateTimeImmutable($fuelTransfer['startedAt']))->modify('+599 seconds')->format('c');
$test->assertEquals('queued', $mannyStorageTransfers->complete($fuelTransfer['id'], $beforeReturn)['status'], 'external fuel is not delivered before the return ends');
$pdo->exec("CREATE TRIGGER fail_external_fuel_credit BEFORE UPDATE OF deuterium_stock ON neumann_probes WHEN NEW.deuterium_stock <> OLD.deuterium_stock BEGIN SELECT RAISE(ABORT,'fuel credit crash'); END");
$fuelFailed = false;
try { $mannyStorageTransfers->complete($fuelTransfer['id'], $fuelTransfer['endsAt']); } catch (PDOException) { $fuelFailed = true; }
$pdo->exec('DROP TRIGGER fail_external_fuel_credit');
$test->assert($fuelFailed, 'external fuel credit failure is exercised');
$test->assertEquals(['amount' => 2.0, 'reserved_amount' => 0.05], $sourceStock(), 'failed external fuel settlement preserves stock and reservations');
$test->assertEquals('queued', $mannyStorageTransfers->get($probe, $fuelTransfer['id'])['status'], 'failed external fuel settlement remains retryable');
$fuelHandler = new \VonNeumannGame\Service\Manny\SectorStorageTransferTaskHandler($mannyStorageTransfers,
    fn($p, $m) => $storage->placeMannyOnProbe($p, $m),
    static function ($m, $payload): void { $m->currentTask = \VonNeumannGame\Domain\Manny::TASK_WAITING_FOR_SPACE; $m->taskPayload = $payload; },
    static function ($m, $payload): void { $m->currentTask = null; $m->taskEndsAt = null; $m->taskStartedAt = null; $m->taskPayload = $payload; },
    fn($m) => $mannies->save($m));
$fuelHandler->refresh($mannyService, $mannies->findByUid($fuelManny->uid), $probes->findById($probe->id), new DateTimeImmutable($fuelTransfer['endsAt']));
$test->assertEquals(100.0, $probes->findById($probe->id)->deuteriumStock, 'external fuel converts 0.05 ECE into five tank points on return');
$test->assertEquals(['amount' => 1.95, 'reserved_amount' => 0.0], $sourceStock(), 'external fuel conserves the raw source quantity');
$settledFuel = $mannyStorageTransfers->complete($fuelTransfer['id'], $fuelTransfer['endsAt']);
$test->assertEquals(5.0, (float) $settledFuel['result']['deliveredTankPoints'], 'external fuel result reports actual tank points');
$test->assertEquals(100.0, $probes->findById($probe->id)->deuteriumStock, 'replayed external fuel settlement does not duplicate tank credit');
$test->assertEquals($fuelStarted->body, $startFuel($oversizedFuel, $fuelKey)->body, 'external fuel HTTP replay remains identical after completion');
$test->assertEquals(null, $mannies->findByUid($fuelManny->uid)->currentTask, 'external fuel task returns its Manny to idle');
$test->assert($mannies->findByUid($fuelManny->uid)->isOnProbe(), 'external fuel Manny returns aboard');

// A competing refill must not destroy raw material or overfill the tank.
$setTank(90);
$competingFuel = $startFuel($baseFuel)->body['transfer'];
$setTank(99);
$competingResult = $mannyStorageTransfers->complete($competingFuel['id'], $competingFuel['endsAt']);
$test->assertEquals(1.0, (float) $competingResult['result']['deliveredTankPoints'], 'external fuel recalculates the remaining capacity on return');
$test->assertEquals(0.09, $competingResult['result']['released']['resources']['deuterium'] ?? null, 'external fuel releases any excess raw stock after another refill');
$test->assertEquals(100.0, $probes->findById($probe->id)->deuteriumStock, 'external fuel never overfills the tank');
$fuelHandler->refresh($mannyService, $mannies->findByUid($fuelManny->uid), $probes->findById($probe->id), new DateTimeImmutable($competingFuel['endsAt']));

// Interruption before pickup returns everything; destruction on the return leg loses the single load.
foreach ([299, 300] as $elapsed) {
    $setTank(90);
    $transport = $startFuel($baseFuel)->body['transfer'];
    $stockBefore = $sourceStock()['amount'];
    $result = $mannyStorageTransfers->complete($transport['id'], (new DateTimeImmutable($transport['startedAt']))->modify('+' . $elapsed . ' seconds')->format('c'), 'manny_destroyed');
    $test->assertEquals($elapsed < 300 ? 0.0 : 0.1, (float) (((array) $result['result']['lost']['resources'])['deuterium'] ?? 0), 'external fuel destruction respects the five-minute pickup boundary');
    $test->assertEquals(round($stockBefore - ($elapsed < 300 ? 0 : 0.1), 4), $sourceStock()['amount'], 'external fuel destruction debits only the transported load');
    $test->assertEquals(90.0, $probes->findById($probe->id)->deuteriumStock, 'destroyed fuel transport never credits the tank');
    $fuelManny = $mannies->createForProbe($probe->id, 'Replacement fuel Manny ' . $elapsed);
    $fuelPath = '/api/probe/' . $probe->id . '/mannies/' . $fuelManny->uid . '/transfer-deuterium-from-external-storage';
    $startFuel = static fn(array $payload, array $extraHeaders = []) => $kernel->handle('POST', $fuelPath, $headers + $extraHeaders, json_encode($payload, JSON_THROW_ON_ERROR));
}
$departureFuel = $startFuel($baseFuel)->body['transfer'];
$mannyStorageTransfers->interruptProbe($probe->id, $departureFuel['startedAt']);
$test->assertEquals('canceled', $mannyStorageTransfers->get($probe, $departureFuel['id'])['status'], 'probe departure cancels external fuel and releases its reservations');
$test->assertEquals(0.0, $sourceStock()['reserved_amount'], 'external fuel cancellation releases raw stock');
$query = $pdo->prepare("SELECT COUNT(*) FROM sector_storage_capacity_reservations WHERE inventory_kind='probe_tank' AND inventory_id=?");
$query->execute([(string) $probe->id]);
$test->assertEquals(0, (int) $query->fetchColumn(), 'all terminal external fuel transfers release tank capacity');

$detachedFuelRepository = new \VonNeumannGame\Repository\DetachedStorageContainerRepository($pdo);
foreach (['drifting', 'hidden_on_asteroid'] as $fuelMode) {
    $objectId = 'raw-fuel-' . $fuelMode;
    $detachedFuel = new \VonNeumannGame\Sector\SectorDetachedContainer($objectId, 'Raw fuel container', $fuelMode, $probe->id, 2, $probe->id,
        $fuelMode === 'hidden_on_asteroid' ? 'fuel-rock' : null, 10, 'earth_container_equivalent', gmdate('c'), [
            'sourceContainerId' => $objectId, 'container' => ['id' => $objectId, 'kind' => 'additional', 'label' => 'Raw fuel', 'rules' => []],
            'resources' => ['deuterium' => 0.02], 'items' => [],
        ]);
    $detachedFuelRepository->save($probe->currentSector, $detachedFuel);
    $actor = $mannies->createForProbe($probe->id, 'Fuel retrieval ' . $fuelMode);
    $pathForActor = '/api/probe/' . $probe->id . '/mannies/' . $actor->uid . '/transfer-deuterium-from-external-storage';
    $request = json_encode(['objectId' => $objectId, 'amount' => 0.01]);
    $setTank(99.5);
    if ($fuelMode === 'hidden_on_asteroid') {
        $test->assertEquals(404, $kernel->handle('POST', $pathForActor, $headers, $request)->status, 'external fuel requires discovery of hidden storage');
        $pdo->prepare('INSERT INTO detached_storage_container_discoveries(container_object_id,player_id,discovered_at) VALUES (?,?,?)')->execute([$objectId, $probe->playerId, gmdate('c')]);
    }
    $accepted = $kernel->handle('POST', $pathForActor, $headers, $request);
    $test->assertEquals(202, $accepted->status, 'external fuel accepts accessible ' . $fuelMode . ' containers');
    $transport = $accepted->body['transfer'];
    $test->assertEquals(0.005, $transport['tankTransfer']['acceptedAmountEce'], 'fractional remaining tank points clamp at raw ECE precision');
    $mannyStorageTransfers->complete($transport['id'], $transport['endsAt']);
    $test->assertEquals(100.0, $probes->findById($probe->id)->deuteriumStock, 'detached raw fuel fills the tank with the canonical conversion');
    $query = $pdo->prepare("SELECT amount FROM detached_storage_container_resources WHERE container_object_id=? AND resource_type='deuterium'");
    $query->execute([$objectId]);
    $test->assertEquals(0.015, (float) $query->fetchColumn(), 'detached raw fuel loses only the accepted ECE');
}

// Model and compression affect capacity, never the conversion or the fixed duration.
$improvements = new \VonNeumannGame\Repository\ProbeImprovementRepository($pdo);
$improvements->markDone($probe->id, \VonNeumannGame\Domain\ProbeImprovementCatalog::DEUTERIUM_COMPRESSION);
$pdo->prepare("UPDATE neumann_probes SET model='deuterium_tanker',deuterium_stock=790 WHERE id=?")->execute([$probe->id]);
$largeTankManny = $mannies->createForProbe($probe->id, 'Compressed tanker fuel Manny');
$largeTankPath = '/api/probe/' . $probe->id . '/mannies/' . $largeTankManny->uid . '/transfer-deuterium-from-external-storage';
$largeFuel = $kernel->handle('POST', $largeTankPath, $headers, json_encode(['objectId' => $depot['public_id'], 'amount' => 0.1]));
$test->assertEquals(202, $largeFuel->status, 'external fuel respects compressed tanker capacity');
$largeTransfer = $largeFuel->body['transfer'];
$test->assertEquals(false, $largeTransfer['tankTransfer']['clamped'], 'external fuel accepts an exact capacity fit without clamping');
$test->assertEquals(600, $largeTransfer['durationSeconds'], 'external fuel larger than normal Manny cargo still takes ten minutes');
$mannyStorageTransfers->complete($largeTransfer['id'], $largeTransfer['endsAt']);
$test->assertEquals(800.0, $probes->findById($probe->id)->deuteriumStock, 'compressed tanker receives ten points for 0.1 ECE');

$shortStockManny = $mannies->createForProbe($probe->id, 'Fuel shortage Manny');
$shortStockPath = '/api/probe/' . $probe->id . '/mannies/' . $shortStockManny->uid . '/transfer-deuterium-from-external-storage';
$setTank(0);
$stockBefore = $sourceStock();
$shortStock = $kernel->handle('POST', $shortStockPath, $headers, json_encode(['objectId' => $depot['public_id'], 'amount' => 8]));
$test->assertEquals(422, $shortStock->status, 'external fuel refuses insufficient source stock for the accepted amount');
$test->assertEquals($stockBefore, $sourceStock(), 'failed external fuel reservation leaves source stock intact');
$query->closeCursor();
$publicFuel = json_encode($kernel->handle('GET', '/api/probe/' . $probe->id . '/storage-transfers/' . $largeTransfer['id'], $headers)->body);
foreach (['sector_x', 'sector_y', 'sector_z', 'manifest_json', 'external_storage_kind'] as $internal) {
    $test->assert(!str_contains($publicFuel, $internal), 'external fuel response hides ' . $internal);
}
