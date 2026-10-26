<?php
declare(strict_types=1);

require_once __DIR__ . '/MiningCapacityFixture.php';

final class MiningCapacityCountingPdo extends PDO
{
    public int $capacityReads = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'SELECT COUNT(*) FROM manny_tasks mt')) { $this->capacityReads++; }
        return parent::prepare($query, $options);
    }
}

(static function () use ($test): void {
    $db = new MiningCapacityCountingPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
    (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-mining-capacity-' . bin2hex(random_bytes(6));
    $f = miningAtomicFixture($db, $directory);
    $sector = new \VonNeumannGame\Sector\SectorCoordinates(1770, 1770, 0);
    $amounts = array_fill_keys(array_keys(\VonNeumannGame\Domain\MineableResourceAmounts::zero()), 100.0);
    $objects = [
        new \VonNeumannGame\Sector\Asteroid('capacity-rock', 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: $amounts),
        new \VonNeumannGame\Sector\DormantConstruct('capacity-wreck', 'Wreck', subtype: \VonNeumannGame\Sector\DormantConstruct::SUBTYPE_OTHERS_MOTHERSHIP_WRECK, resourceAmounts: $amounts),
        new \VonNeumannGame\Sector\Planet('capacity-moon', 'Moon', 'rocky', 0.001, 0.001, false, 0, ['metals'], resourceAmounts: $amounts),
    ];
    $f['files']->save(new \VonNeumannGame\Sector\SectorContent($sector, $objects));
    (new \VonNeumannGame\Database\StorageTransaction($db))->run(function () use ($f, $objects, $amounts): void {
        foreach ($objects as $object) { $f['stocks']->create('fixture-' . $object->getId(), $object->getId(), $amounts); }
    });
    $refused = static function (callable $call, string $label) use ($test): void {
        try { $call(); $test->assert(false, $label . ' must refuse'); }
        catch (\VonNeumannGame\Service\MannyActionException $e) {
            $test->assertEquals([409, 'mining_target_capacity_reached'], [$e->httpStatus, $e->errorCode], $label);
        }
    };
    try {
        // All supported kinds and all players share the same admission rule.
        $service = miningCapacityService($f, 1);
        foreach ($objects as $i => $object) {
            [$probe, $manny] = miningAtomicActor($f, 'capacity-owner-' . $i, $sector);
            [$other, $candidate] = miningAtomicActor($f, 'capacity-other-' . $i, $sector);
            $active = $service->startMining($probe, $manny->uid, $object->getId(), 'metals', 0.01);
            $refused(fn() => $service->startMining($other, $candidate->uid, $object->getId(), 'ice', 0.01), 'global limit includes every player and resource for ' . $object->getId());
            $active->taskPayload['waitingFor'] = 'storage_space';
            $active->taskPayload[\VonNeumannGame\Domain\Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY] = gmdate('c');
            $f['mannies']->save($active);
            $refused(fn() => $service->startMining($other, $candidate->uid, $object->getId(), 'metals', 0.01), 'storage wait keeps its slot');
            $event = $f['events']->claim($f['events']->findById($active->taskScheduledEventId));
            $f['events']->markFailed($event, new RuntimeException('capacity fixture failure'));
            $refused(fn() => $service->startMining($other, $candidate->uid, $object->getId(), 'metals', 0.01), 'failed scheduler event keeps its slot');
            $service->recallManny($probe, $manny->uid);
            $test->assertEquals(0, $f['mannies']->countMiningAssignments($object->getId()), 'recall releases the slot before return completion');
            $replacement = $service->startMining($other, $candidate->uid, $object->getId(), 'metals', 0.01);
            $due = miningAtomicDue($f, $replacement);
            $service->processScheduledMiningTask($due);
            $test->assertEquals(0, $f['mannies']->countMiningAssignments($object->getId()), 'delivery releases the slot');
        }

        [$probe, $manny] = miningAtomicActor($f, 'capacity-batch', $sector);
        $second = $f['mannies']->createForProbe($probe->id, 'Second');
        $f['storage']->repairProbeStorage($probe);
        $beforeEvents = (int) $db->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn();
        $db->capacityReads = 0;
        $refused(fn() => $service->withPreparedBatch($probe, function () use ($service, $probe, $manny, $second): void {
            $service->startMining($probe, $manny->uid, 'capacity-rock', 'metals', 0.01);
            $service->startMining($probe, $second->uid, 'capacity-rock', 'metals', 0.01);
        }, ['capacity-rock'], true), 'overflow rolls back a pure mining batch');
        $test->assertEquals(1, $db->capacityReads, 'pure mining batch counts each target once');
        $test->assertEquals($beforeEvents, (int) $db->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn(), 'overflow leaves no scheduler event');
        $test->assertEquals(null, $f['mannies']->findById($manny->id)->currentTask, 'overflow restores the first member');
        $service->startMining($probe, $manny->uid, 'capacity-rock', 'metals', 0.01);
        $test->assertEquals(1, $f['mannies']->countMiningAssignments('capacity-rock'), 'failed batch does not leak cached occupancy into the next order');

        // Mixed batches may release places between mining starts: no stale local count.
        $service->withPreparedBatch($probe, function () use ($service, $probe, $manny, $second): void {
            $service->recallManny($probe, $manny->uid);
            $service->startMining($probe, $second->uid, 'capacity-rock', 'metals', 0.01);
        }, ['capacity-rock'], false);
        $test->assertEquals('mining', $f['mannies']->findById($second->id)->currentTask, 'mixed batch uses a released slot');
        $service->recallManny($probe, $second->uid);

        // Production default boundary, including pre-existing over-capacity objects.
        [$owner] = miningAtomicActor($f, 'capacity-existing', $sector);
        $db->beginTransaction();
        $seeded = miningCapacitySeed($f['mannies'], $owner, 'capacity-rock', 1999);
        $db->commit();
        [$lastProbe, $lastManny] = miningAtomicActor($f, 'capacity-last-slot', $sector);
        [$excessProbe, $excessManny] = miningAtomicActor($f, 'capacity-excess', $sector);
        $f['mining']->startMining($lastProbe, $lastManny->uid, 'capacity-rock', 'metals', 0.01);
        $test->assertEquals(2000, $f['mannies']->countMiningAssignments('capacity-rock'), 'default admits the 2000th miner');
        $refused(fn() => $f['mining']->startMining($excessProbe, $excessManny->uid, 'capacity-rock', 'metals', 0.01), 'default rejects the 2001st miner');
        miningCapacitySeed($f['mannies'], $owner, 'capacity-rock', 1);
        $refused(fn() => $f['mining']->startMining($excessProbe, $excessManny->uid, 'capacity-rock', 'metals', 0.01), 'existing overload refuses new assignments');
        $test->assertEquals(2001, $f['mannies']->countMiningAssignments('capacity-rock'), 'existing overload is preserved without forced recall');
        $f['mining']->startMining($excessProbe, $excessManny->uid, 'capacity-wreck', 'metals', 0.01);
        $test->assertEquals(1, $f['mannies']->countMiningAssignments('capacity-wreck'), 'another source still admits orders');

        // Additive migration does not reset locks or rewrite assignments, even on replay.
        foreach ([1, 2] as $_) {
            foreach ((new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->miningAdmissionLockStatements() as $sql) { $db->exec($sql); }
        }
        $test->assertEquals(2001, $f['mannies']->countMiningAssignments('capacity-rock'), 'schema migration replay preserves overloaded assignments');
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
})();
