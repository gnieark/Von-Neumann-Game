<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

use VonNeumannGame\Repository\DetachedStorageContainerRepository;
use VonNeumannGame\Repository\MineableObjectStockRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\Storage\SectorEffectRepository;
use VonNeumannGame\Service\SectorEffectService;

final class SectorService
{
    private SectorGrid $grid;
    private array $createdSectorKeys = [];
    private readonly MineableObjectStockRepository $stocks;
    private readonly \VonNeumannGame\Repository\MineableObjectReferenceRepository $references;
    private readonly \VonNeumannGame\Repository\AsteroidTrajectoryRepository $trajectories;
    private readonly \VonNeumannGame\Repository\MannyRepository $mannies;
    private readonly SectorEffectRepository $effects;
    private readonly SectorEffectService $outbox;

    public function __construct(
        private readonly SectorFileRepository $repository,
        private readonly SectorContentGenerator $generator,
        private readonly string $worldSeed,
        \PDO $pdo,
        ?SectorGrid $grid = null,
        private readonly ?DetachedStorageContainerRepository $detachedContainers = null,
        private readonly ?\VonNeumannGame\Repository\GerminationDepotRepository $germinationDepots = null,
        ?SectorEffectRepository $effects = null,
        ?\VonNeumannGame\Repository\MannyRepository $mannies = null,
    ) {
        $this->grid = $grid ?? new SectorGrid();
        $this->mannies = $mannies ?? new \VonNeumannGame\Repository\MannyRepository($pdo);
        $this->stocks = new MineableObjectStockRepository($pdo);
        $this->references = new \VonNeumannGame\Repository\MineableObjectReferenceRepository($pdo);
        $this->trajectories = new \VonNeumannGame\Repository\AsteroidTrajectoryRepository($pdo);
        $this->effects = $effects ?? new SectorEffectRepository($pdo);
        $this->outbox = new SectorEffectService($this->effects, new ScheduledEventRepository($pdo), $this);
    }

    public function getOrCreateSector(SectorCoordinates $coordinates): SectorContent
    {
        if ($this->repository->exists($coordinates)) {
            return $this->withSqlDetachedContainers($this->repository->load($coordinates));
        }

        return $this->createSector($coordinates, true);
    }

    public function sectorExists(SectorCoordinates $coordinates): bool
    {
        return $this->repository->exists($coordinates);
    }

    public function saveSector(SectorContent $sector): void
    {
        $this->effects->withSectorLock($sector->getCoordinates(), function () use ($sector): void {
            foreach ($this->effects->pendingInSector($sector->getCoordinates()) as $effect) {
                if (!$sector->hasAppliedEffect($effect['operation_id'])) {
                    throw new SectorStorageException('Sector has newer committed intentions; reload before saving.');
                }
            }
            $this->saveSectorLocked($sector);
        });
    }

    private function saveSectorLocked(SectorContent $sector): void
    {
        $this->saveDetachedContainerChanges($sector);
        $this->repository->save($sector);
        $sector->markDetachedContainerChangesPersisted();
    }

    public function saveDetachedContainerChanges(SectorContent $sector): void
    {
        if ($this->detachedContainers !== null) {
            foreach ($sector->getDetachedContainerChanges() as $objectId => $container) {
                if ($container instanceof SectorDetachedContainer) {
                    $this->detachedContainers->save($sector->getCoordinates(), $container);
                } else {
                    $this->detachedContainers->delete($objectId);
                }
            }
        }
        $sector->markDetachedContainerChangesPersisted();
    }

    public function germinationKnowledge(int $probeId, SectorCoordinates $sector): array
    {
        return $this->germinationDepots === null ? [] : $this->germinationDepots->knowledgeInSector($probeId, $sector);
    }

    public function applySectorEffect(SectorCoordinates $coordinates, string $operationId, string $type, string $objectId, array $payload): void
    {
        if ($type === 'create_sector') {
            $this->repository->publishCreation($coordinates, $operationId, $payload);
            return;
        }
        if (!$this->repository->exists($coordinates)) { throw new SectorStorageException('Publish sector creation before its object effects.'); }
        $this->repository->mutate($coordinates, static function (SectorContent $sector) use ($operationId, $type, $objectId, $payload): void {
            SectorEffect::apply($sector, $operationId, $type, $objectId, $payload);
        });
    }

    public function addDriftingItem(SectorCoordinates $coordinates, string $operationId, string $itemType, string $name, float $containerSpace): SectorDriftingItem
    {
        $this->getOrCreateSector($coordinates);
        $objectId = SectorDriftingItem::objectIdForItemType($itemType);
        $result = null;
        $this->repository->mutate($coordinates, static function (SectorContent $sector) use ($operationId, $objectId, $itemType, $name, $containerSpace, &$result): void {
            $existing = $sector->findObjectById($objectId);
            if ($sector->hasAppliedEffect($operationId)) {
                if (!$existing instanceof SectorDriftingItem) { throw new \LogicException('Applied drifting item effect has no sector object.'); }
                $result = $existing;
                return;
            }
            if ($existing !== null && !$existing instanceof SectorDriftingItem) { throw new \LogicException('Drifting item identifier is already occupied.'); }
            $result = $existing instanceof SectorDriftingItem
                ? $existing->withQuantity($existing->getQuantity() + 1)
                : new SectorDriftingItem($objectId, $name, $itemType, 1, $containerSpace);
            if ($existing instanceof SectorDriftingItem) { $sector->replaceObject($result); }
            else { $sector->addObject($result); }
            $sector->markEffectApplied($operationId);
        });
        return $result ?? throw new \LogicException('Drifting item was not created.');
    }

    public function reserveDetachedContainer(string $objectId, int $mannyId): bool
    {
        return $this->detachedContainers?->reserve($objectId, $mannyId) ?? false;
    }

    public function releaseDetachedContainerReservation(string $objectId, int $mannyId): bool
    {
        return $this->detachedContainers?->releaseReservation($objectId, $mannyId) ?? false;
    }

    public function reservedDetachedContainer(string $objectId, int $mannyId): ?SectorDetachedContainer
    {
        return $this->detachedContainers?->findReservedByObjectId($objectId, $mannyId);
    }

    public function deleteDetachedContainer(string $objectId): bool
    {
        return $this->detachedContainers?->delete($objectId) ?? false;
    }

    /**
     * @return array<string>
     */
    public function getCreatedSectorKeys(): array
    {
        return $this->createdSectorKeys;
    }

    private function createSector(SectorCoordinates $coordinates, bool $createMissingNeighbors): SectorContent
    {
        if ($this->repository->exists($coordinates)) {
            return $this->withSqlDetachedContainers($this->repository->load($coordinates));
        }

        if ($this->effects->inTransaction()) {
            throw new SectorStorageException('Publish the sector outside the business transaction before accessing it.');
        }
        $effect = $this->prepareCreation($coordinates);
        if ($effect !== null) { $this->outbox->deliverAfterCommit((int) $effect['id']); }
        $sector = $this->repository->load($coordinates);
        $this->createdSectorKeys[] = $coordinates->toKey();

        if ($createMissingNeighbors) {
            foreach ($this->grid->getNeighbors($coordinates) as $neighbor) {
                if (!$this->repository->exists($neighbor)) {
                    $this->createSector($neighbor, false);
                }
            }
        }

        return $this->withSqlDetachedContainers($sector);
    }

    private function withSqlDetachedContainers(SectorContent $sector): SectorContent
    {
        $this->effects->project($sector, includeMineables: false);
        if ($this->germinationDepots !== null) {
            $sector->hydrateGerminationDepots($this->germinationDepots->projections($sector->getCoordinates()));
            foreach ($this->germinationDepots->pendingConsumedObjects($sector->getCoordinates()) as $id) { $sector->removeObjectById($id); }
        }
        $sector->hydrateDetachedContainers(
            $this->detachedContainers?->findBySector($sector->getCoordinates()) ?? [],
        );

        $sector->hydrateForgottenMannies($this->mannies->forgottenProjections($sector->getCoordinates()));
        $this->hydrateStocks($sector);
        return $sector;
    }

    public function hydrateStocks(SectorContent $sector): void
    {
        $stocks = $this->stocks->findMany($sector->mineableIds());
        $sector->mapMineables(static function (array $object) use ($stocks): array {
            $object['resourceAmounts'] = $stocks[$object['id']]['amounts'];
            return $object;
        });
    }

    public function mineableStocks(): MineableObjectStockRepository { return $this->stocks; }

    public function replaceMineableIdentity(string $operation, string $oldId, string $candidateId): string
    {
        $newId = $this->stocks->replaceIdentity($operation, $oldId, $candidateId);
        $this->references->replace($oldId, $newId);
        return $newId;
    }

    /** Recheck file presence after waiting for a source transformation or a concurrent debit. */
    public function lockMineableForMining(SectorCoordinates $coordinates, string $objectId): ?array
    {
        $stock = $this->stocks->lockMany([$objectId])[$objectId];
        $object = $this->getOrCreateSector($coordinates)->findObjectById($objectId);
        if ($object === null || !MineableDescriptors::covers($object->toArray())) { return null; }
        if ($object instanceof Asteroid && !$this->trajectories->allowsMiningAt($objectId, $coordinates)) { return null; }
        return $stock;
    }

    /** Structural decisions alone may compose unpublished descriptors in their transaction. */
    public function getSectorForEffects(SectorCoordinates $coordinates): SectorContent
    {
        if (!$this->repository->exists($coordinates)) {
            $effect = $this->prepareCreation($coordinates);
            if ($effect === null) { return $this->getSectorForEffects($coordinates); }
            $sector = SectorContent::fromDescriptor(json_decode($effect['payload_json'], true, 512, JSON_THROW_ON_ERROR));
            $sector->markEffectApplied($effect['operation_id']);
        } else { $sector = $this->repository->load($coordinates); }
        $this->effects->project($sector);
        return $this->withSqlDetachedContainers($sector);
    }

    private function prepareCreation(SectorCoordinates $coordinates): ?array
    {
        return $this->effects->withSectorLock($coordinates, function () use ($coordinates): ?array {
            if ($this->repository->exists($coordinates)) { return null; }
            $operation = 'sector-create-' . hash('sha256', $this->worldSeed . ':' . $coordinates->toKey());
            $existing = $this->effects->findByOperation($operation);
            if ($existing !== null) {
                if ($existing['status'] === 'applied') { throw new SectorStorageException('Published sector file is missing; restore it explicitly.'); }
                return $existing;
            }
            $sector = $this->generator->generate($coordinates, $this->worldSeed, $this->loadExistingNeighbors($coordinates));
            $slot = 0;
            $sector->mapMineables(function (array $object) use ($operation, &$slot): array {
                $object['id'] = $this->stocks->create($operation . ':' . $slot++, $object['id'], $object['resourceAmounts']);
                return $object;
            });
            $id = $this->outbox->enqueue($operation, $coordinates, 'create_sector', '', $sector->toDescriptor(), gmdate('c'));
            $this->deliverWhenCommitted($id);
            return $this->effects->find($id);
        });
    }

    private function deliverWhenCommitted(int $id): void
    {
        $this->effects->afterCommit(function () use ($id): void {
            try { $this->outbox->deliverAfterCommit($id); }
            catch (\Throwable) { /* Durable event retains the failed publication for retry. */ }
        });
    }

    /** Exceptional abandonment joins SQL; only its committed descriptor is published. */
    public function publishAbandonedManny(SectorCoordinates $coordinates, SectorManny $object): void
    {
        if ($object->getState() !== SectorManny::STATE_ABANDONED) { throw new \InvalidArgumentException('Only abandoned Mannies have a persistent descriptor.'); }
        $this->changeObjectsAfterCommit($coordinates, static function (SectorContent $sector) use ($object): void {
            if (!$sector->replaceObject($object)) { $sector->addObject($object); }
        });
    }

    /** Rare structural changes join the caller transaction; no file write before commit. */
    public function changeObjectsAfterCommit(SectorCoordinates $coordinates, callable $change): mixed
    {
        return $this->effects->withSectorLock($coordinates, function () use ($coordinates, $change): mixed {
            $changes = new \VonNeumannGame\Service\OthersSectorService($this->effects, $this->outbox, $this);
            $sector = $changes->getOrCreateSector($coordinates);
            $result = $change($sector);
            $changes->saveSector($sector);
            return $result;
        });
    }

    /** Structural decisions may compose committed intentions; public presence still comes from the file.
     * Source locks are shared with mining and held through the caller's commit. Hydrate from the
     * locking read, not a repeatable-read snapshot taken before a concurrent debit committed.
     */
    public function changeMineablesAfterCommit(SectorCoordinates $coordinates, array $sourceIds, callable $change): mixed
    {
        return $this->changeObjectsAfterCommit($coordinates, function (SectorContent $sector) use ($coordinates, $sourceIds, $change): mixed {
            $stocks = $this->stocks->lockMany($sourceIds);
            if ($this->detachedContainers !== null) {
                $sector->hydrateContainersForTargets($sourceIds, $this->detachedContainers->lockAttachedToTargets($coordinates, $sourceIds));
            }
            $sector->mapMineables(static function (array $object) use ($stocks): array {
                if (isset($stocks[$object['id']])) { $object['resourceAmounts'] = $stocks[$object['id']]['amounts']; }
                return $object;
            });
            return $change($sector);
        });
    }

    /** Explicit creation only; generic sector saves never create or reset SQL stocks. */
    public function createMineable(SectorCoordinates $coordinates, string $operation, Asteroid|DormantConstruct $object): UniverseObject
    {
        if (!MineableDescriptors::covers($object->toArray())) { throw new \InvalidArgumentException('Object is outside the SQL stock scope.'); }
        $result = $this->effects->withSectorLock($coordinates, function () use ($coordinates, $operation, $object): UniverseObject {
            $existing = $this->effects->findByOperation($operation);
            if ($existing !== null) {
                if ($existing['effect_type'] !== 'add_object'
                    || (int) $existing['sector_x'] !== $coordinates->getX()
                    || (int) $existing['sector_y'] !== $coordinates->getY()
                    || (int) $existing['sector_z'] !== $coordinates->getZ()) {
                    throw new \LogicException('Creation operation is already attributed to another publication.');
                }
                return UniverseObject::fromDescriptor(json_decode($existing['payload_json'], true, 512, JSON_THROW_ON_ERROR));
            }
            if (!$this->repository->exists($coordinates)) { $this->prepareCreation($coordinates); }
            $data = $object->toArray();
            $data['id'] = $this->stocks->create($operation, $data['id'], $data['resourceAmounts']);
            $id = $this->outbox->enqueue($operation, $coordinates, 'add_object', $data['id'], MineableDescriptors::descriptor($data), gmdate('c'));
            $this->deliverWhenCommitted($id);
            return UniverseObject::fromArray($data);
        });
        return $result->withResourceAmounts($this->stocks->find($result->getId())['amounts']);
    }

    /**
     * @return array<SectorContent>
     */
    private function loadExistingNeighbors(SectorCoordinates $coordinates): array
    {
        $neighbors = [];
        foreach ($this->grid->getNeighbors($coordinates) as $neighbor) {
            if ($this->repository->exists($neighbor)) {
                $neighbors[] = $this->repository->load($neighbor);
            }
        }

        return $neighbors;
    }
}
