<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use PDO;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Repository\GerminationDepotRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Service\Storage\SqlInventoryTransferPort;
use VonNeumannGame\Service\Storage\TransferLoadPlanner;

final class SectorStorageTransferService
{
    private readonly StorageTransaction $transaction;
    private readonly GerminationDepotRepository $depots;
    private readonly TransferLoadPlanner $planner;
    private readonly \Closure $clock;

    public function __construct(private readonly PDO $pdo, private readonly GerminationDepotService $construction, ?\Closure $clock = null)
    {
        $this->transaction = new StorageTransaction($pdo);
        $this->depots = new GerminationDepotRepository($pdo);
        $this->planner = new TransferLoadPlanner();
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function startOthers(array $ship, array $auxiliary, string $direction, array $payload): array
    {
        if (!in_array($direction, ['to_storage', 'from_storage'], true)
            || array_diff(array_keys($payload), ['depotId', 'resources', 'itemIds']) !== []
            || !is_string($payload['depotId'] ?? null) || $payload['depotId'] === ''
            || !is_array($payload['resources'] ?? null) || !is_array($payload['itemIds'] ?? null)
            || !array_is_list($payload['itemIds']) || count($payload['itemIds']) > 500) {
            throw new OthersActionException(400, 'bad_request', 'A depotId, resource object and itemIds list are required.');
        }
        $ids = $payload['itemIds'];
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') { throw new OthersActionException(400, 'bad_request', 'Item IDs must be nonempty strings.'); }
        }
        if (count(array_unique($ids, SORT_STRING)) !== count($ids)) { throw new OthersActionException(400, 'bad_request', 'Duplicate item IDs.'); }
        $resources = $payload['resources'];
        try {
            if (array_diff(array_keys($resources), TransferLoadPlanner::RESOURCE_TYPES) !== []) { throw new \InvalidArgumentException('Unknown resource type.'); }
            foreach ($resources as $amount) { TransferLoadPlanner::units($amount); }
            if ($resources === [] && $ids === []) { throw new \InvalidArgumentException('Empty transfer.'); }
        } catch (\InvalidArgumentException $error) { throw new OthersActionException(400, 'bad_request', $error->getMessage()); }
        return $this->transaction->run(function () use ($ship, $auxiliary, $payload, $resources, $ids, $direction): array {
            $carrier = $this->transaction->lock('ship', (int) $ship['id']);
            $actor = $this->transaction->lock('auxiliary', (int) $auxiliary['id']);
            $this->construction->assertActor($carrier, $actor, (int) $ship['id']);
            $depot = $this->depots->find($payload['depotId']);
            if ($depot === null) { throw new OthersActionException(404, 'target_not_found', 'Storage not found.'); }
            $depot = $this->transaction->lock('depot', (int) $depot['id']);
            if ($this->construction->coordinates($depot)->toKey() !== $this->construction->coordinates($carrier)->toKey()) { throw new OthersActionException(422, 'target_out_of_range', 'Storage must be in the carrier sector.'); }
            $shipPort = new SqlInventoryTransferPort($this->pdo, 'ship', (int) $carrier['id']);
            $depotPort = new SqlInventoryTransferPort($this->pdo, 'depot', (int) $depot['id']);
            [$source, $destination] = $direction === 'to_storage' ? [$shipPort, $depotPort] : [$depotPort, $shipPort];
            $items = $source->items($ids);
            $now = ($this->clock)();
            try { $plan = $this->planner->plan($resources, $items); $end = $this->planner->endsAt($plan, $now); }
            catch (\InvalidArgumentException $error) { throw new OthersActionException(422, 'invalid_transfer', $error->getMessage()); }
            $projection = ['depotId' => $depot['public_id'], 'resources' => $resources, 'itemIds' => $ids,
                'capacityEce' => 2.0, 'roundTrips' => $plan['tripCount'], 'durationSeconds' => $plan['durationSeconds']];
            $action = $this->construction->createAction($carrier, $actor, $direction === 'to_storage' ? 'depot_deposit' : 'depot_withdrawal', $projection, $now, $end);
            $id = OthersRepository::publicId('storage_transfer');
            $query = $this->pdo->prepare("INSERT INTO sector_storage_transfers(public_id,player_id,actor_kind,actor_public_id,others_ship_id,others_action_id,external_storage_kind,external_storage_id,direction,status,manifest_json,resources_json,items_json,started_at,ends_at,updated_at) VALUES(?,?,'others_auxiliary',?,?,?,'depot',?,?,'queued',?,?,?,?,?,?)");
            $query->execute([$id, $ship['player_id'], $actor['public_id'], $carrier['id'], $action['id'], (string) $depot['id'], $direction,
                json_encode($plan, JSON_THROW_ON_ERROR), json_encode($resources, JSON_THROW_ON_ERROR), json_encode($items, JSON_THROW_ON_ERROR), $now->format('c'), $end->format('c'), $now->format('c')]);
            $transferId = (int) $this->pdo->lastInsertId();
            $source->reserve($transferId, (int) $action['id'], $resources, $items, $now->format('c'));
            $destination->reserveCapacity($transferId, $plan['totalUnits'] / 10000, $now->format('c'));
            return $action;
        });
    }

    public function completeOthers(int $actionId, string $causalTime, ?string $reason = null): void
    {
        $this->transaction->run(function () use ($actionId, $causalTime, $reason): void {
            $query = $this->pdo->prepare('SELECT * FROM sector_storage_transfers WHERE others_action_id=?');
            $query->execute([$actionId]);
            $initial = $query->fetch(PDO::FETCH_ASSOC);
            if (!$initial || $initial['status'] !== 'queued') { return; }
            $ship = $this->transaction->lock('ship', (int) $initial['others_ship_id']);
            $query = $this->pdo->prepare('SELECT id FROM others_auxiliaries WHERE public_id=?');
            $query->execute([$initial['actor_public_id']]);
            $actorId = $query->fetchColumn();
            $actor = $actorId === false ? null : $this->transaction->lock('auxiliary', (int) $actorId);
            $action = $this->transaction->lock('action', $actionId);
            $transfer = $this->transaction->lock('transfer', (int) $initial['id']);
            if ($transfer['status'] !== 'queued') { return; }
            if ($ship === null || $actor === null || $action === null || (int) $actor['current_action_id'] !== $actionId) { throw new \RuntimeException('Transfer actor reservation is missing.'); }
            $this->transaction->lock('depot', (int) $transfer['external_storage_id']);
            $shipPort = new SqlInventoryTransferPort($this->pdo, 'ship', (int) $ship['id']);
            $depotPort = new SqlInventoryTransferPort($this->pdo, 'depot', (int) $transfer['external_storage_id']);
            [$source, $destination] = $transfer['direction'] === 'to_storage' ? [$shipPort, $depotPort] : [$depotPort, $shipPort];
            $resources = json_decode($transfer['resources_json'], true, 512, JSON_THROW_ON_ERROR);
            $items = json_decode($transfer['items_json'], true, 512, JSON_THROW_ON_ERROR);
            $plan = json_decode($transfer['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
            $elapsed = strtotime($causalTime) - strtotime($transfer['started_at']);
            if ($elapsed >= $plan['durationSeconds']) { $reason = null; }
            elseif ($reason === null) { return; }
            $deliveredResources = []; $deliveredItems = []; $lostResources = []; $lostItems = [];
            if ($reason === null || ($reason !== 'auxiliary_destroyed' && $transfer['direction'] === 'to_storage')) {
                $deliveredResources = $resources; $deliveredItems = $items;
                $source->debit((int) $transfer['id'], $actionId, $resources, array_column($items, 'id'), $causalTime);
                $destination->credit($resources, $items, $causalTime);
            } elseif ($reason === 'auxiliary_destroyed') {
                $cargo = $this->planner->cargoAt($plan, $elapsed, $transfer['direction']);
                foreach ($cargo['resources'] as $type => $units) { $lostResources[$type] = $units / 10000; }
                $lostItems = $cargo['itemIds'];
                $source->debit((int) $transfer['id'], $actionId, $lostResources, $lostItems, $causalTime);
            }
            $source->release((int) $transfer['id'], $actionId, $causalTime);
            $destination->release((int) $transfer['id'], $actionId, $causalTime);
            $released = [];
            foreach ($resources as $type => $amount) {
                $value = round($amount - ($deliveredResources[$type] ?? 0) - ($lostResources[$type] ?? 0), 4);
                if ($value > 0) { $released[$type] = $value; }
            }
            $result = ['outcome' => $reason ?? 'delivered',
                'delivered' => ['resources' => $deliveredResources, 'itemIds' => array_column($deliveredItems, 'id')],
                'lost' => ['resources' => $lostResources, 'itemIds' => $lostItems],
                'released' => ['resources' => $released, 'itemIds' => array_values(array_diff(array_column($items, 'id'), array_column($deliveredItems, 'id'), $lostItems))]];
            if ($reason === null) { $this->construction->releaseActor($actionId, (int) $actor['id'], $causalTime); }
            elseif ($reason !== 'auxiliary_destroyed') { $result['dormantAuxiliaryId'] = $this->construction->makeDormant($actor, $actionId, $causalTime); }
            $status = $reason === null ? 'succeeded' : ($reason === 'auxiliary_destroyed' ? 'failed' : 'canceled');
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            $this->pdo->prepare('UPDATE sector_storage_transfers SET status=?,version=version+1,result_json=?,updated_at=? WHERE id=?')->execute([$status, $json, $causalTime, $transfer['id']]);
            $this->pdo->prepare('UPDATE others_actions SET status=?,result_json=?,completed_at=?,updated_at=? WHERE id=?')->execute([$status, $json, $causalTime, $causalTime, $actionId]);
        });
    }
}
