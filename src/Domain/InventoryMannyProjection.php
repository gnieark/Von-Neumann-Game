<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class InventoryMannyProjection
{
    public function __construct(
        public readonly string $uid,
        public readonly ?int $storageContainerId,
        public readonly string $name,
        public readonly string $locationType,
        public readonly float $cargoDeuterium,
        public readonly float $cargoMetals,
        public readonly float $cargoIce,
        public readonly float $cargoOrganicCompounds,
    ) {}

    public function isOnProbe(): bool
    {
        return $this->locationType === Manny::LOCATION_PROBE;
    }

    /**
     * @return array<string, float|string>
     */
    public function cargoArray(): array
    {
        return [
            'capacity' => Manny::CARGO_CAPACITY,
            'deuterium' => $this->cargoDeuterium,
            'metals' => $this->cargoMetals,
            'ice' => $this->cargoIce,
            'organicCompounds' => $this->cargoOrganicCompounds,
            'capacityUnit' => ProbeInventory::CAPACITY_UNIT,
        ];
    }
}
