<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$config=null;$sectorPath=null;
foreach(array_slice($argv,1) as $argument){
    if(str_starts_with($argument,'--database-config=')){$config=substr($argument,18);}
    elseif(str_starts_with($argument,'--sector-path=')){$sectorPath=substr($argument,14);}
    else{fwrite(STDERR,"Usage: php scripts/one-shot-scripts/audit-sector-storage.php [--database-config=PATH] [--sector-path=PATH]\n");exit(2);}
}
try{
    $pdo=(new \VonNeumannGame\AppFactory(dirname(__DIR__,2)))->pdo($config,initializeSchema:false);
    $checks=[];
    foreach(['others_inventory_resources','germination_depot_resources','storage_container_resources','detached_storage_container_resources'] as $table){
        $checks[$table.'_invalid']=(int)$pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE amount<0 OR reserved_amount<0 OR reserved_amount>amount')->fetchColumn();
    }
    foreach(['sector_storage_resource_reservations','sector_storage_item_claims','sector_storage_capacity_reservations'] as $table){
        $checks[$table.'_orphaned']=(int)$pdo->query("SELECT COUNT(*) FROM $table r LEFT JOIN sector_storage_transfers t ON t.id=r.transfer_id WHERE t.id IS NULL OR t.status<>'queued'")->fetchColumn();
    }
    $checks['duplicate_item_identities']=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT uid FROM (
        SELECT public_id AS uid FROM others_inventory_items UNION ALL SELECT public_id FROM germination_depot_items
        UNION ALL SELECT uid FROM probe_items UNION ALL SELECT uid FROM detached_storage_container_items WHERE is_backing_item=0
    ) identities GROUP BY uid HAVING COUNT(*)>1) duplicates")->fetchColumn();
    $checks['missing_opening_broadcast']=(int)$pdo->query("SELECT COUNT(*) FROM germination_depots d LEFT JOIN anomaly_broadcasts b ON b.depot_id=d.id WHERE d.state='open' AND b.id IS NULL")->fetchColumn();
    $checks['active_transfer_missing_actor']=(int)$pdo->query("SELECT COUNT(*) FROM sector_storage_transfers t LEFT JOIN others_auxiliaries a ON t.actor_kind='others_auxiliary' AND a.public_id=t.actor_public_id LEFT JOIN mannies m ON t.actor_kind='manny' AND m.uid=t.actor_public_id WHERE t.status='queued' AND a.id IS NULL AND m.id IS NULL")->fetchColumn();
    $checks['terminal_transfer_busy_auxiliary']=(int)$pdo->query("SELECT COUNT(*) FROM sector_storage_transfers t JOIN others_auxiliaries a ON a.current_action_id=t.others_action_id WHERE t.status<>'queued'")->fetchColumn();
    $checks['unresumable_sector_effect']=(int)$pdo->query("SELECT COUNT(*) FROM sector_effects e WHERE e.status='pending' AND NOT EXISTS(SELECT 1 FROM scheduled_events s WHERE s.type='sector.effect' AND s.entity_id=e.id AND s.status IN ('pending','running','failed'))")->fetchColumn();
    $checks['ships_over_capacity']=(int)$pdo->query("SELECT COUNT(*) FROM others_ships s WHERE s.inventory_reserved+(SELECT COALESCE(SUM(amount),0) FROM others_inventory_resources r WHERE r.ship_id=s.id)+(SELECT COALESCE(SUM(container_space),0) FROM others_inventory_items i WHERE i.ship_id=s.id)>s.inventory_capacity+0.00001")->fetchColumn();
    $checks['detached_over_capacity']=(int)$pdo->query("SELECT COUNT(*) FROM detached_storage_containers c WHERE (SELECT COALESCE(SUM(amount),0) FROM detached_storage_container_resources r WHERE r.container_object_id=c.object_id)+(SELECT COALESCE(SUM(container_space),0) FROM detached_storage_container_items i WHERE i.container_object_id=c.object_id AND i.is_backing_item=0)+(SELECT COALESCE(SUM(amount),0) FROM sector_storage_capacity_reservations r WHERE r.inventory_kind='detached' AND r.inventory_id=c.object_id)>c.capacity+0.00001")->fetchColumn();
    $checks['onboard_stock_over_capacity']=(int)$pdo->query("SELECT COUNT(*) FROM storage_containers c WHERE (SELECT COALESCE(SUM(amount),0) FROM storage_container_resources r WHERE r.container_id=c.id)+(SELECT COALESCE(SUM(container_space),0) FROM probe_items i WHERE i.storage_container_id=c.id)+(SELECT COALESCE(SUM(amount),0) FROM sector_storage_capacity_reservations r WHERE r.inventory_kind='container' AND r.inventory_id=CAST(c.id AS CHAR))>c.capacity+0.00001")->fetchColumn();
    foreach(['depot'=>['germination_depot_resources','depot_id'],'container'=>['storage_container_resources','container_id'],'detached'=>['detached_storage_container_resources','container_object_id']] as $kind=>[$table,$owner]){
        $checks[$kind.'_reservation_mismatch']=(int)$pdo->query("SELECT COUNT(*) FROM $table r WHERE ABS(r.reserved_amount-(SELECT COALESCE(SUM(q.amount),0) FROM sector_storage_resource_reservations q WHERE q.inventory_kind='$kind' AND q.inventory_id=CAST(r.$owner AS CHAR) AND q.resource_type=r.resource_type))>0.00001")->fetchColumn();
    }
    $checks['duplicate_construction']=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT construction_action_id FROM germination_depots GROUP BY construction_action_id HAVING COUNT(*)>1) duplicates')->fetchColumn();
    $checks['missing_recipient_snapshot']=(int)$pdo->query('SELECT COUNT(*) FROM anomaly_broadcasts b WHERE (SELECT COUNT(*) FROM anomaly_broadcast_recipient_counts c WHERE c.broadcast_id=b.id)<>2')->fetchColumn();
    $checks['duplicate_broadcast']=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT depot_id FROM anomaly_broadcasts GROUP BY depot_id HAVING COUNT(*)>1) duplicates')->fetchColumn();
    $app=json_decode((string)file_get_contents(__DIR__.'/../../config/app.json'),true,512,JSON_THROW_ON_ERROR);
    $sectorPath??=__DIR__.'/../../'.($app['universePath']??'data/universe');
    $depotIds=array_fill_keys($pdo->query('SELECT public_id FROM germination_depots')->fetchAll(PDO::FETCH_COLUMN),true);
    $checks['json_depot_copies']=0;$filesChecked=0;
    if(!is_dir($sectorPath)){throw new RuntimeException('Sector directory is missing: '.$sectorPath);}
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sectorPath,FilesystemIterator::SKIP_DOTS)) as $file){
        if(!$file->isFile()||$file->getExtension()!=='json'){continue;}$filesChecked++;
        $data=json_decode((string)file_get_contents($file->getPathname()),true,512,JSON_THROW_ON_ERROR);
        $walk=static function(mixed $value)use(&$walk,$depotIds,&$checks):void{
            if(!is_array($value)){return;}
            if(isset($value['id'])&&isset($depotIds[$value['id']])){$checks['json_depot_copies']++;}
            if(isset($value['germinationDepots'])||isset($value['germination_depots'])){$checks['json_depot_copies']++;}
            foreach($value as $child){if(is_array($child)){$walk($child);}}
        };$walk($data);
    }
    $losses=[];$cursor=0;
    do{
        $query=$pdo->prepare("SELECT id,result_json FROM sector_storage_transfers WHERE id>? AND status IN ('failed','canceled') ORDER BY id LIMIT 100");$query->execute([$cursor]);$rows=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){$cursor=(int)$row['id'];$result=json_decode($row['result_json']??'{}',true,512,JSON_THROW_ON_ERROR);$reason=$result['outcome']??'unknown';
            foreach($result['lost']['resources']??[] as $type=>$amount){$losses[$reason]['resources'][$type]=round(($losses[$reason]['resources'][$type]??0)+$amount,4);}
            $losses[$reason]['items']=($losses[$reason]['items']??0)+count($result['lost']['itemIds']??[]);
        }
    }while(count($rows)===100);
    $metrics=[
        'sectorFilesChecked'=>$filesChecked,
        'lossesByReason'=>$losses,
        'transfers'=>$pdo->query('SELECT status,COUNT(*) AS count FROM sector_storage_transfers GROUP BY status')->fetchAll(PDO::FETCH_ASSOC),
        'effects'=>$pdo->query('SELECT status,COUNT(*) AS count,MIN(created_at) AS oldest FROM sector_effects GROUP BY status')->fetchAll(PDO::FETCH_ASSOC),
        'broadcasts'=>$pdo->query('SELECT status,COUNT(*) AS count,SUM(probe_high_watermark-probe_cursor) AS probe_id_span_remaining,SUM(ship_high_watermark-ship_cursor) AS ship_id_span_remaining FROM anomaly_broadcasts GROUP BY status')->fetchAll(PDO::FETCH_ASSOC),
        'failedEvents'=>(int)$pdo->query("SELECT COUNT(*) FROM scheduled_events WHERE type IN ('sector.effect','anomaly.broadcast','others.action','manny.task') AND status='failed'")->fetchColumn(),
    ];
    echo json_encode(['valid'=>array_sum($checks)===0,'checks'=>$checks,'metrics'=>$metrics],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
    exit(array_sum($checks)===0?0:1);
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
