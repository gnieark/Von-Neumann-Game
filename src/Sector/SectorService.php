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
        if ($this->detachedContainers !== null) {
            foreach ($sector->getDetachedContainerChanges() as $objectId => $container) {
                if ($container instanceof SectorDetachedContainer) {
                    $this->detachedContainers->save($sector->getCoordinates(), $container);
                } else {
                    $this->detachedContainers->delete($objectId);
                }
            }
        }
        $this->repository->save($sector);
        $sector->markDetachedContainerChangesPersisted();
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

        return $sector;
    }

    private function withSqlDetachedContainers(SectorContent $sector): SectorContent
    {
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
