<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

use VonNeumannGame\Repository\DetachedStorageContainerRepository;

final class SectorService
{
    private SectorGrid $grid;
    private array $createdSectorKeys = [];

    public function __construct(
        private readonly SectorFileRepository $repository,
        private readonly SectorContentGenerator $generator,
        private readonly string $worldSeed,
        ?SectorGrid $grid = null,
        private readonly ?DetachedStorageContainerRepository $detachedContainers = null,
        private readonly ?\VonNeumannGame\Repository\GerminationDepotRepository $germinationDepots = null,
        private readonly ?\VonNeumannGame\Repository\Storage\SectorEffectRepository $effects = null,
    ) {
        $this->grid = $grid ?? new SectorGrid();
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
        if ($this->effects !== null) {
            $this->effects->withSectorLock($sector->getCoordinates(), function () use ($sector): void {
                foreach ($this->effects->pendingInSector($sector->getCoordinates()) as $effect) {
                    if (!$sector->hasAppliedEffect($effect['operation_id'])) {
                        throw new SectorStorageException('Sector has newer committed intentions; reload before saving.');
                    }
                }
                $this->saveSectorLocked($sector);
            });
            return;
        }
        $this->saveSectorLocked($sector);
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
        if (!$this->repository->exists($coordinates)) { $this->createSector($coordinates, true); }
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

        $knownNeighbors = $this->loadExistingNeighbors($coordinates);
        $sector = $this->generator->generate($coordinates, $this->worldSeed, $knownNeighbors);
        $this->repository->save($sector);
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
        $this->effects?->project($sector);
        if ($this->germinationDepots !== null) {
            $sector->hydrateGerminationDepots($this->germinationDepots->projections($sector->getCoordinates()));
            foreach ($this->germinationDepots->pendingConsumedObjects($sector->getCoordinates()) as $id) { $sector->removeObjectById($id); }
        }
        $sector->hydrateDetachedContainers(
            $this->detachedContainers?->findBySector($sector->getCoordinates()) ?? [],
        );

        return $sector;
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
