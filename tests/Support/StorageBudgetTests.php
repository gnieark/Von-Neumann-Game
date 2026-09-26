<?php

declare(strict_types=1);

require_once __DIR__.'/StorageTestPdo.php';

(static function($test):void{
    $db=new StorageTestPdo(new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]));
    (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
    $now=gmdate('c');$db->prepare('INSERT INTO players(username,created_at,updated_at) VALUES(?,?,?)')->execute(['budget-player',$now,$now]);
    $probes=new \VonNeumannGame\Repository\NeumannProbeRepository($db);$probe=$probes->createForPlayer(1,'Budget observer',new \VonNeumannGame\Sector\SectorCoordinates(3,4,5));
    $depots=new \VonNeumannGame\Repository\GerminationDepotRepository($db);$coordinates=$probe->currentSector;$created=0;$measurements=[];
    foreach([1,10,100] as $count){
        while($created<$count){$depot=$depots->create(++$created,$coordinates,$now);}
        $db->metrics->reset();$projections=$depots->projections($coordinates);$knowledge=$depots->knowledgeInSector($probe->id,$coordinates);
        $measurements['depots'][$count]=$db->metrics->snapshot();
        $test->assertEquals(2,$db->metrics->queries,'projection and personal knowledge use two queries for '.$count.' depots');
        $test->assertEquals($count,count($projections),'projection returns the expected number of depots');
    }
    $insert=$db->prepare('INSERT INTO germination_depot_items(depot_id,public_id,type,name,container_space,metadata_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
    $created=0;$ids=[];$port=new \VonNeumannGame\Repository\Storage\SqlInventoryTransferRepository($db,'depot',(int)$depot['id']);
    foreach([0,99,100,101,500] as $count){
        while($created<$count){$uid='budget-item-'.(++$created);$ids[]=$uid;$insert->execute([$depot['id'],$uid,'steel_bar','Bar',0.01,'{}',$now,$now]);}
        $db->metrics->reset();$page=$depots->inventory($depot,1);$measurement=$db->metrics->snapshot();
        $test->assertEquals(2,$measurement['queries'],'fixed inventory page query budget at '.$count.' stored objects');
        $test->assert($measurement['rows']<=2,'fixed inventory page row budget ignores total stock');
        $db->metrics->reset();$items=$port->items($ids);$measurement=$db->metrics->snapshot();$measurements['items'][$count]=$measurement;
        $test->assertEquals(5*(int)ceil($count/100),$measurement['queries'],'selected identities use five batched reads per hundred');
        $test->assert($measurement['maxParameters']<=101,'identity selection parameter blocks are bounded');
        $test->assertEquals($count,count($items),'selected item hydration has no missing identities');
    }
    $db->metrics->reset();$duplicateRejected=false;
    try{$port->items([$ids[0],$ids[0]]);}catch(\VonNeumannGame\Service\OthersActionException){$duplicateRejected=true;}
    $test->assert($duplicateRejected,'duplicate selected identities are rejected');
    $test->assert($db->metrics->maxParameters<=101,'duplicate identity lookup remains inside the batch parameter bound');

    $mannies=new \VonNeumannGame\Repository\MannyRepository($db);$probeItems=new \VonNeumannGame\Repository\ProbeItemRepository($db);$containers=new \VonNeumannGame\Repository\StorageContainerRepository($db);$probeStorage=new \VonNeumannGame\Service\ProbeStorageService($containers,$probeItems,$mannies,$probes);$probeStorage->initializeProbeStorage($probe);
    $insertContainer=$db->prepare("INSERT INTO storage_containers(probe_id,uid,kind,label,sort_order,capacity,priority_filter_json,exclusion_filter_json,strict_exclusion_filter_json,created_at,updated_at) VALUES(?,?,'container',?,?,1,'[]','[]','[]',?,?)");
    $insertResource=$db->prepare("INSERT INTO storage_container_resources(container_id,resource_type,amount,reserved_amount,updated_at) VALUES(?,'metals',0.001,0,?)");
    $containerIds=[];
    for($index=1;$index<=201;$index++){$insertContainer->execute([$probe->id,'budget-container-'.$index,'Budget '.$index,$index,$now,$now]);$containerIds[]=(int)$db->lastInsertId();}
    foreach([199,200,201] as $count){
        $db->exec("DELETE FROM storage_container_resources WHERE resource_type='metals'");
        foreach(array_slice($containerIds,0,$count) as $containerId){$insertResource->execute([$containerId,$now]);}
        $db->metrics->reset();$consumed=$probeStorage->consumeResource($probe,'metals',$count/1000);$measurement=$db->metrics->snapshot();$measurements['consumption'][$count]=$measurement;
        $test->assertEquals($count/1000,$consumed,'resource consumption preserves the requested amount at batch boundary '.$count);
        $test->assertEquals($count<=200?6:9,$measurement['queries'],'resource consumption follows K0 + 3 × ceil(N / 200) at '.$count.' rows');
        $test->assert($measurement['maxParameters']<=802,'resource consumption stays under the SQLite parameter limit at '.$count.' rows');
    }
    $plan=$db->query("EXPLAIN QUERY PLAN SELECT c.id,ROUND(r.amount-r.reserved_amount,4) FROM storage_containers c JOIN storage_container_resources r ON r.container_id=c.id WHERE c.probe_id=".$probe->id." AND r.resource_type='metals' ORDER BY c.sort_order,c.id")->fetchAll(PDO::FETCH_ASSOC);
    $planJson=json_encode($plan,JSON_THROW_ON_ERROR);
    $test->assert(str_contains($planJson,'idx_storage_containers_probe_id'),'resource consumption plan indexes containers by probe');
    $test->assert(str_contains($planJson,'sqlite_autoindex_storage_container_resources_1'),'resource consumption plan indexes stock by container and type');
    $events=new \VonNeumannGame\Repository\ScheduledEventRepository($db);$transaction=new \VonNeumannGame\Database\StorageTransaction($db);$locks=new \VonNeumannGame\Repository\Storage\StorageLockRepository($db);$waves=new \VonNeumannGame\Service\AnomalyBroadcastService(new \VonNeumannGame\Repository\Storage\AnomalyBroadcastRepository($db),$transaction,$locks,$events,new \VonNeumannGame\Repository\OthersAuditRepository($db));
    $insert=$db->prepare("INSERT INTO neumann_probes(player_id,name,sector_x,sector_y,sector_z,status,entered_current_sector_at,created_at,updated_at) VALUES(1,?,3,4,5,'idle',?,?,?)");$created=1;
    foreach([1,100,1000] as $count){
        while($created<$count){$insert->execute(['Recipient '.(++$created),$now,$now,$now]);}
        $depot=$depots->create(1000+$count,$coordinates,$now);
        $db->beginTransaction();$waves->enqueue($depot,$now);$db->commit();
        $id=(int)$db->query('SELECT MAX(id) FROM anomaly_broadcasts')->fetchColumn();
        $db->metrics->reset();
        for($page=0;$page<(int)ceil($count/100);$page++){$waves->deliverPage($id);}
        $measurement=$db->metrics->snapshot();$measurements['recipients'][$count]=$measurement;
        $test->assert($measurement['queries']<=13*(int)ceil($count/100),'broadcast SQL grows by pages for '.$count.' recipients');
        $test->assert($measurement['maxParameters']<=800,'broadcast inserts remain below 999 parameters');
        $test->assertEquals($count,(int)$db->query('SELECT COUNT(*) FROM anomaly_broadcast_deliveries WHERE broadcast_id='.$id)->fetchColumn(),'broadcast delivered exactly one record per recipient');
    }
    $depot=$depots->create(3000,$coordinates,$now);
    $db->beginTransaction();$waves->enqueue($depot,$now);$db->commit();
    $wave=(int)$db->query('SELECT MAX(id) FROM anomaly_broadcasts')->fetchColumn();
    $db->exec('DELETE FROM neumann_probes WHERE id=1000');
    for($page=0;$page<10;$page++){$waves->deliverPage($wave);}
    $audit=json_decode($db->query("SELECT details_json FROM others_operator_audit WHERE outcome='recipients_missing'")->fetchColumn(),true);
    $test->assertEquals(['probe'=>1],$audit['missing'],'a physically deleted recipient is recorded without recreating it');
    $waves->deliverPage($wave);
    $test->assertEquals(1,(int)$db->query("SELECT COUNT(*) FROM others_operator_audit WHERE outcome='recipients_missing'")->fetchColumn(),'completed broadcast replay does not duplicate missing-recipient audit');
    echo 'STORAGE SQL BUDGETS '.json_encode($measurements,JSON_THROW_ON_ERROR).PHP_EOL;
})($test);
