<?php
declare(strict_types=1);

require_once __DIR__ . '/CraftingRetryFixture.php';

(static function () use ($test): void {
    $db = new MiningRetryFaultPdo();
    (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-failed-recall-' . bin2hex(random_bytes(6));
    $f = miningAtomicFixture($db, $directory);
    $scheduler = miningRetryScheduler($db, $f);
    $fail = static function ($manny) use ($db, $f): array {
        $event = $f['events']->claim($f['events']->findById($manny->taskScheduledEventId));
        $f['events']->markFailed($event, new RuntimeException('original failure'));
        $s = $db->prepare('SELECT * FROM scheduled_events WHERE id=?'); $s->execute([$event->id]);
        return $s->fetch();
    };
    $history = static function (int $id) use ($db): array {
        $s = $db->prepare('SELECT * FROM scheduled_events WHERE id=?'); $s->execute([$id]); return $s->fetch();
    };
    $sector = new \VonNeumannGame\Sector\SectorCoordinates(826, 826, 0);
    $rock = new \VonNeumannGame\Sector\Asteroid('recall-rock', 'Rock', 'iron', ['metals'], 'small', 1, 1,
        resourceAmounts: array_replace(\VonNeumannGame\Domain\MineableResourceAmounts::zero(), ['metals'=>1.0]));
    $f['files']->save(new \VonNeumannGame\Sector\SectorContent($sector, [$rock]));
    (new \VonNeumannGame\Database\StorageTransaction($db))->run(fn() => $f['stocks']->create('recall-rock-create', $rock->getId(), $rock->getResourceAmounts()));
    try {
        [$probe, $manny] = miningAtomicActor($f, 'failed-miner', $sector);
        $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1);
        $old = $fail($manny);
        // Failure while creating the replacement must leave the old task intact.
        $db->faultCode = 19; $db->faultSql = 'INSERT INTO scheduled_events';
        try { $f['mining']->recallManny($probe, $manny->uid); $test->assert(false, 'recall insertion fault throws'); }
        catch (PDOException) { $test->assert(true, 'recall insertion fault rolls back'); }
        $current = $f['mannies']->findById($manny->id);
        $test->assertEquals(['mining', $old['id']], [$current->currentTask, $current->taskScheduledEventId], 'failed recall retains original task and event');
        $test->assertEquals(1, (int)$db->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn(), 'failed recall creates no orphan event');
        $test->assertEquals(0, (int)$db->query('SELECT COUNT(*) FROM probe_operations')->fetchColumn(), 'failed recall releases admission');

        $returned = $f['mining']->recallManny($probe, $manny->uid);
        $event = $f['events']->findById($returned->taskScheduledEventId);
        $test->assert($event->id !== $old['id'], 'failed mining recall creates a distinct event');
        $test->assertEquals(['returning','pending',['reason'=>'recall']], [$returned->currentTask,$event->status,$event->payload], 'new event contains only the canonical return');
        $test->assertEquals($old, $history($old['id']), 'failed mining history stays intact');
        $again = $f['mining']->recallManny($probe, $manny->uid);
        $test->assertEquals([$returned->taskScheduledEventId,$returned->taskEndsAt], [$again->taskScheduledEventId,$again->taskEndsAt], 'repeated recall neither duplicates nor postpones the return');
        miningRetryDue($db,$f,$returned);
        $test->assertEquals(1,$scheduler->processDueEvents()['processed'],'scheduler completes replacement return');
        $test->assert($f['mannies']->findById($manny->id)->isOnProbe(), 'recalled Manny is back aboard');
        $test->assertEquals(1.0,$f['stocks']->find($rock->getId())['amounts']['metals'],'recall never replays failed mining');

        // A return that itself failed retains its journey and carried resources.
        [$probe,$manny] = miningAtomicActor($f,'failed-return',$sector);
        $manny->locationType = 'sector'; $manny->sector = $sector; $manny->storageContainerId = null;
        $manny->currentTask = 'returning'; $manny->taskStartedAt = gmdate('c',time()-1000); $manny->taskEndsAt = gmdate('c',time()-10);
        $manny->cargoMetals = 0.02; $manny->taskPayload = ['reason'=>'recall']; $f['mannies']->save($manny);
        $old = $fail($manny);
        $returned = $f['mining']->recallManny($probe,$manny->uid);
        $test->assertEquals([$manny->taskStartedAt,$manny->taskEndsAt,0.02],[$returned->taskStartedAt,$returned->taskEndsAt,$returned->cargoMetals],'failed return keeps elapsed journey and cargo');
        $test->assertEquals(1,$scheduler->processDueEvents()['processed'],'overdue return executes immediately');
        $f['mining']->recallManny($probe,$manny->uid);
        $test->assertEquals(0.02,$f['storage']->resourceStock($probe,'metals'),'return deposits cargo once');
        $test->assertEquals($old,$history($old['id']),'failed return history stays intact');

        [$probe,$manny] = miningAtomicActor($f,'failed-waiting',$sector);
        $manny->locationType='sector'; $manny->sector=$sector; $manny->storageContainerId=null;
        $manny->currentTask='waiting_for_space'; $manny->taskStartedAt=gmdate('c',time()-100); $manny->taskEndsAt=null;
        $reserved=['type'=>'steel_plate','name'=>'Plate','quantity'=>1,'containerSpace'=>0.01];
        $manny->taskPayload=['reason'=>'return_to_probe','reservedItem'=>$reserved,
            \VonNeumannGame\Domain\Manny::WAITING_FOR_SPACE_SINCE_PAYLOAD_KEY=>$manny->taskStartedAt,
            \VonNeumannGame\Domain\Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY=>gmdate('c',time()-1)]; $f['mannies']->save($manny);
        $old=$fail($manny);
        $waiting=$f['mining']->recallManny($probe,$manny->uid);
        $test->assertEquals('waiting_for_space',$waiting->currentTask,'failed wait keeps its reserved delivery');
        $test->assertEquals($reserved,$waiting->taskPayload['reservedItem'],'replacement preserves reserved item payload');
        $test->assert($waiting->taskScheduledEventId!==$old['id'],'failed wait gets a new active event despite early recall return');
        $test->assertEquals(1,$scheduler->processDueEvents()['processed'],'scheduler completes reserved delivery after failed wait');
        $test->assertEquals(1,craftingRetryOutputCount($db,$probe->id),'reserved item delivered exactly once');

        foreach ([false,true] as $printer) {
            [$probe,$manny] = craftingRetryOrder($f,$printer?'failed-printer-recall':'failed-crafting-recall',$printer);
            $old = $fail($manny);
            $result = $f['mining']->recallManny($probe,$manny->uid);
            $test->assertEquals(null,$result->currentTask,'failed crafting recall cancels fabrication');
            $test->assertEquals(null,$result->taskScheduledEventId,'cancelled fabrication no longer points to failed event');
            $f['mining']->recallManny($f['probes']->findById($probe->id),$manny->uid);
            $test->assertEquals(0.2,$f['storage']->resourceStock($probe,'metals'),'failed crafting ingredients refunded exactly once');
            $test->assertEquals(1.0,$f['probes']->findById($probe->id)->deuteriumStock,'printer fuel refunded exactly once');
            $test->assertEquals(0,craftingRetryOutputCount($db,$probe->id),'recall does not deliver cancelled output');
            $test->assertEquals($old,$history($old['id']),'failed crafting history stays intact');
        }

        // Consumed components live in a separate table: load them before unlinking the event.
        [$probe,$manny] = miningAtomicActor($f,'failed-components-recall',$sector);
        $recipe = \VonNeumannGame\Domain\CraftingRecipeCatalog::find('manny'); $types=[];
        foreach ($recipe['ingredients'] as $ingredient) {
            for ($i=0;$i<$ingredient['quantity'];$i++) {
                $f['items']->create($probe->id,$ingredient['type'],$ingredient['type'],0.01,storageContainerId:$manny->storageContainerId);
                $types[]=$ingredient['type'];
            }
        }
        $manny=$f['mining']->startCrafting($probe,$manny->uid,'manny'); $fail($manny);
        $test->assertEquals(0,count($f['items']->findByProbeId($probe->id)),'components consumed before failed fabrication');
        $f['mining']->recallManny($probe,$manny->uid); $f['mining']->recallManny($probe,$manny->uid);
        $actual=array_map(fn($item)=>$item->type,$f['items']->findByProbeId($probe->id)); sort($types); sort($actual);
        $test->assertEquals($types,$actual,'recall restores each consumed component exactly once');
    } finally {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());} rmdir($directory);
    }
})();
