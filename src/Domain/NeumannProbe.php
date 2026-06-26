<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Sector\SectorCoordinates;

final class NeumannProbe
{
    public function __construct(
        public readonly int $id,
        public readonly int $playerId,
        public string $name,
        public SectorCoordinates $currentSector,
        public float $velocityC,
        public float $accelerationCPerDay,
        public ProbeDirection $direction,
        public ProbeStatus $status,
        public float $integrityPercent,
        public float $energyStored,
        public float $deuteriumStock,
        public float $metalsStock,
        public float $iceStock,
        public float $organicCompoundsStock,
        public float $internalClockRate,
        public ?string $currentTask,
        public string $enteredCurrentSectorAt,
        public readonly string $createdAt,
        public string $updatedAt,
        public bool $excludeFromStats,
    ) {}

    public function addIntegrityPercent(float $percentageToAdd, float $maxIntegrityPercent): void
    {
        $this->integrityPercent = round(min($maxIntegrityPercent, $this->integrityPercent + $percentageToAdd), 2);
    }
}
