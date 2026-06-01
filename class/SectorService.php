<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class SectorService
{
    private SectorGrid $grid;
    private array $createdSectorKeys = [];

    public function __construct(
        private readonly SectorFileRepository $repository,
        private readonly SectorContentGenerator $generator,
        private readonly string $worldSeed,
        ?SectorGrid $grid = null,
    ) {
        $this->grid = $grid ?? new SectorGrid();
    }

    public function getOrCreateSector(SectorCoordinates $coordinates): SectorContent
    {
        if ($this->repository->exists($coordinates)) {
            return $this->repository->load($coordinates);
        }

        return $this->createSector($coordinates, true);
    }

    public function saveSector(SectorContent $sector): void
    {
        $this->repository->save($sector);
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
            return $this->repository->load($coordinates);
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
