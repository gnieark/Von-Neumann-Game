<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use PDO;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\GerminationDepotRepository;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ItemMetadataColumns;
use VonNeumannGame\Service\Storage\SqlInventoryTransferPort;
use VonNeumannGame\Service\Storage\TransferLoadPlanner;

final class MannyStorageTransferService
{
    public const TASK = 'transferring_sector_storage';
    private readonly StorageTransaction $transaction;
    private readonly TransferLoadPlanner $planner;
    private readonly \Closure $clock;

    public function __construct(private readonly PDO $pdo, private readonly MannyRepository $mannies, private readonly NeumannProbeRepository $probes,
        private readonly ProbeStorageService $storage, private readonly array $config = [], ?\Closure $clock = null)
    {
        $this->transaction = new StorageTransaction($pdo);
        $this->planner = new TransferLoadPlanner();
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function start(NeumannProbe $selectedProbe, string $mannyUid, array $payload): array
    {
        $kind = $payload['kind'] ?? null;
        $keys = ['objectId','direction','containerId','kind', $kind === 'resources' ? 'resources' : 'itemIds'];
        if (!in_array($kind,['resources','items'],true) || array_diff(array_keys($payload),$keys) !== [] || array_diff($keys,array_keys($payload)) !== []
            || !in_array($payload['direction'] ?? null,['to_storage','from_storage'],true)
            || !is_string($payload['objectId']) || $payload['objectId'] === '' || !is_string($payload['containerId']) || $payload['containerId'] === '') {
            throw new MannyActionException(400,'bad_request','Invalid storage transfer payload.');
        }
        $resources=$kind==='resources' ? $payload['resources'] : [];
        $ids=$kind==='items' ? $payload['itemIds'] : [];
        if (!is_array($resources) || !is_array($ids) || !array_is_list($ids) || count($ids)>500 || ($resources===[] && $ids===[])) { throw new MannyActionException(400,'bad_request','Nonempty content is required.'); }
        foreach($ids as $id){if(!is_string($id)||$id===''){throw new MannyActionException(400,'bad_request','Invalid item ID.');}}
        if(count(array_unique($ids,SORT_STRING))!==count($ids)){throw new MannyActionException(400,'bad_request','Duplicate item IDs.');}
        if(array_diff(array_keys($resources),TransferLoadPlanner::RESOURCE_TYPES)!==[]){throw new MannyActionException(400,'bad_request','Unknown resource type.');}
        try { foreach($resources as $amount){TransferLoadPlanner::units($amount);} }
        catch(\InvalidArgumentException $error){throw new MannyActionException(400,'bad_request',$error->getMessage());}
        return $this->transaction->run(function()use($selectedProbe,$mannyUid,$payload,$resources,$ids,$kind):array{
            $this->transaction->lock('probe',$selectedProbe->id);
            $probe=$this->probes->findById($selectedProbe->id) ?? throw new MannyActionException(404,'not_found','Probe not found.');
            if (!in_array($probe->status,[ProbeStatus::Idle,ProbeStatus::Orbiting],true)) { throw new MannyActionException(409,'probe_busy','The probe must be available in its sector.'); }
            $manny=$this->mannies->findByUidForProbe($probe->id,$mannyUid);
            if($manny===null){throw new MannyActionException(404,'manny_not_found','Manny not found.');}
            $this->transaction->lock('manny',$manny->id);
            $manny=$this->mannies->findById($manny->id) ?? throw new \RuntimeException('Manny missing.');
            if(!$manny->isOnProbe()||$manny->currentTask!==null){throw new MannyActionException(409,'manny_busy','An idle embarked Manny is required.');}
            $query=$this->pdo->prepare('SELECT * FROM storage_containers WHERE probe_id=? AND uid=?');$query->execute([$probe->id,$payload['containerId']]);
            $container=$query->fetch(PDO::FETCH_ASSOC);
            if(!$container){throw new MannyActionException(404,'not_found','Container not found.');}
            $this->transaction->lock('container',(int)$container['id']);
            $external=$this->external($probe,$payload['objectId']);
            $outgoing=$payload['direction']==='to_storage';
            if($outgoing){$this->storage->assertContentsAvailableForTransfer($probe,$payload['containerId'],$resources,$ids);}
            $source=$outgoing ? new SqlInventoryTransferPort($this->pdo,'container',(int)$container['id']) : $external['port'];
            $items=$source->items($ids);
            $types=[...array_keys($resources),...array_column($items,'type')];
            if ($outgoing && $external['kind']==='detached') {
                $rules=$this->pdo->prepare("SELECT resource_type FROM detached_storage_container_rules WHERE container_object_id=? AND rule_kind='strictExclusion'");
                $rules->execute([$external['id']]);
                if(array_intersect($types,$rules->fetchAll(PDO::FETCH_COLUMN))!==[]){throw new MannyActionException(422,'storage_exclusion','The destination excludes this content.');}
            }
            $capacity=$this->storage->transferContainerCapacity($probe,$payload['containerId'],$outgoing?[]:$types);
            $onboard=new SqlInventoryTransferPort($this->pdo,'container',(int)$container['id'],(float)$capacity['available'],$probe->id);
            $destination=$outgoing?$external['port']:$onboard;
            $now=($this->clock)();
            $cargo=(float)($this->config['manny']['cargoCapacity']??0.05);
            $seconds=$kind==='resources' ? 2*(int)($this->config['manny']['actions']['miningTravelSeconds']??900) : (int)($this->config['manny']['actions']['salvageSeconds']??300);
            try{$plan=$this->planner->plan($resources,$items,$cargo,$seconds,$kind==='items');$ends=$this->planner->endsAt($plan,$now);}
            catch(\InvalidArgumentException $error){throw new MannyActionException(422,'invalid_transfer',$error->getMessage());}
            $publicId='storage_transfer_'.bin2hex(random_bytes(12));
            $query=$this->pdo->prepare("INSERT INTO sector_storage_transfers(public_id,player_id,actor_kind,actor_public_id,probe_id,manny_id,external_storage_kind,external_storage_id,object_public_id,container_id,container_public_id,direction,status,manifest_json,resources_json,items_json,started_at,ends_at,updated_at) VALUES(?,?,'manny',?,?,?,?,?,?,?,?,?,'queued',?,?,?,?,?,?)");
            $query->execute([$publicId,$probe->playerId,$manny->uid,$probe->id,$manny->id,$external['kind'],(string)$external['id'],$payload['objectId'],$container['id'],$container['uid'],$payload['direction'],json_encode($plan,JSON_THROW_ON_ERROR),json_encode($resources,JSON_THROW_ON_ERROR),json_encode($items,JSON_THROW_ON_ERROR),$now->format('c'),$ends->format('c'),$now->format('c')]);
            $transferId=(int)$this->pdo->lastInsertId();
            $source->reserve($transferId,0,$resources,$items,$now->format('c'));
            $destination->reserveCapacity($transferId,$plan['totalUnits']/10000,$now->format('c'));
            $this->storage->releaseMannyFromStorage($manny);
            $manny->locationType=Manny::LOCATION_SECTOR;$manny->sector=$probe->currentSector;
            $manny->currentTask=self::TASK;$manny->taskStartedAt=$now->format('c');$manny->taskEndsAt=$ends->format('c');
            $manny->taskPayload=['transferId'=>$publicId,'objectId'=>$payload['objectId'],'direction'=>$payload['direction'],'durationSeconds'=>$plan['durationSeconds']];
            $this->mannies->save($manny);
            return ['transfer'=>$this->get($probe,$publicId),'manny'=>$this->mannies->findById($manny->id)];
        });
    }

    /** Rechecks discovery before reading any content. Parent row locks serialize partial stock mutations. */
    private function external(NeumannProbe $probe,string $objectId, bool $withCapacity = true):array
    {
        $repository=new GerminationDepotRepository($this->pdo);
        $depot=$repository->find($objectId);
        if($depot!==null){
            $row=$this->transaction->lock('depot',(int)$depot['id']);
            $query=$this->pdo->prepare('SELECT access_discovered_at FROM germination_depot_probe_knowledge WHERE depot_id=? AND probe_id=?');$query->execute([$row['id'],$probe->id]);$access=$query->fetchColumn();
            if($row['state']!=='open'||$access===false||$access===null||!$this->local($probe,$row)){throw new MannyActionException(404,'not_found','Storage not found.');}
            return ['kind'=>'depot','id'=>(int)$row['id'],'row'=>$row,'port'=>new SqlInventoryTransferPort($this->pdo,'depot',(int)$row['id'])];
        }
        $row=$this->transaction->lock('detached',$objectId);
        if(!$row||!$this->local($probe,$row)||!in_array($row['mode'],['drifting','hidden_on_asteroid'],true)){throw new MannyActionException(404,'not_found','Storage not found.');}
        if($row['mode']==='hidden_on_asteroid'){
            $query=$this->pdo->prepare('SELECT 1 FROM detached_storage_container_discoveries WHERE container_object_id=? AND player_id=?');$query->execute([$objectId,$probe->playerId]);
            if($query->fetchColumn()===false){throw new MannyActionException(404,'not_found','Storage not found.');}
        }
        if($row['status']!=='available'){throw new MannyActionException(409,'storage_reserved','The container is reserved for recovery.');}
        $free=null;
        if ($withCapacity) {
        $query=$this->pdo->prepare("SELECT (SELECT COALESCE(SUM(amount),0) FROM detached_storage_container_resources WHERE container_object_id=?) +(SELECT COALESCE(SUM(container_space),0) FROM detached_storage_container_items WHERE container_object_id=? AND is_backing_item=0)+(SELECT COALESCE(SUM(amount),0) FROM sector_storage_capacity_reservations WHERE inventory_kind='detached' AND inventory_id=?)");
        $query->execute([$objectId,$objectId,$objectId]);$free=round((float)$row['capacity']-(float)$query->fetchColumn(),4);
        }
        return ['kind'=>'detached','id'=>$objectId,'row'=>$row,'port'=>new SqlInventoryTransferPort($this->pdo,'detached',$objectId,$free)];
    }

    private function local(NeumannProbe $probe,array $row):bool
    {
        return [(int)$row['sector_x'],(int)$row['sector_y'],(int)$row['sector_z']]===[$probe->currentSector->getX(),$probe->currentSector->getY(),$probe->currentSector->getZ()];
    }

    public function get(NeumannProbe $probe,string $id):array
    {
        $query=$this->pdo->prepare("SELECT * FROM sector_storage_transfers WHERE public_id=? AND probe_id=? AND player_id=? AND actor_kind='manny'");$query->execute([$id,$probe->id,$probe->playerId]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if(!$row){throw new MannyActionException(404,'not_found','Transfer not found.');}
        return $this->present($row);
    }

    private function present(array $row):array
    {
        $plan=json_decode($row['manifest_json'],true,512,JSON_THROW_ON_ERROR);
        return \VonNeumannGame\Service\Storage\StoragePublicData::normalize(['id'=>$row['public_id'],'actor'=>['kind'=>'manny','id'=>$row['actor_public_id']],'objectId'=>$row['object_public_id'],'containerId'=>$row['container_public_id'],
            'direction'=>$row['direction'],'resources'=>json_decode($row['resources_json'],true,512,JSON_THROW_ON_ERROR),'itemIds'=>array_column(json_decode($row['items_json'],true,512,JSON_THROW_ON_ERROR),'id'),
            'status'=>$row['status'],'startedAt'=>$row['started_at'],'endsAt'=>$row['ends_at'],'durationSeconds'=>$plan['durationSeconds'],'tripCount'=>$plan['tripCount'],
            'result'=>$row['result_json']===null?null:json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR),'error'=>$row['error_json']===null?null:json_decode($row['error_json'],true,512,JSON_THROW_ON_ERROR)]);
    }

    /** Delivery commits once; returning the actor to storage is handled separately by the Manny handler. */
    public function complete(string $id,string $causalTime,?string $reason=null):array
    {
        return $this->transaction->run(function()use($id,$causalTime,$reason):array{
            $query=$this->pdo->prepare('SELECT * FROM sector_storage_transfers WHERE public_id=?');$query->execute([$id]);$initial=$query->fetch(PDO::FETCH_ASSOC);
            if(!$initial||$initial['actor_kind']!=='manny'){throw new \RuntimeException('Manny transfer missing.');}
            $this->transaction->lock('probe',(int)$initial['probe_id']);
            if($initial['manny_id']!==null){$this->transaction->lock('manny',(int)$initial['manny_id']);}
            $row=$this->transaction->lock('transfer',(int)$initial['id']);
            if($row['status']!=='queued'){return $this->present($row);}
            $this->transaction->lock('container',(int)$row['container_id']);
            $this->transaction->lock($row['external_storage_kind'],$row['external_storage_kind']==='depot'?(int)$row['external_storage_id']:$row['external_storage_id']);
            $plan=json_decode($row['manifest_json'],true,512,JSON_THROW_ON_ERROR);$elapsed=strtotime($causalTime)-strtotime($row['started_at']);
            if($elapsed >= $plan['durationSeconds']){$reason=null;}elseif($reason===null){return $this->present($row);}
            $probe=$this->probes->findById((int)$row['probe_id']);
            $onboard=new SqlInventoryTransferPort($this->pdo,'container',(int)$row['container_id'],probeId:(int)$row['probe_id']);
            $external=new SqlInventoryTransferPort($this->pdo,$row['external_storage_kind'],$row['external_storage_kind']==='depot'?(int)$row['external_storage_id']:$row['external_storage_id']);
            [$source,$destination]=$row['direction']==='to_storage'?[$onboard,$external]:[$external,$onboard];
            $resources=json_decode($row['resources_json'],true,512,JSON_THROW_ON_ERROR);$items=json_decode($row['items_json'],true,512,JSON_THROW_ON_ERROR);
            $lost=['resources'=>[],'itemIds'=>[]];$delivered=['resources'=>[],'itemIds'=>[]];
            if($reason===null){
                $source->debit((int)$row['id'],0,$resources,array_column($items,'id'),$causalTime);$destination->credit($resources,$items,$causalTime);
                $delivered=['resources'=>$resources,'itemIds'=>array_column($items,'id')];
            }elseif($reason==='manny_destroyed'){
                $cargo=$this->planner->cargoAt($plan,$elapsed,$row['direction']);
                foreach($cargo['resources'] as $type=>$units){$lost['resources'][$type]=$units/10000;}$lost['itemIds']=$cargo['itemIds'];
                $source->debit((int)$row['id'],0,$lost['resources'],$lost['itemIds'],$causalTime);
            }
            $source->release((int)$row['id'],0,$causalTime);$destination->release((int)$row['id'],0,$causalTime);
            $released=['resources'=>[],'itemIds'=>array_values(array_diff(array_column($items,'id'),$delivered['itemIds'],$lost['itemIds']))];
            foreach($resources as $type=>$amount){
                $remaining=round($amount-($delivered['resources'][$type]??0)-($lost['resources'][$type]??0),4);
                if($remaining>0){$released['resources'][$type]=$remaining;}
            }
            $result=['outcome'=>$reason??'delivered','delivered'=>$delivered,'lost'=>$lost,'released'=>$released];
            $status=$reason===null?'succeeded':($reason==='manny_destroyed'?'failed':'canceled');
            $this->pdo->prepare('UPDATE sector_storage_transfers SET status=?,version=version+1,result_json=?,updated_at=? WHERE id=?')->execute([$status,json_encode($result,JSON_THROW_ON_ERROR),$causalTime,$row['id']]);
            $row['status']=$status;$row['result_json']=json_encode($result,JSON_THROW_ON_ERROR);
            if($reason!==null && $row['manny_id']!==null){
                $manny=$this->mannies->findById((int)$row['manny_id']);
                if($manny!==null && $manny->currentTask===self::TASK){$manny->currentTask=null;$manny->taskEndsAt=null;$manny->taskStartedAt=null;$manny->taskPayload=['lastTask'=>self::TASK,'transferId'=>$id,'result'=>$status];$this->mannies->save($manny);}
            }
            return $this->present($row);
        });
    }

    public function interruptProbe(int $probeId,string $now,string $reason='carrier_departure'):void { $this->interrupt('probe_id',$probeId,$now,$reason); }
    public function interruptManny(int $mannyId,string $now):void { $this->interrupt('manny_id',$mannyId,$now,'manny_destroyed'); }
    public function interruptExternal(string $id,string $now):void { $this->interrupt('object_public_id',$id,$now,'target_moved'); }
    private function interrupt(string $column,int|string $id,string $now,string $reason):void
    {
        $query=$this->pdo->prepare("SELECT public_id FROM sector_storage_transfers WHERE $column=? AND actor_kind='manny' AND status='queued' ORDER BY id");$query->execute([$id]);
        foreach($query->fetchAll(PDO::FETCH_COLUMN) as $uid){$this->complete($uid,$now,$reason);}
    }

    public function inventory(NeumannProbe $probe,string $id,int $limit=100,?string $cursor=null):array
    {
        return $this->transaction->run(function()use($probe,$id,$limit,$cursor):array{
            $this->transaction->lock('probe',$probe->id);
            $probe=$this->probes->findById($probe->id) ?? throw new MannyActionException(404,'not_found','Probe not found.');
            $external=$this->external($probe,$id,false);$row=$external['row'];
            if($external['kind']==='depot'){$page=(new GerminationDepotRepository($this->pdo))->inventory($row,$limit,$cursor);unset($page['depotId']);return ['objectId'=>$id]+$page;}
            if($limit<1||$limit>500){throw new MannyActionException(400,'bad_request','Invalid page size.');}
            $after=0;
            if($cursor!==null){
                try{$data=json_decode(base64_decode($cursor,true)?:'',true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new MannyActionException(400,'bad_request','Invalid cursor.');}
                if(!is_array($data)||array_keys($data)!==['storage','version','after']||$data['storage']!==$id||!is_int($data['version'])||!is_int($data['after'])||$data['after']<0){throw new MannyActionException(400,'bad_request','Invalid cursor.');}
                if($data['version']!==(int)$row['storage_version']){throw new MannyActionException(409,'inventory_changed','Inventory changed; restart pagination.');}$after=$data['after'];
            }
            $query=$this->pdo->prepare('SELECT * FROM detached_storage_container_items WHERE container_object_id=? AND is_backing_item=0 AND id>? ORDER BY id LIMIT '.($limit+1));$query->execute([$id,$after]);$items=$query->fetchAll(PDO::FETCH_ASSOC);$more=count($items)>$limit;if($more){array_pop($items);}
            $query=$this->pdo->prepare('SELECT resource_type,amount,reserved_amount FROM detached_storage_container_resources WHERE container_object_id=? ORDER BY resource_type');$query->execute([$id]);
            return ['objectId'=>$id,'resources'=>array_map(static fn(array $r):array=>['type'=>$r['resource_type'],'amount'=>(float)$r['amount'],'reservedAmount'=>(float)$r['reserved_amount'],'availableAmount'=>round((float)$r['amount']-(float)$r['reserved_amount'],4)],$query->fetchAll(PDO::FETCH_ASSOC)),
                'items'=>array_map(static fn(array $r):array=>['id'=>$r['uid'],'type'=>$r['type'],'name'=>$r['name'],'containerSpace'=>(float)$r['container_space'],'available'=>$r['reserved_transfer_id']===null,'metadata'=>ItemMetadataColumns::metadata($r) ?: new \stdClass()],$items),
                'nextCursor'=>$more?base64_encode(json_encode(['storage'=>$id,'version'=>(int)$row['storage_version'],'after'=>(int)end($items)['id']],JSON_THROW_ON_ERROR)):null];
        });
    }
}
