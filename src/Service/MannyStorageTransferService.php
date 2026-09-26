<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\GerminationDepotRepository;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ItemMetadataColumns;
use VonNeumannGame\Repository\DetachedStorageContainerRepository;
use VonNeumannGame\Repository\StorageContainerRepository;
use VonNeumannGame\Repository\Storage\InventoryTransferRepositoryFactory;
use VonNeumannGame\Repository\Storage\SectorStorageTransferRepository;
use VonNeumannGame\Repository\Storage\StorageLockRepository;
use VonNeumannGame\Repository\Storage\StorageReservationRepository;
use VonNeumannGame\Service\Storage\TransferLoadPlanner;

final class MannyStorageTransferService
{
    public const TASK = 'transferring_sector_storage';
    private readonly TransferLoadPlanner $planner;
    private readonly \Closure $clock;

    public function __construct(
        private readonly StorageTransaction $transaction,
        private readonly StorageLockRepository $locks,
        private readonly SectorStorageTransferRepository $transfers,
        private readonly StorageReservationRepository $reservations,
        private readonly InventoryTransferRepositoryFactory $inventories,
        private readonly GerminationDepotRepository $depots,
        private readonly DetachedStorageContainerRepository $detachedContainers,
        private readonly StorageContainerRepository $containers,
        private readonly MannyRepository $mannies,
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeStorageService $storage,
        private readonly array $config = [],
        ?\Closure $clock = null,
    )
    {
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
        if (array_key_exists('deuterium', $resources)) {
            throw new MannyActionException(400, 'bad_request', 'Deuterium cannot be transferred through this endpoint.');
        }
        try { foreach($resources as $amount){TransferLoadPlanner::units($amount);} }
        catch(\InvalidArgumentException $error){throw new MannyActionException(400,'bad_request',$error->getMessage());}
        return $this->transaction->run(function()use($selectedProbe,$mannyUid,$payload,$resources,$ids,$kind):array{
            $this->locks->lock('probe',$selectedProbe->id);
            $probe=$this->probes->findById($selectedProbe->id) ?? throw new MannyActionException(404,'not_found','Probe not found.');
            if (!in_array($probe->status,[ProbeStatus::Idle,ProbeStatus::Orbiting],true)) { throw new MannyActionException(409,'probe_busy','The probe must be available in its sector.'); }
            $manny=$this->mannies->findByUidForProbe($probe->id,$mannyUid);
            if($manny===null){throw new MannyActionException(404,'manny_not_found','Manny not found.');}
            $this->locks->lock('manny',$manny->id);
            $manny=$this->mannies->findById($manny->id) ?? throw new \RuntimeException('Manny missing.');
            if(!$manny->isOnProbe()||$manny->currentTask!==null){throw new MannyActionException(409,'manny_busy','An idle embarked Manny is required.');}
            $container=$this->containers->findByUidForProbe($probe->id,$payload['containerId']);
            if(!$container){throw new MannyActionException(404,'not_found','Container not found.');}
            $this->locks->lock('container',$container->id);
            $external=$this->external($probe,$payload['objectId']);
            $outgoing=$payload['direction']==='to_storage';
            if($outgoing){$this->storage->assertContentsAvailableForTransfer($probe,$payload['containerId'],$resources,$ids);}
            $source=$outgoing ? $this->inventories->create('container',$container->id) : $external['port'];
            $items=$source->items($ids);
            $types=[...array_keys($resources),...array_column($items,'type')];
            if ($outgoing && $external['kind']==='detached') {
                if(array_intersect($types,$this->detachedContainers->excludedResourceTypes((string) $external['id']))!==[]){throw new MannyActionException(422,'storage_exclusion','The destination excludes this content.');}
            }
            $capacity=$this->storage->transferContainerCapacity($probe,$payload['containerId'],$outgoing?[]:$types);
            $onboard=$this->inventories->create('container',$container->id,(float)$capacity['available'],$probe->id);
            $destination=$outgoing?$external['port']:$onboard;
            $now=($this->clock)();
            $cargo=(float)($this->config['manny']['cargoCapacity']??0.05);
            $seconds=$kind==='resources' ? 2*(int)($this->config['manny']['actions']['miningTravelSeconds']??900) : (int)($this->config['manny']['actions']['salvageSeconds']??300);
            try{$plan=$this->planner->plan($resources,$items,$cargo,$seconds,$kind==='items');$ends=$this->planner->endsAt($plan,$now);}
            catch(\InvalidArgumentException $error){throw new MannyActionException(422,'invalid_transfer',$error->getMessage());}
            $publicId='storage_transfer_'.bin2hex(random_bytes(12));
            $transferId=$this->transfers->createManny($publicId,$probe->playerId,$manny->uid,$probe->id,$manny->id,$external['kind'],$external['id'],$payload['objectId'],$container->id,$container->uid,$payload['direction'],$plan,$resources,$items,$now->format('c'),$ends->format('c'));
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

    public function startDeuteriumFromExternalStorage(NeumannProbe $selectedProbe, string $mannyUid, array $payload): array
    {
        if (array_diff(array_keys($payload), ['objectId', 'amount']) !== []
            || !is_string($payload['objectId'] ?? null) || $payload['objectId'] === '') {
            throw new MannyActionException(400, 'bad_request', 'objectId and a positive amount in ECE are required.');
        }
        try {
            $requestedUnits = TransferLoadPlanner::units($payload['amount'] ?? null);
        } catch (\InvalidArgumentException $error) {
            throw new MannyActionException(400, 'bad_request', $error->getMessage());
        }

        return $this->transaction->run(function () use ($selectedProbe, $mannyUid, $payload, $requestedUnits): array {
            $this->locks->lock('probe', $selectedProbe->id);
            $probe = $this->probes->findById($selectedProbe->id) ?? throw new MannyActionException(404, 'not_found', 'Probe not found.');
            if (!in_array($probe->status, [ProbeStatus::Idle, ProbeStatus::Orbiting], true)) {
                throw new MannyActionException(409, 'probe_busy', 'The probe must be stationary.');
            }
            $manny = $this->mannies->findByUidForProbe($probe->id, $mannyUid)
                ?? throw new MannyActionException(404, 'manny_not_found', 'Manny not found.');
            $this->locks->lock('manny', $manny->id);
            $manny = $this->mannies->findById($manny->id) ?? throw new \RuntimeException('Manny missing.');
            if (!$manny->isOnProbe() || $manny->currentTask !== null) {
                throw new MannyActionException(409, 'manny_busy', 'An idle embarked Manny is required.');
            }
            $external = $this->external($probe, $payload['objectId'], false);
            $acceptedUnits = min($requestedUnits, $this->availableTankUnits($probe));
            if ($acceptedUnits === 0) {
                throw new MannyActionException(409, 'probe_deuterium_full', 'No unreserved tank capacity is available for deuterium.');
            }
            $amount = $acceptedUnits / 10000;
            $resources = ['deuterium' => $amount];
            $now = ($this->clock)();
            // One fixed trip: five minutes outbound, five minutes returning with the fuel.
            $plan = $this->planner->plan($resources, [], $amount, 600);
            $plan['tankTransfer'] = [
                'requestedAmountEce' => $requestedUnits / 10000,
                'acceptedAmountEce' => $amount,
                'tankPoints' => round($amount * ResourceComposition::DEUTERIUM_TANK_POINTS_PER_ECE, 4),
                'clamped' => $acceptedUnits < $requestedUnits,
            ];
            $ends = $this->planner->endsAt($plan, $now);
            $publicId = 'storage_transfer_' . bin2hex(random_bytes(12));
            $transferId = $this->transfers->createManny($publicId, $probe->playerId, $manny->uid, $probe->id, $manny->id,
                $external['kind'], $external['id'], $payload['objectId'], null, null, 'from_storage', $plan, $resources, [], $now->format('c'), $ends->format('c'));
            $external['port']->reserve($transferId, 0, $resources, [], $now->format('c'));
            $this->reservations->reserveProbeTank($transferId, $probe->id, $amount);
            $this->storage->releaseMannyFromStorage($manny);
            $manny->locationType = Manny::LOCATION_SECTOR;
            $manny->sector = $probe->currentSector;
            $manny->currentTask = self::TASK;
            $manny->taskStartedAt = $now->format('c');
            $manny->taskEndsAt = $ends->format('c');
            $manny->taskPayload = ['transferId' => $publicId, 'objectId' => $payload['objectId'],
                'direction' => 'from_storage', 'durationSeconds' => 600, 'tankTransfer' => $plan['tankTransfer']];
            $this->mannies->save($manny);
            return ['transfer' => $this->get($probe, $publicId), 'manny' => $this->mannies->findById($manny->id)];
        });
    }

    /** Caller holds the probe lock. Quantities round down to the raw stock precision (0.0001 ECE). */
    private function availableTankUnits(NeumannProbe $probe, int $ignoredTransferId = 0): int
    {
        $remainingEce = ($this->storage->maxDeuteriumPercent($probe) - $probe->deuteriumStock)
            / ResourceComposition::DEUTERIUM_TANK_POINTS_PER_ECE - $this->reservations->reservedProbeTank($probe->id, $ignoredTransferId);
        return max(0, (int) floor(round($remainingEce * 10000, 6)));
    }

    /** Rechecks discovery before reading any content. Parent row locks serialize partial stock mutations. */
    private function external(NeumannProbe $probe,string $objectId, bool $withCapacity = true):array
    {
        $depot=$this->depots->find($objectId);
        if($depot!==null){
            $row=$this->locks->lock('depot',(int)$depot['id']);
            if($row['state']!=='open'||!$this->depots->hasAccess((int) $row['id'],$probe->id)||!$this->local($probe,$row)){throw new MannyActionException(404,'not_found','Storage not found.');}
            return ['kind'=>'depot','id'=>(int)$row['id'],'row'=>$row,'port'=>$this->inventories->create('depot',(int)$row['id'])];
        }
        $row=$this->locks->lock('detached',$objectId);
        if(!$row||!$this->local($probe,$row)||!in_array($row['mode'],['drifting','hidden_on_asteroid','hidden_on_dormant_construct'],true)){throw new MannyActionException(404,'not_found','Storage not found.');}
        if(\VonNeumannGame\Sector\SectorDetachedContainer::isHiddenMode($row['mode'])){
            if(!$this->detachedContainers->isDiscovered($objectId,$probe->playerId)){throw new MannyActionException(404,'not_found','Storage not found.');}
        }
        if($row['status']!=='available'){throw new MannyActionException(409,'storage_reserved','The container is reserved for recovery.');}
        $free=null;
        if ($withCapacity) {
        $free=round((float)$row['capacity']-$this->detachedContainers->occupiedSpace($objectId),4);
        }
        return ['kind'=>'detached','id'=>$objectId,'row'=>$row,'port'=>$this->inventories->create('detached',$objectId,$free)];
    }

    private function local(NeumannProbe $probe,array $row):bool
    {
        return [(int)$row['sector_x'],(int)$row['sector_y'],(int)$row['sector_z']]===[$probe->currentSector->getX(),$probe->currentSector->getY(),$probe->currentSector->getZ()];
    }

    public function get(NeumannProbe $probe,string $id):array
    {
        $row=$this->transfers->findMannyForProbe($id,$probe->id,$probe->playerId);
        if(!$row){throw new MannyActionException(404,'not_found','Transfer not found.');}
        return $this->present($row);
    }

    private function present(array $row):array
    {
        $plan=json_decode($row['manifest_json'],true,512,JSON_THROW_ON_ERROR);
        return \VonNeumannGame\Service\Storage\StoragePublicData::normalize(['id'=>$row['public_id'],'actor'=>['kind'=>'manny','id'=>$row['actor_public_id']],'objectId'=>$row['object_public_id'],'containerId'=>$row['container_public_id'],
            'direction'=>$row['direction'],'resources'=>json_decode($row['resources_json'],true,512,JSON_THROW_ON_ERROR),'itemIds'=>array_column(json_decode($row['items_json'],true,512,JSON_THROW_ON_ERROR),'id'),
            'status'=>$row['status'],'startedAt'=>$row['started_at'],'endsAt'=>$row['ends_at'],'durationSeconds'=>$plan['durationSeconds'],'tripCount'=>$plan['tripCount'],
            'result'=>$row['result_json']===null?null:json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR),'error'=>$row['error_json']===null?null:json_decode($row['error_json'],true,512,JSON_THROW_ON_ERROR)]
            + (isset($plan['tankTransfer']) ? ['tankTransfer' => $plan['tankTransfer']] : []));
    }

    /** Delivery commits once; returning the actor to storage is handled separately by the Manny handler. */
    public function complete(string $id,string $causalTime,?string $reason=null):array
    {
        return $this->transaction->run(function()use($id,$causalTime,$reason):array{
            $initial=$this->transfers->findByPublicId($id);
            if(!$initial||$initial['actor_kind']!=='manny'){throw new \RuntimeException('Manny transfer missing.');}
            $this->locks->lock('probe',(int)$initial['probe_id']);
            if($initial['manny_id']!==null){$this->locks->lock('manny',(int)$initial['manny_id']);}
            $row=$this->locks->lock('transfer',(int)$initial['id']);
            if($row['status']!=='queued'){return $this->present($row);}
            if ($row['container_id'] !== null) { $this->locks->lock('container', (int) $row['container_id']); }
            $this->locks->lock($row['external_storage_kind'],$row['external_storage_kind']==='depot'?(int)$row['external_storage_id']:$row['external_storage_id']);
            $plan=json_decode($row['manifest_json'],true,512,JSON_THROW_ON_ERROR);$elapsed=strtotime($causalTime)-strtotime($row['started_at']);
            if($elapsed >= $plan['durationSeconds']){$reason=null;}elseif($reason===null){return $this->present($row);}
            $probe=$this->probes->findById((int)$row['probe_id']);
            $tankTransfer = isset($plan['tankTransfer']);
            $onboard = $tankTransfer ? null : $this->inventories->create('container',(int)$row['container_id'],probeId:(int)$row['probe_id']);
            $external=$this->inventories->create($row['external_storage_kind'],$row['external_storage_kind']==='depot'?(int)$row['external_storage_id']:$row['external_storage_id']);
            [$source,$destination]=$row['direction']==='to_storage'?[$onboard,$external]:[$external,$onboard];
            $resources=json_decode($row['resources_json'],true,512,JSON_THROW_ON_ERROR);$items=json_decode($row['items_json'],true,512,JSON_THROW_ON_ERROR);
            $lost=['resources'=>[],'itemIds'=>[]];$delivered=['resources'=>[],'itemIds'=>[]];
            if($reason===null){
                $deliveredResources = $resources;
                if ($tankTransfer) {
                    if ($probe === null) { throw new \RuntimeException('Destination probe missing.'); }
                    $units = min(TransferLoadPlanner::units($resources['deuterium']), $this->availableTankUnits($probe, (int) $row['id']));
                    $deliveredResources = $units > 0 ? ['deuterium' => $units / 10000] : [];
                    if ($units > 0) {
                        $this->probes->addDeuteriumStock($probe->id, $units / 10000 * ResourceComposition::DEUTERIUM_TANK_POINTS_PER_ECE, $this->storage->maxDeuteriumPercent($probe));
                    }
                }
                $source->debit((int)$row['id'],0,$deliveredResources,array_column($items,'id'),$causalTime);
                $destination?->credit($deliveredResources,$items,$causalTime);
                $delivered=['resources'=>$deliveredResources,'itemIds'=>array_column($items,'id')];
            }elseif($reason==='manny_destroyed'){
                $cargo=$this->planner->cargoAt($plan,$elapsed,$row['direction']);
                foreach($cargo['resources'] as $type=>$units){$lost['resources'][$type]=$units/10000;}$lost['itemIds']=$cargo['itemIds'];
                $source->debit((int)$row['id'],0,$lost['resources'],$lost['itemIds'],$causalTime);
            }
            $source->release((int)$row['id'],0,$causalTime);$destination?->release((int)$row['id'],0,$causalTime);
            if ($tankTransfer) {
                $this->reservations->releaseProbeTank((int) $row['id']);
            }
            $released=['resources'=>[],'itemIds'=>array_values(array_diff(array_column($items,'id'),$delivered['itemIds'],$lost['itemIds']))];
            foreach($resources as $type=>$amount){
                $remaining=round($amount-($delivered['resources'][$type]??0)-($lost['resources'][$type]??0),4);
                if($remaining>0){$released['resources'][$type]=$remaining;}
            }
            $result=['outcome'=>$reason??'delivered','delivered'=>$delivered,'lost'=>$lost,'released'=>$released];
            if ($tankTransfer) {
                $result['deliveredTankPoints'] = round(($delivered['resources']['deuterium'] ?? 0) * ResourceComposition::DEUTERIUM_TANK_POINTS_PER_ECE, 4);
            }
            $status=$reason===null?'succeeded':($reason==='manny_destroyed'?'failed':'canceled');
            $this->transfers->finish((int) $row['id'], $status, $result, $causalTime);
            $row['status']=$status;$row['result_json']=json_encode($result,JSON_THROW_ON_ERROR);
            if($reason!==null && $row['manny_id']!==null){
                $manny=$this->mannies->findById((int)$row['manny_id']);
                if($manny!==null && $manny->currentTask===self::TASK){$manny->currentTask=null;$manny->taskEndsAt=null;$manny->taskStartedAt=null;$manny->taskPayload=['lastTask'=>self::TASK,'transferId'=>$id,'result'=>$status];$this->mannies->save($manny);}
            }
            return $this->present($row);
        });
    }

    public function interruptProbe(int $probeId,string $now,string $reason='carrier_departure'):void { $this->interrupt('probe',$probeId,$now,$reason); }
    public function interruptManny(int $mannyId,string $now):void { $this->interrupt('manny',$mannyId,$now,'manny_destroyed'); }
    public function interruptExternal(string $id,string $now):void { $this->interrupt('object',$id,$now,'target_moved'); }
    private function interrupt(string $root,int|string $id,string $now,string $reason):void
    {
        foreach($this->transfers->activeMannyPublicIds($root,$id) as $uid){$this->complete($uid,$now,$reason);}
    }

    public function inventory(NeumannProbe $probe,string $id,int $limit=100,?string $cursor=null):array
    {
        return $this->transaction->run(function()use($probe,$id,$limit,$cursor):array{
            $this->locks->lock('probe',$probe->id);
            $probe=$this->probes->findById($probe->id) ?? throw new MannyActionException(404,'not_found','Probe not found.');
            $external=$this->external($probe,$id,false);$row=$external['row'];
            if($external['kind']==='depot'){$page=$this->depots->inventory($row,$limit,$cursor);unset($page['depotId']);return ['objectId'=>$id]+$page;}
            if($limit<1||$limit>500){throw new MannyActionException(400,'bad_request','Invalid page size.');}
            $after=0;
            if($cursor!==null){
                try{$data=json_decode(base64_decode($cursor,true)?:'',true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new MannyActionException(400,'bad_request','Invalid cursor.');}
                if(!is_array($data)||array_keys($data)!==['storage','version','after']||$data['storage']!==$id||!is_int($data['version'])||!is_int($data['after'])||$data['after']<0){throw new MannyActionException(400,'bad_request','Invalid cursor.');}
                if($data['version']!==(int)$row['storage_version']){throw new MannyActionException(409,'inventory_changed','Inventory changed; restart pagination.');}$after=$data['after'];
            }
            $inventory=$this->detachedContainers->inventoryRows($id,$after,$limit);$items=$inventory['items'];
            return ['objectId'=>$id,'resources'=>array_map(static fn(array $r):array=>['type'=>$r['resource_type'],'amount'=>(float)$r['amount'],'reservedAmount'=>(float)$r['reserved_amount'],'availableAmount'=>round((float)$r['amount']-(float)$r['reserved_amount'],4)],$inventory['resources']),
                'items'=>array_map(static fn(array $r):array=>['id'=>$r['uid'],'type'=>$r['type'],'name'=>$r['name'],'containerSpace'=>(float)$r['container_space'],'available'=>$r['reserved_transfer_id']===null,'metadata'=>ItemMetadataColumns::metadata($r) ?: new \stdClass()],$items),
                'nextCursor'=>$inventory['more']?base64_encode(json_encode(['storage'=>$id,'version'=>(int)$row['storage_version'],'after'=>(int)end($items)['id']],JSON_THROW_ON_ERROR)):null];
        });
    }
}
