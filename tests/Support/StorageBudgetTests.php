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
    $created=0;$ids=[];$port=new \VonNeumannGame\Service\Storage\SqlInventoryTransferPort($db,'depot',(int)$depot['id']);
    foreach([1,50,500] as $count){
        while($created<$count){$uid='budget-item-'.(++$created);$ids[]=$uid;$insert->execute([$depot['id'],$uid,'steel_bar','Bar',0.01,'{}',$now,$now]);}
        $db->metrics->reset();$page=$depots->inventory($depot,1);$measurement=$db->metrics->snapshot();
        $test->assertEquals(2,$measurement['queries'],'fixed inventory page query budget at '.$count.' stored objects');
        $test->assert($measurement['rows']<=2,'fixed inventory page row budget ignores total stock');
        $db->metrics->reset();$items=$port->items($ids);$measurement=$db->metrics->snapshot();$measurements['items'][$count]=$measurement;
        $test->assertEquals(5*(int)ceil($count/100),$measurement['queries'],'selected identities use five batched reads per hundred');
        $test->assert($measurement['maxParameters']<=101,'identity selection parameter blocks are bounded');
        $test->assertEquals($count,count($items),'selected item hydration has no missing identities');
    }
    $events=new \VonNeumannGame\Repository\ScheduledEventRepository($db);$waves=new \VonNeumannGame\Service\AnomalyBroadcastService($db,$events);
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
