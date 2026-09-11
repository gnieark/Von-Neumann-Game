<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use PDO;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Repository\GerminationDepotRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorService;

final class GerminationDepotService
{
    private readonly PDO $pdo;
    private readonly StorageTransaction $transaction;
    private readonly GerminationDepotRepository $depots;
    private readonly \Closure $clock;

    public function __construct(
        private readonly OthersRepository $others,
        private readonly ScheduledEventRepository $events,
        private readonly SectorService $sectors,
        private readonly SectorEffectService $effects,
        private readonly AnomalyBroadcastService $broadcasts,
        ?\Closure $clock = null,
        private readonly ?MannyStorageTransferService $mannyStorageTransfers = null,
    ) {
        $this->pdo = $others->pdo();
        $this->transaction = new StorageTransaction($this->pdo);
        $this->depots = new GerminationDepotRepository($this->pdo);
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function build(array $ship, array $auxiliary, array $payload): array
    {
        if ($payload !== []) { throw new OthersActionException(400, 'bad_request', 'Construction accepts an empty JSON object.'); }
        return $this->transaction->run(function () use ($ship, $auxiliary): array {
            $locked = $this->transaction->lock('ship', (int) $ship['id']);
            $actor = $this->transaction->lock('auxiliary', (int) $auxiliary['id']);
            $this->assertActor($locked, $actor, (int) $ship['id']);
            if ($locked['type'] !== 'mothership') { throw new OthersActionException(422, 'invalid_carrier', 'A mothership is required.'); }
            $sector = $this->coordinates($locked);
            if ($this->sectors->getOrCreateSector($sector)->hasBlackHole()) { throw new OthersActionException(422, 'invalid_sector', 'Construction is unavailable in a black-hole sector.'); }
            $now = ($this->clock)();
            $reserve = $this->pdo->prepare("UPDATE others_inventory_resources SET reserved_amount=ROUND(reserved_amount+2,4),updated_at=? WHERE ship_id=? AND resource_type='metals' AND ROUND(amount-reserved_amount,4)>=2");
            $reserve->execute([$now->format('c'), $locked['id']]);
            if ($reserve->rowCount() !== 1) { throw new OthersActionException(422, 'insufficient_resources', 'Construction requires 2 ECE of available metals.'); }
            return $this->createAction($locked, $actor, 'build_germination_depot', [], $now, $now->modify('+1800 seconds'));
        });
    }

    public function assertActor(?array $ship, ?array $auxiliary, int $shipId): void
    {
        if ($ship === null || $auxiliary === null || (int) $auxiliary['ship_id'] !== $shipId) { throw new OthersActionException(404, 'others_auxiliary_not_found', 'Auxiliary not found.'); }
        if ($ship['destroyed_at'] !== null || $ship['current_action_id'] !== null || !in_array($ship['status'], ['inactive', 'available'], true)) { throw new OthersActionException(409, 'others_ship_busy', 'The carrier is unavailable.'); }
        if ($auxiliary['destroyed_at'] !== null || $auxiliary['location_type'] !== 'embarked' || $auxiliary['current_action_id'] !== null || !in_array($auxiliary['status'], ['inactive', 'available'], true)) { throw new OthersActionException(409, 'others_auxiliary_busy', 'The auxiliary is unavailable.'); }
    }

    public function createAction(array $ship, array $auxiliary, string $type, array $payload, \DateTimeImmutable $now, \DateTimeImmutable $end): array
    {
        $action = $this->others->createAction($ship, $type, 'others_auxiliary', $auxiliary['public_id'], $payload, $end->format('c'), auxiliaryId: (int) $auxiliary['id']);
        $update = $this->pdo->prepare("UPDATE others_auxiliaries SET status='busy',location_type='deployed',spatial_state='moving_to_sector_object',sector_x=?,sector_y=?,sector_z=?,current_action_id=?,object_id=?,updated_at=? WHERE id=? AND current_action_id IS NULL AND location_type='embarked' AND destroyed_at IS NULL");
        $update->execute([$ship['sector_x'], $ship['sector_y'], $ship['sector_z'], $action['id'], $payload['depotId'] ?? null, $now->format('c'), $auxiliary['id']]);
        if ($update->rowCount() !== 1) { throw new OthersActionException(409, 'others_auxiliary_busy', 'The auxiliary was reserved concurrently.'); }
        $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $end->format('c'));
        $this->pdo->prepare('UPDATE others_actions SET scheduled_event_id=?,created_at=?,updated_at=? WHERE id=?')->execute([$event->id, $now->format('c'), $now->format('c'), $action['id']]);
        return $this->others->findActionByPublicId($action['public_id']) ?? throw new \RuntimeException('Action creation failed.');
    }

    /** Locks in actor order, before locking the action, including on replay. */
    public function completeConstruction(int $actionId, string $causalTime, ?string $interruption = null): void
    {
        $this->transaction->run(function () use ($actionId, $causalTime, $interruption): void {
            $query = $this->pdo->prepare('SELECT ship_id,auxiliary_id FROM others_actions WHERE id=?');
            $query->execute([$actionId]);
            $roots = $query->fetch(PDO::FETCH_ASSOC);
            if (!$roots) { return; }
            $ship = $roots['ship_id'] === null ? null : $this->transaction->lock('ship', (int) $roots['ship_id']);
            $actor = $roots['auxiliary_id'] === null ? null : $this->transaction->lock('auxiliary', (int) $roots['auxiliary_id']);
            $action = $this->transaction->lock('action', $actionId);
            if ($action === null || in_array($action['status'], ['succeeded', 'failed', 'canceled'], true)) { return; }
            if ($ship === null || $actor === null || (int) $actor['current_action_id'] !== $actionId) { throw new \RuntimeException('Construction actor reservation is missing.'); }
            $due = strtotime($causalTime) >= strtotime($action['ends_at']);
            if ($interruption === null && !$due) { return; }
            if ($due) { $interruption = null; }
            $consume = $this->pdo->prepare("UPDATE others_inventory_resources SET amount=ROUND(amount-2,4),reserved_amount=ROUND(reserved_amount-2,4),updated_at=? WHERE ship_id=? AND resource_type='metals' AND amount>=2 AND reserved_amount>=2");
            $consume->execute([$causalTime, $ship['id']]);
            if ($consume->rowCount() !== 1) { throw new \RuntimeException('Construction reservation invariant violated.'); }
            $result = ['outcome' => $interruption ?? 'built'];
            if ($interruption === null) {
                $depot = $this->depots->create($actionId, $this->coordinates($actor), $action['ends_at']);
                $result['depotId'] = $depot['public_id'];
                $this->releaseActor($actionId, (int) $actor['id'], $causalTime);
            } elseif ($interruption !== 'auxiliary_destroyed') {
                $result['dormantAuxiliaryId'] = $this->makeDormant($actor, $actionId, $causalTime);
                $result['lost'] = ['resources' => ['metals' => 2.0], 'itemIds' => []];
            } else {
                $result['lost'] = ['resources' => ['metals' => 2.0], 'itemIds' => []];
            }
            $status = $interruption === null ? 'succeeded' : ($interruption === 'auxiliary_destroyed' ? 'failed' : 'canceled');
            $this->pdo->prepare('UPDATE others_actions SET status=?,result_json=?,completed_at=?,updated_at=? WHERE id=?')->execute([$status, json_encode($result, JSON_THROW_ON_ERROR), $causalTime, $causalTime, $actionId]);
        });
    }

    public function releaseActor(int $actionId, int $actorId, string $now): void
    {
        $query = $this->pdo->prepare("UPDATE others_auxiliaries SET status='inactive',location_type='embarked',spatial_state='drifting',sector_x=NULL,sector_y=NULL,sector_z=NULL,object_id=NULL,current_action_id=NULL,updated_at=? WHERE id=? AND current_action_id=? AND destroyed_at IS NULL");
        $query->execute([$now, $actorId, $actionId]);
        if ($query->rowCount() !== 1) { throw new \RuntimeException('Auxiliary release invariant violated.'); }
    }

    public function makeDormant(array $actor, int $actionId, string $now): string
    {
        $object = DormantConstruct::fromOthersAuxiliary($actor['public_id']);
        $this->effects->enqueue('storage-dormant-' . $actionId, $this->coordinates($actor), 'add_object', $object->getId(), $object->toArray(), $now);
        $this->pdo->prepare('UPDATE others_actions SET auxiliary_id=NULL WHERE auxiliary_id=?')->execute([$actor['id']]);
        $this->pdo->prepare('DELETE FROM others_auxiliaries WHERE id=?')->execute([$actor['id']]);
        return $object->getId();
    }

    public function inventoryForOthers(int $playerId, string $id, int $limit, ?string $cursor): array
    {
        return $this->transaction->run(function () use ($playerId, $id, $limit, $cursor): array {
            $depot = $this->depots->find($id);
            if ($depot === null || !$this->depots->hasLocalShip($playerId, $depot)) { throw new OthersActionException(404, 'target_not_found', 'Storage not found.'); }
            $depot = $this->transaction->lock('depot', (int) $depot['id']);
            return $this->depots->inventory($depot, $limit, $cursor);
        });
    }

    public function inspect(NeumannProbe $probe, string $id, string $now): array
    {
        return $this->transaction->run(function () use ($probe, $id, $now): array {
            $depot = $this->depots->find($id);
            if ($depot === null || $this->coordinates($depot)->toKey() !== $probe->currentSector->toKey()) { throw new MannyActionException(404, 'target_not_found', 'Storage not found.'); }
            $depot = $this->transaction->lock('depot', (int) $depot['id']);
            if ($depot['state'] === 'impacted') {
                $this->pdo->prepare("UPDATE germination_depots SET state='open',opened_at=?,version=version+1 WHERE id=? AND state='impacted'")->execute([$now, $depot['id']]);
                $this->broadcasts->enqueue($depot, $now);
                $depot['state'] = 'open';
            }
            $access = $depot['state'] === 'open' ? $now : null;
            $query = $this->pdo->prepare('SELECT 1 FROM germination_depot_probe_knowledge WHERE depot_id=? AND probe_id=?');
            $query->execute([$depot['id'], $probe->id]);
            if ($query->fetchColumn() === false) {
                $this->pdo->prepare('INSERT INTO germination_depot_probe_knowledge(depot_id,probe_id,inspected_at,access_discovered_at) VALUES(?,?,?,?)')->execute([$depot['id'], $probe->id, $now, $access]);
            } elseif ($access !== null) {
                $this->pdo->prepare('UPDATE germination_depot_probe_knowledge SET access_discovered_at=COALESCE(access_discovered_at,?) WHERE depot_id=? AND probe_id=?')->execute([$now, $depot['id'], $probe->id]);
            }
            return ['message' => $access !== null
                ? 'Votre Manny a trouvé un accès au stockage. Vous pouvez maintenant en consulter et transférer le contenu.'
                : "Votre Manny décrit une enveloppe parfaitement lisse, impossible à percer avec son outillage. Un impact pourrait permettre d'en savoir plus."];
        });
    }

    public function impact(string $id): void
    {
        $this->transaction->run(function () use ($id): void {
            $depot = $this->depots->find($id);
            if ($depot === null) { throw new \RuntimeException('Impact depot missing.'); }
            $this->transaction->lock('depot', (int) $depot['id']);
            $this->pdo->prepare("UPDATE germination_depots SET state='impacted',version=version+1 WHERE id=? AND state='sealed'")->execute([$depot['id']]);
        });
    }

    /** Called inside the command transaction, after the observer/actor locks. */
    public function canTarget(int $probeId, string $id, SectorCoordinates $sector): bool
    {
        $depot = $this->depots->find($id);
        if ($depot === null || $this->coordinates($depot)->toKey() !== $sector->toKey()) { return false; }
        $depot = $this->transaction->lock('depot', (int) $depot['id']);
        if ($depot['state'] === 'open') { return false; }
        $query = $this->pdo->prepare('SELECT 1 FROM germination_depot_probe_knowledge WHERE depot_id=? AND probe_id=?');
        $query->execute([$depot['id'], $probeId]);
        return $query->fetchColumn() !== false;
    }

    public function impactWithSourceEffect(string $id, string $operationId, SectorCoordinates $sector, string $sourceId, string $now): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Trajectory impact must join its transaction.'); }
        $this->impact($id);
        $query=$this->pdo->prepare('SELECT object_id FROM detached_storage_containers WHERE target_object_id=? AND sector_x=? AND sector_y=? AND sector_z=? ORDER BY object_id');
        $query->execute([$sourceId,$sector->getX(),$sector->getY(),$sector->getZ()]);
        foreach($query->fetchAll(PDO::FETCH_COLUMN) as $containerId){
            ($this->mannyStorageTransfers ?? throw new \RuntimeException('Manny transfer service required for source attachments.'))->interruptExternal($containerId,$now);
            $this->pdo->prepare('DELETE FROM detached_storage_containers WHERE object_id=?')->execute([$containerId]);
        }
        $this->effects->enqueue($operationId, $sector, 'consume_object', $sourceId, [], $now);
    }

    public function coordinates(array $row): SectorCoordinates
    {
        return new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);
    }
}
