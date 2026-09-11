<?php

declare(strict_types=1);

use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\GerminationDepotRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Service\SectorEffectService;
use VonNeumannGame\Service\AnomalyBroadcastService;
use VonNeumannGame\Service\GerminationDepotService;
use VonNeumannGame\Service\SectorStorageTransferService;
use VonNeumannGame\Service\Storage\TransferLoadPlanner;

(static function ($test): void {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
    (new SchemaInitializer('sqlite'))->initialize($db);
    $now = new DateTimeImmutable('2026-09-10T10:00:00+00:00');
    $clock = static function () use (&$now): DateTimeImmutable { return $now; };
    $db->prepare('INSERT INTO players(username,created_at,updated_at) VALUES(?,?,?)')->execute(['storage-fixture',$now->format('c'),$now->format('c')]);
    $others = new OthersRepository($db);
    $fleet = $others->createFleet((int)$db->lastInsertId(), 3,4,5);
    $ships = $others->findShipsByFleetId((int)$fleet['id']);
    $ship = $others->findShipForPlayer($ships[0]['public_id'],1);
    $db->prepare("UPDATE others_inventory_resources SET amount=30 WHERE ship_id=? AND resource_type='metals'")->execute([$ship['id']]);
    $actor = $others->createAuxiliary((int)$ship['id']);
    $events = new ScheduledEventRepository($db);
    $directory = sys_get_temp_dir() . '/vng-storage-' . bin2hex(random_bytes(6));
    $files = new SectorFileRepository($directory);
    $coordinates = new SectorCoordinates(3,4,5);
    $files->save(new SectorContent($coordinates));
    $depots = new GerminationDepotRepository($db);
    $sectors = new SectorService($files,new SectorContentGenerator(),'storage-test',germinationDepots:$depots);
    $effects = new SectorEffectService($db,$events,$sectors);
    $waves = new AnomalyBroadcastService($db,$events);
    $service = new GerminationDepotService($others,$events,$sectors,$effects,$waves,$clock);
    $transfers = new SectorStorageTransferService($db,$service,$clock);
    $action = $service->build($ship,$actor,[]);
    $test->assertEquals(2.0,(float)$others->inventory((int)$ship['id'])['resources']['metals']['reserved'],'construction reserves exactly two ECE');
    $service->completeConstruction((int)$action['id'],$action['ends_at']);
    $service->completeConstruction((int)$action['id'],$action['ends_at']);
    $test->assertEquals(1,(int)$db->query('SELECT COUNT(*) FROM germination_depots')->fetchColumn(),'construction replay creates exactly one depot');
    $test->assertEquals(28.0,(float)$others->inventory((int)$ship['id'])['resources']['metals']['amount'],'construction replay consumes materials once');
    $depot = $db->query('SELECT * FROM germination_depots')->fetch();
    $projection = $sectors->getOrCreateSector($coordinates);
    $test->assertEquals(1,count($projection->getObjects()),'SQL depot appears in sector projection');
    $sectors->saveSector($projection);
    $test->assert(!str_contains((string)file_get_contents($files->getPath($coordinates)),$depot['public_id']),'sector save never copies a depot into JSON');
    $actor = $others->findAuxiliaryByPublicId($actor['public_id']);
    $deposit = $transfers->startOthers($ship,$actor,'to_storage',['depotId'=>$depot['public_id'],'resources'=>['metals'=>5],'itemIds'=>[]]);
    $transfers->completeOthers((int)$deposit['id'],$deposit['ends_at']);
    $transfers->completeOthers((int)$deposit['id'],$deposit['ends_at']);
    $test->assertEquals(5.0,(float)$db->query('SELECT amount FROM germination_depot_resources')->fetchColumn(),'deposit credits exactly once');
    $test->assertEquals(23.0,(float)$others->inventory((int)$ship['id'])['resources']['metals']['amount'],'deposit debits its source exactly once');
    $test->assertEquals(0,(int)$db->query('SELECT COUNT(*) FROM sector_storage_resource_reservations')->fetchColumn(),'successful transfer releases all resource reservations');
    $actor = $others->findAuxiliaryByPublicId($actor['public_id']);
    $withdraw = $transfers->startOthers($ship,$actor,'from_storage',['depotId'=>$depot['public_id'],'resources'=>['metals'=>4],'itemIds'=>[]]);
    $transfers->completeOthers((int)$withdraw['id'],$now->modify('+300 seconds')->format('c'),'auxiliary_destroyed');
    $test->assertEquals(3.0,(float)$db->query('SELECT amount FROM germination_depot_resources')->fetchColumn(),'destroyed auxiliary loses only the loaded two ECE');
    $test->assertEquals(0.0,(float)$db->query('SELECT reserved_amount FROM germination_depot_resources')->fetchColumn(),'partial loss releases the remaining half without losing its reservation');
    $test->assertEquals(0.0,(float)$db->query('SELECT inventory_reserved FROM others_ships WHERE id='.(int)$ship['id'])->fetchColumn(),'failed withdrawal releases destination space');
    $actor2 = $others->createAuxiliary((int)$ship['id']);
    $withdraw2 = $transfers->startOthers($ship,$actor2,'from_storage',['depotId'=>$depot['public_id'],'resources'=>['metals'=>1],'itemIds'=>[]]);
    $transfers->completeOthers((int)$withdraw2['id'],$now->modify('+100 seconds')->format('c'),'carrier_departure');
    $test->assertEquals(3.0,(float)$db->query('SELECT amount FROM germination_depot_resources')->fetchColumn(),'abandoned withdrawal does not credit the source twice');
    $test->assertEquals(1,(int)$db->query('SELECT COUNT(*) FROM sector_effects')->fetchColumn(),'dormant auxiliary has a durable sector intention');
    $effectId = (int)$db->query('SELECT id FROM sector_effects')->fetchColumn();
    $effects->apply($effectId);
    $db->exec("UPDATE sector_effects SET status='pending'");
    $effects->apply($effectId);
    $test->assertEquals(1,count($files->load($coordinates)->getObjects()),'sector projection replay does not duplicate dormant auxiliary');
    $origin = ['sector_x'=>120,'sector_y'=>-41,'sector_z'=>7];
    $recipient = ['sector_x'=>0,'sector_y'=>0,'sector_z'=>0];
    $test->assert(str_contains(AnomalyBroadcastService::message($origin,$recipient),'(50, -17, 3)'),'anomaly direction uses the specified normalization');
    $test->assert(str_contains(AnomalyBroadcastService::message($origin,$origin),'de votre secteur'),'local anomaly has the specified message');
    $probes=new \VonNeumannGame\Repository\NeumannProbeRepository($db);
    $probe=$probes->createForPlayer(1,'Depot visitor',$coordinates);
    $otherProbe=$probes->createForPlayer(1,'Other visitor',$coordinates);
    $mannies=new \VonNeumannGame\Repository\MannyRepository($db,[],$events);
    $probeItems=new \VonNeumannGame\Repository\ProbeItemRepository($db);
    $containers=new \VonNeumannGame\Repository\StorageContainerRepository($db);
    $storage=new \VonNeumannGame\Service\ProbeStorageService($containers,$probeItems,$mannies,$probes);
    $manny=$mannies->createForProbe($probe->id,'Transporter');
    $storage->initializeProbeStorage($probe);
    $mannyTransfers=new \VonNeumannGame\Service\MannyStorageTransferService($db,$mannies,$probes,$storage,[], $clock);
    $service->inspect($probe,$depot['public_id'],$now->format('c'));
    $known=$depots->knowledgeInSector($otherProbe->id,$coordinates);
    $test->assertEquals(null,$known[$depot['public_id']]['inspected_at'],'inspection knowledge is per probe, even for the same player');
    $service->impact($depot['public_id']);
    $service->inspect($probe,$depot['public_id'],$now->format('c'));
    $service->inspect($probe,$depot['public_id'],$now->format('c'));
    $test->assertEquals(1,(int)$db->query('SELECT COUNT(*) FROM anomaly_broadcasts')->fetchColumn(),'opening and repeated inspection emit one durable wave');
    $refused=false;
    try{$mannyTransfers->inventory($otherProbe,$depot['public_id']);}catch(\VonNeumannGame\Service\MannyActionException $error){$refused=$error->httpStatus===404;}
    $test->assert($refused,'guessed storage ID does not reveal inventory to another probe');
    $service->inspect($otherProbe,$depot['public_id'],$now->format('c'));
    $test->assertEquals(1,(int)$db->query('SELECT COUNT(*) FROM anomaly_broadcasts')->fetchColumn(),'inspection by another probe does not repeat the wave');
    $mannyService=new \VonNeumannGame\Service\MannyService($mannies,$probes,$sectors,$probeItems,$storage,scheduledEvents:$events,germinationDepots:$service,sectorStorageTransfers:$mannyTransfers);
    $created=$mannyTransfers->start($probe,$manny->uid,['objectId'=>$depot['public_id'],'direction'=>'from_storage','containerId'=>'probe-core','kind'=>'resources','resources'=>['metals'=>0.1]]);
    $test->assertEquals(2,$created['transfer']['tripCount'],'Manny uses two 0.05 ECE loads for 0.1 ECE');
    $test->assertEquals(3600,$created['transfer']['durationSeconds'],'Manny uses mining travel duration instead of Others duration');
    // The worker-only refresh entry point, with an injectable causal transfer clock.
    $transferManny=$mannies->findByUid($manny->uid);
    $handler=new \VonNeumannGame\Service\Manny\SectorStorageTransferTaskHandler($mannyTransfers,
        fn($p,$m)=>$storage->placeMannyOnProbe($p,$m),
        static function($m,$payload):void{$m->currentTask=\VonNeumannGame\Domain\Manny::TASK_WAITING_FOR_SPACE;$m->taskPayload=$payload;},
        static function($m,$payload):void{$m->currentTask=null;$m->taskEndsAt=null;$m->taskStartedAt=null;$m->taskPayload=$payload;},
        fn($m)=>$mannies->save($m));
    $handler->refresh($mannyService,$transferManny,$probe,new DateTimeImmutable($created['transfer']['endsAt']));
    $test->assertEquals(0.1,$storage->resourceStock($probe,'metals'),'Manny retrieves contents through its task handler');
    $test->assertEquals(true,$mannies->findByUid($manny->uid)->isOnProbe(),'Manny returns to its existing onboard storage mechanism');
    $roundtrip=$mannyTransfers->start($probe,$manny->uid,['objectId'=>$depot['public_id'],'direction'=>'to_storage','containerId'=>'probe-core','kind'=>'resources','resources'=>['metals'=>0.1]]);
    $test->assertEquals(0.0,$storage->resourceStock($probe,'metals'),'craft cannot consume resources reserved by outgoing Manny transfer');
    $handler->refresh($mannyService,$mannies->findByUid($manny->uid),$probe,new DateTimeImmutable($roundtrip['transfer']['endsAt']));
    $test->assertEquals(0.0,$storage->resourceStock($probe,'metals'),'Manny deposits resources without leaving duplicated stock');

    $db->prepare("UPDATE storage_containers SET capacity=10 WHERE probe_id=?")->execute([$probe->id]);
    $detachedRepository=new \VonNeumannGame\Repository\DetachedStorageContainerRepository($db);
    foreach(['drifting','hidden_on_asteroid'] as $mode){
        $objectId='transfer-'.$mode;
        $container=new \VonNeumannGame\Sector\SectorDetachedContainer($objectId,'External box',$mode,$otherProbe->id,2,$otherProbe->id,$mode==='hidden_on_asteroid'?'rock':null,10,'earth_container_equivalent',$now->format('c'),[
            'sourceContainerId'=>'source-'.$mode,'container'=>['id'=>'source-'.$mode,'kind'=>'additional','label'=>'External box','rules'=>[]],
            'resources'=>['metals'=>0.1],
            'items'=>[['uid'=>'whole-'.$mode,'type'=>'steel_plate','name'=>'Oversized for resource hold','containerSpace'=>1.2,'metadata'=>['fabricator'=>'probe','capacityBonus'=>0.7]]],
        ]);
        $detachedRepository->save($coordinates,$container);
        if($mode==='hidden_on_asteroid'){
            $hidden=false;
            try{$mannyTransfers->inventory($probe,$objectId);}catch(\VonNeumannGame\Service\MannyActionException $error){$hidden=$error->httpStatus===404;}
            $test->assert($hidden,'undiscovered hidden container contents remain inaccessible');
            $db->prepare('INSERT INTO detached_storage_container_discoveries(container_object_id,player_id,discovered_at) VALUES(?,?,?)')->execute([$objectId,1,$now->format('c')]);
        }
        foreach(['from_storage','to_storage'] as $direction){
            $transfer=$mannyTransfers->start($probe,$manny->uid,['objectId'=>$objectId,'direction'=>$direction,'containerId'=>'probe-core','kind'=>'resources','resources'=>['metals'=>0.1]]);
            $handler->refresh($mannyService,$mannies->findByUid($manny->uid),$probe,new DateTimeImmutable($transfer['transfer']['endsAt']));
            $test->assertEquals($direction==='from_storage'?0.1:0.0,$storage->resourceStock($probe,'metals'),$mode.' resource transfer '.$direction.' conserves contents');
        }
        foreach(['from_storage','to_storage'] as $direction){
            $transfer=$mannyTransfers->start($probe,$manny->uid,['objectId'=>$objectId,'direction'=>$direction,'containerId'=>'probe-core','kind'=>'items','itemIds'=>['whole-'.$mode]]);
            $test->assertEquals(1,$transfer['transfer']['tripCount'],$mode.' uses whole-object handling');
            $handler->refresh($mannyService,$mannies->findByUid($manny->uid),$probe,new DateTimeImmutable($transfer['transfer']['endsAt']));
        }
        $page=$mannyTransfers->inventory($probe,$objectId);
        $test->assertEquals('whole-'.$mode,$page['items'][0]['id'],$mode.' object round trip keeps its UID');
        $test->assertEquals(0.7,$page['items'][0]['metadata']['capacityBonus'],$mode.' object round trip keeps its bonus');
        $pending=$mannyTransfers->start($probe,$manny->uid,['objectId'=>$objectId,'direction'=>'from_storage','containerId'=>'probe-core','kind'=>'resources','resources'=>['metals'=>0.05]]);
        $mannyTransfers->interruptExternal($objectId,$now->modify('+1 second')->format('c'));
        $test->assertEquals('canceled',$mannyTransfers->get($probe,$pending['transfer']['id'])['status'],'moving an external target cancels a pending transfer');
        $returned=$mannies->findByUid($manny->uid);$storage->placeMannyOnProbe($probe,$returned);$returned->locationType=\VonNeumannGame\Domain\Manny::LOCATION_PROBE;$returned->sector=null;$mannies->save($returned);
    }

    // Rollback after source debit must preserve both the reservation and its original stock.
    $faultActor=$others->createAuxiliary((int)$ship['id']);
    $faultAction=$transfers->startOthers($ship,$faultActor,'to_storage',['depotId'=>$depot['public_id'],'resources'=>['metals'=>1],'itemIds'=>[]]);
    $beforeDebit=(float)$others->inventory((int)$ship['id'])['resources']['metals']['amount'];
    $db->exec("CREATE TRIGGER storage_fail_credit BEFORE INSERT ON germination_depot_resources BEGIN SELECT RAISE(ABORT,'injected credit crash'); END");
    $failed=false;
    try{$transfers->completeOthers((int)$faultAction['id'],$faultAction['ends_at']);}catch(PDOException){$failed=true;}
    $test->assert($failed,'fault injection reaches debit-before-credit boundary');
    $test->assertEquals($beforeDebit,(float)$others->inventory((int)$ship['id'])['resources']['metals']['amount'],'failed credit rolls the debit back');
    $test->assertEquals(1.0,(float)$others->inventory((int)$ship['id'])['resources']['metals']['reserved'],'failed credit preserves the queued reservation');
    $test->assertEquals('queued',$others->findActionByPublicId($faultAction['public_id'])['status'],'failed credit leaves its action retryable');
    $db->exec('DROP TRIGGER storage_fail_credit');
    $transfers->completeOthers((int)$faultAction['id'],$faultAction['ends_at']);
    $test->assertEquals($beforeDebit-1,(float)$others->inventory((int)$ship['id'])['resources']['metals']['amount'],'retry after credit crash settles exactly once');

    // Snapshot versions invalidate a page even when only a reservation changed.
    $insert=$db->prepare('INSERT INTO germination_depot_items(depot_id,public_id,type,name,container_space,metadata_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
    foreach(['paged-a','paged-b'] as $uid){$insert->execute([$depot['id'],$uid,'steel_bar','Named bar',0.01,json_encode(['fabricator'=>'probe','quality'=>1.75]),$now->format('c'),$now->format('c')]);}
    $page=$mannyTransfers->inventory($probe,$depot['public_id'],1);
    $test->assertEquals(1,count($page['items']),'inventory returns only the requested page');
    $pagedActor=$others->createAuxiliary((int)$ship['id']);
    $paged=$transfers->startOthers($ship,$pagedActor,'from_storage',['depotId'=>$depot['public_id'],'resources'=>[],'itemIds'=>['paged-a']]);
    $staleCursor=false;
    try{$mannyTransfers->inventory($probe,$depot['public_id'],1,$page['nextCursor']);}catch(\VonNeumannGame\Service\OthersActionException $error){$staleCursor=$error->errorCode==='inventory_changed';}
    $test->assert($staleCursor,'reservation change invalidates an old inventory cursor');
    $transfers->completeOthers((int)$paged['id'],$paged['ends_at']);
    $item=$db->query("SELECT * FROM others_inventory_items WHERE public_id='paged-a'")->fetch();
    $test->assertEquals(['fabricator'=>'probe','quality'=>1.75],json_decode($item['metadata_json'],true),'depot to Others preserves foreign technology and numeric bonuses');
    $test->assertEquals('Named bar',$item['name'],'item name survives inventory transport');
    $back=$transfers->startOthers($ship,$others->findAuxiliaryByPublicId($pagedActor['public_id']),'to_storage',['depotId'=>$depot['public_id'],'resources'=>[],'itemIds'=>['paged-a']]);
    $transfers->completeOthers((int)$back['id'],$back['ends_at']);
    $test->assertEquals(1,(int)$db->query("SELECT COUNT(*) FROM germination_depot_items WHERE public_id='paged-a'")->fetchColumn(),'object round trip preserves one original identity');

    $waveId=(int)$db->query('SELECT id FROM anomaly_broadcasts')->fetchColumn();
    $db->exec("CREATE TRIGGER storage_fail_alert BEFORE INSERT ON others_alerts BEGIN SELECT RAISE(ABORT,'injected alert crash'); END");
    $failed=false;
    try{$waves->deliverPage($waveId);}catch(PDOException){$failed=true;}
    $test->assert($failed,'fault injection interrupts an alert page');
    $test->assertEquals(0,(int)$db->query("SELECT ship_cursor FROM anomaly_broadcasts WHERE id=".$waveId)->fetchColumn(),'failed alert page does not advance its cursor');
    $test->assertEquals(0,(int)$db->query("SELECT COUNT(*) FROM anomaly_broadcast_deliveries WHERE recipient_kind='ship'")->fetchColumn(),'failed alert page rolls back delivery identities');
    $db->exec('DROP TRIGGER storage_fail_alert');
    $waves->deliverPage($waveId); $waves->deliverPage($waveId);
    $test->assertEquals(count($ships),(int)$db->query("SELECT COUNT(*) FROM anomaly_broadcast_deliveries WHERE recipient_kind='ship'")->fetchColumn(),'broadcast replay produces one delivery per ship');
    $test->assertEquals(count($ships),(int)$db->query('SELECT COUNT(*) FROM others_alerts')->fetchColumn(),'broadcast replay produces one alert per ship');
    $stale = $files->load($coordinates);
    $files->mutate($coordinates,static fn(SectorContent $sector)=>$sector->markEffectApplied('parallel-writer'));
    $rejected = false;
    try { $files->save($stale); } catch (\VonNeumannGame\Sector\SectorStorageException) { $rejected = true; }
    $test->assert($rejected,'stale sector writer cannot erase a committed effect');
    $db->prepare('INSERT INTO germination_depot_resources(depot_id,resource_type,amount,reserved_amount) VALUES(?,?,?,0)')->execute([$depot['id'],'deuterium',0.1]);
    $initialTank=$probe->deuteriumStock;
    $fuelCargo=$mannyTransfers->start($probe,$manny->uid,['objectId'=>$depot['public_id'],'direction'=>'from_storage','containerId'=>'probe-core','kind'=>'resources','resources'=>['deuterium'=>0.1]]);
    $handler->refresh($mannyService,$mannies->findByUid($manny->uid),$probe,new DateTimeImmutable($fuelCargo['transfer']['endsAt']));
    $test->assertEquals($initialTank,$probes->findById($probe->id)->deuteriumStock,'deuterium withdrawal does not implicitly fill the probe tank');
    $coreInventory=$storage->containerInventory($probe,'probe-core');
    $fuelRows=array_values(array_filter($coreInventory['inventory']['resourceStocks'],static fn(array $r):bool=>$r['type']==='deuterium'));
    $test->assertEquals(0.1,$fuelRows[0]['amount'],'literal deuterium cargo is visible in its fixed container');
    $fuelDeposit=$mannyTransfers->start($probe,$manny->uid,['objectId'=>$depot['public_id'],'direction'=>'to_storage','containerId'=>'probe-core','kind'=>'resources','resources'=>['deuterium'=>0.1]]);
    $handler->refresh($mannyService,$mannies->findByUid($manny->uid),$probe,new DateTimeImmutable($fuelDeposit['transfer']['endsAt']));
    $test->assertEquals($initialTank,$probes->findById($probe->id)->deuteriumStock,'deuterium deposit leaves the probe tank unchanged');
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $recallTransfer=$mannyTransfers->start($probe,$manny->uid,['objectId'=>$depot['public_id'],'direction'=>'from_storage','containerId'=>'probe-core','kind'=>'resources','resources'=>['metals'=>0.01]]);
    $mannyService->recallManny($probe,$manny->uid);
    $test->assertEquals('canceled',$mannyTransfers->get($probe,$recallTransfer['transfer']['id'])['status'],'recalling a Manny cancels its durable transfer before returning');
    $test->assertEquals(0,(int)$db->query('SELECT COUNT(*) FROM sector_storage_resource_reservations')->fetchColumn(),'recall releases the pending source reservation');
    $mannyTransfers->complete($recallTransfer['transfer']['id'],$recallTransfer['transfer']['endsAt']);
    $test->assertEquals('canceled',$mannyTransfers->get($probe,$recallTransfer['transfer']['id'])['status'],'a delayed transfer event cannot revive a recalled task');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry){if($entry->isDir()){rmdir($entry->getPathname());}else{unlink($entry->getPathname());}}
    rmdir($directory);
})($test);
