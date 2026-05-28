<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Sector\SectorCoordinates;

final class ProbeMovement
{
    public function __construct(
        public readonly int $id,
        public readonly int $probeId,
        public SectorCoordinates $origin,
        public SectorCoordinates $target,
        public int $distance,
        public string $status,
        public string $startedAt,
        public string $preparationEndsAt,
        public string $accelerationEndsAt,
        public string $cruiseEndsAt,
        public string $decelerationEndsAt,
        public string $arrivalAt,
        public float $fuelCostDeuterium,
        public ?string $destructionCheckedAt,
        public ?string $destroyedAt,
        public ?string $destructionReason,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}
}
