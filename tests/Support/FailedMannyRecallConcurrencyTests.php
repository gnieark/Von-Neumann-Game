<?php
declare(strict_types=1);

require_once __DIR__ . '/CraftingRetryFixture.php';

(static function () use ($connect, $race, $assert, $directory): void {
    foreach (['returning','crafting'] as $task) {
        $db=$connect(); $f=miningAtomicFixture($db,$directory);
        if ($task === 'crafting') {
            [$probe,$manny]=craftingRetryOrder($f,'recall-race-crafter');
        } else {
            $sector=new \VonNeumannGame\Sector\SectorCoordinates(829,829,0);
            [$probe,$manny]=miningAtomicActor($f,'recall-race-return',$sector);
            $manny->locationType='sector'; $manny->sector=$sector; $manny->storageContainerId=null;
            $manny->currentTask='returning'; $manny->taskStartedAt=gmdate('c',time()-1000); $manny->taskEndsAt=gmdate('c',time()-10);
            $manny->taskPayload=['reason'=>'recall']; $f['mannies']->save($manny);
        }
        $old=$f['events']->claim($f['events']->findById($manny->taskScheduledEventId));
        $f['events']->markFailed($old,new RuntimeException('original failure'));
        $probeId=$probe->id; $mannyId=$manny->id; $uid=$manny->uid; $eventId=$old->id;
        $f=$db=null;
        $recall=static function(PDO $db) use($directory,$probeId,$uid): array {
            $f=miningAtomicFixture($db,$directory);
            $manny=$f['mining']->recallManny($f['probes']->findById($probeId),$uid);
            return ['event'=>$manny->taskScheduledEventId,'task'=>$manny->currentTask];
        };
        $results=$race([$recall,$recall]);
        $assert($results[0]['ok'] && $results[1]['ok'],'concurrent failed '.$task.' recalls succeed: '.json_encode($results));
        $assert($results[0]['result']===$results[1]['result'],'concurrent recalls converge on one '.$task.' result');
        $db=$connect(); $f=miningAtomicFixture($db,$directory);
        $stmt=$db->prepare("SELECT COUNT(*) FROM scheduled_events WHERE entity_type='manny' AND entity_id=? AND status IN ('pending','running')"); $stmt->execute([$mannyId]);
        $assert((int)$stmt->fetchColumn()===($task==='returning'?1:0),'concurrent recalls create no duplicate active event for '.$task);
        $assert($f['events']->findById($eventId)->status==='failed','concurrent recalls preserve failed '.$task.' history');
        if($task==='crafting'){
            $assert($f['storage']->resourceStock($f['probes']->findById($probeId),'metals')===0.2,'concurrent crafting recalls refund ingredients once');
        } else {
            $current=$f['mannies']->findById($mannyId);
            $assert($current->taskEndsAt===$manny->taskEndsAt,'concurrent recalls preserve elapsed return deadline');
            // Keep this independent fixture out of subsequent scheduler tests.
            $db->prepare('UPDATE scheduled_events SET run_at=? WHERE id=?')->execute(['9999-12-31T00:00:00+00:00',$current->taskScheduledEventId]);
        }
        $stmt=$f=$db=null;
    }
})();
