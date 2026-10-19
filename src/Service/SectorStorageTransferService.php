<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Repository\GerminationDepotRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\Storage\InventoryTransferRepositoryFactory;
use VonNeumannGame\Repository\Storage\SectorStorageTransferRepository;
use VonNeumannGame\Repository\Storage\StorageLockRepository;
use VonNeumannGame\Service\Storage\TransferLoadPlanner;

final class SectorStorageTransferService
{
    private readonly TransferLoadPlanner $planner;
    private readonly \Closure $clock;

    public function __construct(
        private readonly StorageTransaction $transaction,
        private readonly StorageLockRepository $locks,
        private readonly SectorStorageTransferRepository $transfers,
        private readonly InventoryTransferRepositoryFactory $inventories,
        private readonly GerminationDepotRepository $depots,
        private readonly GerminationDepotService $construction,
        ?\Closure $clock = null,
    )
    {
        $this->planner = new TransferLoadPlanner();
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function startOthers(array $ship, array $auxiliary, string $direction, array $payload): array
    {
        return $this->startOthersTransfer($ship, $auxiliary, $direction, $payload);
    }

    public function startCollectiveDeposit(array $ship, array $payload): array
    {
        $count = $payload['auxiliaryCount'] ?? null;
        if (!is_int($count) || $count < 1) { throw new OthersActionException(400, 'bad_request', 'auxiliaryCount must be a positive integer.'); }
        unset($payload['auxiliaryCount']);
        return $this->startOthersTransfer($ship, null, 'to_storage', $payload, $count);
    }

    private function startOthersTransfer(array $ship, ?array $auxiliary, string $direction, array $payload, ?int $count = null): array
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
        return $this->transaction->run(function () use ($ship, $auxiliary, $payload, $resources, $ids, $direction, $count): array {
            $carrier = $this->locks->lock('ship', (int) $ship['id']);
            if ($carrier === null || $carrier['destroyed_at'] !== null || $carrier['current_action_id'] !== null || !in_array($carrier['status'], ['inactive', 'available'], true)) {
                throw new OthersActionException(409, 'others_ship_busy', 'The carrier is unavailable.');
            }
            if ($count !== null) {
                $auxiliaries = $this->construction->depositAuxiliaries($carrier, $count);
                $actor = $carrier;
            } else {
                $actor = $this->locks->lock('auxiliary', (int) $auxiliary['id']);
                $this->construction->assertActor($carrier, $actor, (int) $ship['id']);
            }
            $depot = $this->depots->find($payload['depotId']);
            if ($depot === null) { throw new OthersActionException(404, 'target_not_found', 'Storage not found.'); }
            $depot = $this->locks->lock('depot', (int) $depot['id']);
            if ($this->construction->coordinates($depot)->toKey() !== $this->construction->coordinates($carrier)->toKey()) { throw new OthersActionException(422, 'target_out_of_range', 'Storage must be in the carrier sector.'); }
            $shipPort = $this->inventories->create('ship', (int) $carrier['id']);
            $depotPort = $this->inventories->create('depot', (int) $depot['id']);
            [$source, $destination] = $direction === 'to_storage' ? [$shipPort, $depotPort] : [$depotPort, $shipPort];
            $items = $source->items($ids);
            $now = ($this->clock)();
            try {
                $plan = $this->planner->plan($resources, $items);
                if ($count !== null) {
                    $plan['participants'] = $this->planner->shares($plan, $count);
                    foreach ($auxiliaries as $index => $participant) {
                        $plan['participants'][$index] += ['id' => (int) $participant['id'], 'publicId' => $participant['public_id'], 'destroyed' => false];
                    }
                    $plan['durationSeconds'] = (intdiv($plan['tripCount'], $count) + (int) ($plan['tripCount'] % $count !== 0)) * $plan['tripSeconds'];
                }
                $end = $this->planner->endsAt($plan, $now);
            }
            catch (\InvalidArgumentException $error) { throw new OthersActionException(422, 'invalid_transfer', $error->getMessage()); }
            $projection = ['depotId' => $depot['public_id'], 'resources' => $resources, 'itemIds' => $ids,
                'capacityEce' => 2.0, 'roundTrips' => $plan['tripCount'], 'durationSeconds' => $plan['durationSeconds']];
            if ($count !== null) {
                $projection['auxiliaryCount'] = $count;
                $projection['auxiliaryIds'] = array_column($auxiliaries, 'public_id');
                $action = $this->construction->createCollectiveDeposit($carrier, $auxiliaries, $projection, $now, $end);
            } else {
                $action = $this->construction->createAction($carrier, $actor, $direction === 'to_storage' ? 'depot_deposit' : 'depot_withdrawal', $projection, $now, $end);
            }
            $id = OthersRepository::publicId('storage_transfer');
            $transferId = $this->transfers->createOthers($id, (int) $ship['player_id'], $actor, $carrier, $action, (int) $depot['id'], $direction, $plan, $resources, $items, $now->format('c'), $end->format('c'));
            $source->reserve($transferId, (int) $action['id'], $resources, $items, $now->format('c'));
            $destination->reserveCapacity($transferId, $plan['totalUnits'] / 10000, $now->format('c'));
            return $action;
        });
    }

    public function completeOthers(int $actionId, string $causalTime, ?string $reason = null, ?int $destroyedAuxiliaryId = null): void
    {
        $this->transaction->run(function () use ($actionId, $causalTime, $reason, $destroyedAuxiliaryId): void {
            $initial = $this->transfers->findByActionId($actionId);
            if (!$initial || $initial['status'] !== 'queued') { return; }
            if ($initial['actor_kind'] === 'others_ship') {
                $this->completeCollectiveDeposit($initial, $causalTime, $reason, $destroyedAuxiliaryId);
                return;
            }
            $ship = $this->locks->lock('ship', (int) $initial['others_ship_id']);
            $actorId = $this->transfers->actorId($initial['actor_public_id']);
            $actor = $actorId === null ? null : $this->locks->lock('auxiliary', $actorId);
            $action = $this->locks->lock('action', $actionId);
            $transfer = $this->locks->lock('transfer', (int) $initial['id']);
            if ($transfer['status'] !== 'queued') { return; }
            if ($ship === null || $actor === null || $action === null || (int) $actor['current_action_id'] !== $actionId) { throw new \RuntimeException('Transfer actor reservation is missing.'); }
            $this->locks->lock('depot', (int) $transfer['external_storage_id']);
            $shipPort = $this->inventories->create('ship', (int) $ship['id']);
            $depotPort = $this->inventories->create('depot', (int) $transfer['external_storage_id']);
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
            $this->transfers->finish((int) $transfer['id'], $status, $result, $causalTime);
            $this->construction->finishAction($actionId, $status, $result, $causalTime);
        });
    }

    private function completeCollectiveDeposit(array $initial, string $now, ?string $reason, ?int $destroyedAuxiliaryId): void
    {
        $actionId = (int) $initial['others_action_id'];
        $ship = $this->locks->lock('ship', (int) $initial['others_ship_id']);
        $actors = [];
        foreach (json_decode($initial['manifest_json'], true, 512, JSON_THROW_ON_ERROR)['participants'] as $participant) {
            $actors[$participant['id']] = $this->locks->lock('auxiliary', $participant['id']);
        }
        $action = $this->locks->lock('action', $actionId);
        $transfer = $this->locks->lock('transfer', (int) $initial['id']);
        if ($transfer['status'] !== 'queued') { return; }
        if ($ship === null || $action === null) { throw new \RuntimeException('Collective deposit roots are missing.'); }
        $plan = json_decode($transfer['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        $due = strtotime($now) >= strtotime($transfer['ends_at']);
        if ($due) { $reason = null; }
        elseif ($reason === null) { return; }
        if ($reason === 'auxiliary_destroyed') {
            $found = false;
            foreach ($plan['participants'] as &$participant) {
                if ($participant['id'] !== $destroyedAuxiliaryId) { continue; }
                if ($participant['destroyed']) { return; }
                $participant['destroyed'] = true;
                $found = true;
            }
            unset($participant);
            if (!$found) { throw new \RuntimeException('Destroyed auxiliary is not a deposit participant.'); }
            $this->transfers->updateManifest((int) $transfer['id'], $plan, $now);
            if (in_array(false, array_column($plan['participants'], 'destroyed'), true)) { return; }
        }
        $this->locks->lock('depot', (int) $transfer['external_storage_id']);
        $source = $this->inventories->create('ship', (int) $ship['id']);
        $destination = $this->inventories->create('depot', (int) $transfer['external_storage_id']);
        $resources = json_decode($transfer['resources_json'], true, 512, JSON_THROW_ON_ERROR);
        $items = json_decode($transfer['items_json'], true, 512, JSON_THROW_ON_ERROR);
        $lostResources = []; $lostIds = []; $destroyedIds = []; $dormantIds = [];
        foreach ($plan['participants'] as $participant) {
            if ($participant['destroyed']) {
                foreach ($participant['resources'] as $type => $amount) { $lostResources[$type] = round(($lostResources[$type] ?? 0) + $amount, 4); }
                array_push($lostIds, ...$participant['itemIds']);
                $destroyedIds[] = $participant['publicId'];
                continue;
            }
            $actor = $actors[$participant['id']];
            if ($actor === null || (int) $actor['current_action_id'] !== $actionId || $actor['destroyed_at'] !== null) {
                throw new \RuntimeException('Collective deposit participant reservation is missing.');
            }
            if ($reason === null) { $this->construction->releaseActor($actionId, $participant['id'], $now); }
            else { $dormantIds[] = $this->construction->makeDormant($actor, $actionId, $now); }
        }
        $deliveredResources = [];
        foreach ($resources as $type => $amount) {
            $delivered = round($amount - ($lostResources[$type] ?? 0), 4);
            if ($delivered > 0) { $deliveredResources[$type] = $delivered; }
        }
        $deliveredItems = array_values(array_filter($items, static fn(array $item): bool => !in_array($item['id'], $lostIds, true)));
        $source->debit((int) $transfer['id'], $actionId, $resources, array_column($items, 'id'), $now);
        $destination->credit($deliveredResources, $deliveredItems, $now);
        $source->release((int) $transfer['id'], $actionId, $now);
        $destination->release((int) $transfer['id'], $actionId, $now);
        $result = ['outcome' => $reason ?? 'delivered',
            'delivered' => ['resources' => $deliveredResources, 'itemIds' => array_column($deliveredItems, 'id')],
            'lost' => ['resources' => $lostResources, 'itemIds' => $lostIds],
            'released' => ['resources' => [], 'itemIds' => []],
            'destroyedAuxiliaryIds' => $destroyedIds, 'dormantAuxiliaryIds' => $dormantIds];
        $status = $reason === null ? 'succeeded' : ($reason === 'auxiliary_destroyed' ? 'failed' : 'canceled');
        $this->transfers->finish((int) $transfer['id'], $status, $result, $now);
        $this->construction->finishAction($actionId, $status, $result, $now);
    }
}
