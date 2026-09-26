<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Repository\GerminationDepotRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\DetachedStorageContainerRepository;
use VonNeumannGame\Repository\Storage\StorageActorRepository;
use VonNeumannGame\Repository\Storage\StorageLockRepository;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorService;

final class GerminationDepotService
{
    private readonly \Closure $clock;

    public function __construct(
        private readonly OthersRepository $others,
        private readonly ScheduledEventRepository $events,
        private readonly SectorService $sectors,
        private readonly SectorEffectService $effects,
        private readonly AnomalyBroadcastService $broadcasts,
        private readonly StorageTransaction $transaction,
        private readonly StorageLockRepository $locks,
        private readonly StorageActorRepository $actors,
        private readonly GerminationDepotRepository $depots,
        private readonly DetachedStorageContainerRepository $detachedContainers,
        ?\Closure $clock = null,
        private readonly ?MannyStorageTransferService $mannyStorageTransfers = null,
    ) {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function build(array $ship, array $auxiliary, array $payload): array
    {
        if ($payload !== []) { throw new OthersActionException(400, 'bad_request', 'Construction accepts an empty JSON object.'); }
        return $this->transaction->run(function () use ($ship, $auxiliary): array {
            $locked = $this->locks->lock('ship', (int) $ship['id']);
            $actor = $this->locks->lock('auxiliary', (int) $auxiliary['id']);
            $this->assertActor($locked, $actor, (int) $ship['id']);
            if ($locked['type'] !== 'mothership') { throw new OthersActionException(422, 'invalid_carrier', 'A mothership is required.'); }
            $sector = $this->coordinates($locked);
            if ($this->sectors->getOrCreateSector($sector)->hasBlackHole()) { throw new OthersActionException(422, 'invalid_sector', 'Construction is unavailable in a black-hole sector.'); }
            $now = ($this->clock)();
            if (!$this->actors->reserveConstructionMetals((int) $locked['id'], $now->format('c'))) { throw new OthersActionException(422, 'insufficient_resources', 'Construction requires 2 ECE of available metals.'); }
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
        if (!$this->actors->reserveAuxiliary($ship, $auxiliary, (int) $action['id'], $payload['depotId'] ?? null, $now->format('c'))) { throw new OthersActionException(409, 'others_auxiliary_busy', 'The auxiliary was reserved concurrently.'); }
        $event = $this->events->schedule(SchedulerService::OTHERS_ACTION, 'others_action', (int) $action['id'], $end->format('c'));
        $this->actors->attachEvent((int) $action['id'], $event->id, $now->format('c'));
        return $this->others->findActionByPublicId($action['public_id']) ?? throw new \RuntimeException('Action creation failed.');
    }

    /** Locks in actor order, before locking the action, including on replay. */
    public function completeConstruction(int $actionId, string $causalTime, ?string $interruption = null): void
    {
        $this->transaction->run(function () use ($actionId, $causalTime, $interruption): void {
            $roots = $this->actors->actionRoots($actionId);
            if (!$roots) { return; }
            $ship = $roots['ship_id'] === null ? null : $this->locks->lock('ship', (int) $roots['ship_id']);
            $actor = $roots['auxiliary_id'] === null ? null : $this->locks->lock('auxiliary', (int) $roots['auxiliary_id']);
            $action = $this->locks->lock('action', $actionId);
            if ($action === null || in_array($action['status'], ['succeeded', 'failed', 'canceled'], true)) { return; }
            if ($ship === null || $actor === null || (int) $actor['current_action_id'] !== $actionId) { throw new \RuntimeException('Construction actor reservation is missing.'); }
            $due = strtotime($causalTime) >= strtotime($action['ends_at']);
            if ($interruption === null && !$due) { return; }
            if ($due) { $interruption = null; }
            if (!$this->actors->consumeConstructionMetals((int) $ship['id'], $causalTime)) { throw new \RuntimeException('Construction reservation invariant violated.'); }
            $result = ['outcome' => $interruption ?? 'built'];
            if ($interruption === null) {
                $depot = $this->depots->create($actionId, $this->coordinates($actor), $action['ends_at']);
                $this->others->discoverFleetDepotsInSector((int) $action['fleet_id'], $this->coordinates($actor));
                $result['depotId'] = $depot['public_id'];
                $this->releaseActor($actionId, (int) $actor['id'], $causalTime);
            } elseif ($interruption !== 'auxiliary_destroyed') {
                $result['dormantAuxiliaryId'] = $this->makeDormant($actor, $actionId, $causalTime);
                $result['lost'] = ['resources' => ['metals' => 2.0], 'itemIds' => []];
            } else {
                $result['lost'] = ['resources' => ['metals' => 2.0], 'itemIds' => []];
            }
            $status = $interruption === null ? 'succeeded' : ($interruption === 'auxiliary_destroyed' ? 'failed' : 'canceled');
            $this->actors->finishAction($actionId, $status, $result, $causalTime);
        });
    }

    public function releaseActor(int $actionId, int $actorId, string $now): void
    {
        if (!$this->actors->releaseAuxiliary($actionId, $actorId, $now)) { throw new \RuntimeException('Auxiliary release invariant violated.'); }
    }

    public function makeDormant(array $actor, int $actionId, string $now): string
    {
        $object = DormantConstruct::fromOthersAuxiliary($actor['public_id']);
        $this->effects->enqueue('storage-dormant-' . $actionId, $this->coordinates($actor), 'add_object', $object->getId(), $object->toArray(), $now);
        $this->actors->deleteAuxiliary((int) $actor['id']);
        return $object->getId();
    }

    public function inventoryForOthers(int $playerId, string $id, int $limit, ?string $cursor): array
    {
        return $this->transaction->run(function () use ($playerId, $id, $limit, $cursor): array {
            $depot = $this->depots->find($id);
            if ($depot === null || !$this->depots->hasLocalShip($playerId, $depot)) { throw new OthersActionException(404, 'target_not_found', 'Storage not found.'); }
            $depot = $this->locks->lock('depot', (int) $depot['id']);
            return $this->depots->inventory($depot, $limit, $cursor);
        });
    }

    public function inspect(NeumannProbe $probe, string $id, string $now): array
    {
        return $this->transaction->run(function () use ($probe, $id, $now): array {
            $depot = $this->depots->find($id);
            if ($depot === null || $this->coordinates($depot)->toKey() !== $probe->currentSector->toKey()) { throw new MannyActionException(404, 'target_not_found', 'Storage not found.'); }
            $depot = $this->locks->lock('depot', (int) $depot['id']);
            if ($depot['state'] === 'impacted') {
                $this->depots->open((int) $depot['id'], $now);
                $this->broadcasts->enqueue($depot, $now);
                $depot['state'] = 'open';
            }
            $access = $depot['state'] === 'open' ? $now : null;
            $this->depots->recordInspection((int) $depot['id'], $probe->id, $now, $access);
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
            $this->locks->lock('depot', (int) $depot['id']);
            $this->depots->impact((int) $depot['id']);
        });
    }

    /** Called inside the command transaction, after the observer/actor locks. */
    public function canTarget(int $probeId, string $id, SectorCoordinates $sector): bool
    {
        $depot = $this->depots->find($id);
        if ($depot === null || $this->coordinates($depot)->toKey() !== $sector->toKey()) { return false; }
        $depot = $this->locks->lock('depot', (int) $depot['id']);
        if ($depot['state'] === 'open') { return false; }
        return $this->depots->hasKnowledge((int) $depot['id'], $probeId);
    }

    public function impactWithSourceEffect(string $id, string $operationId, SectorCoordinates $sector, string $sourceId, string $now): void
    {
        if (!$this->transaction->isActive()) { throw new \LogicException('Trajectory impact must join its transaction.'); }
        $this->impact($id);
        foreach($this->detachedContainers->attachedToTarget($sourceId, $sector) as $containerId){
            ($this->mannyStorageTransfers ?? throw new \RuntimeException('Manny transfer service required for source attachments.'))->interruptExternal($containerId,$now);
            $this->detachedContainers->deleteWithoutTransferCheck($containerId);
        }
        $this->effects->enqueue($operationId, $sector, 'consume_object', $sourceId, [], $now);
    }

    public function coordinates(array $row): SectorCoordinates
    {
        return new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);
    }

    public function finishAction(int $actionId, string $status, array $result, string $now): void
    {
        $this->actors->finishAction($actionId, $status, $result, $now);
    }
}
