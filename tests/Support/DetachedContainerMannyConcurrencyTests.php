<?php

declare(strict_types=1);

use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Repository\DetachedStorageContainerRepository;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorDetachedContainer;

(static function () use ($connect, $race, $assert): void {
    $db = $connect();
    // Rehearse the additive migration on an existing schema, then replay it.
    $db->exec('DROP TABLE detached_storage_container_inspections');
    $db->exec('DROP TABLE detached_storage_container_mannies');
    $schema = new SchemaInitializer($db->getAttribute(PDO::ATTR_DRIVER_NAME));
    foreach ($schema->detachedMannyStatements() as $statement) { $db->exec($statement); }
    $probes = new NeumannProbeRepository($db);
    $mannies = new MannyRepository($db);
    $containers = new DetachedStorageContainerRepository($db);
    $sector = new SectorCoordinates(3, 4, 5);
    $probe = $probes->createForPlayer(1, 'Occupant race', $sector);
    $occupant = $mannies->createForProbe($probe->id, 'Occupant');
    $actors = [$mannies->createForProbe($probe->id, 'First'), $mannies->createForProbe($probe->id, 'Second')];
    $objectId = 'occupied-race-container';
    $containers->save($sector, new SectorDetachedContainer($objectId, 'Race container', 'drifting', $probe->id, 1, null, null, 1.0, ProbeInventory::CAPACITY_UNIT, gmdate('c'), ['sourceContainerId' => 'race-source']));
    $occupant->probeId = null;
    $occupant->locationType = Manny::LOCATION_DETACHED_CONTAINER;
    $occupant->sector = $sector;
    $mannies->save($occupant);
    $mannies->putInDetachedContainer($occupant, $objectId, 0.05);
    $mannies->recordContainerInspection($objectId, 1);
    foreach ($schema->detachedMannyStatements() as $statement) { $db->exec($statement); }
    $assert(count($mannies->findInDetachedContainer($objectId)) === 1 && $mannies->hasInspectedContainer($objectId, 1), 'container Manny migration replay preserves occupants and inspection knowledge');
    $assert($containers->occupiedSpace($objectId) === 0.05, 'detached capacity accounts for contained Mannies');
    try {
        (new StorageTransaction($db))->run(function () use ($mannies, $occupant, $objectId): void {
            $mannies->removeFromDetachedContainer($occupant, $objectId);
            throw new RuntimeException('Simulated recovery failure');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Simulated recovery failure') { throw $e; }
    }
    $assert(count($mannies->findInDetachedContainer($objectId)) === 1, 'failed recovery rolls back occupant removal');
    $db = $probes = $mannies = $containers = null;
    $operations = [];
    foreach ($actors as $actor) {
        $operations[] = static function (PDO $pdo) use ($actor, $objectId, $probe): bool {
            return (new StorageTransaction($pdo))->run(function () use ($pdo, $actor, $objectId, $probe): bool {
                $containers = new DetachedStorageContainerRepository($pdo);
                if (!$containers->reserve($objectId, $actor->id)) { return false; }
                $mannies = new MannyRepository($pdo);
                $occupants = $mannies->findInDetachedContainer($objectId, lock: true);
                if (count($occupants) !== 1) { throw new RuntimeException('Reservation winner must own the occupant.'); }
                $occupant = $occupants[0];
                $mannies->removeFromDetachedContainer($occupant, $objectId);
                $occupant->probeId = $probe->id;
                $occupant->locationType = Manny::LOCATION_PROBE;
                $occupant->sector = null;
                $mannies->save($occupant);
                return true;
            });
        };
    }
    $results = $race($operations);
    $assert($results[0]['ok'] && $results[1]['ok'], 'concurrent occupied-container claims complete without SQL errors');
    $assert(count(array_filter($results, static fn(array $result): bool => $result['result'])) === 1, 'only one concurrent claimant can recruit the contained Manny');
    $db = $connect();
    $mannies = new MannyRepository($db);
    $assert($mannies->findInDetachedContainer($objectId) === [] && $mannies->findById($occupant->id)->probeId === $probe->id, 'concurrent recovery leaves one owned Manny and no stale container relation');
})();
