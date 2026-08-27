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
        public float $internalClockRate,
        public ?string $currentTask,
        public string $enteredCurrentSectorAt,
        public readonly string $createdAt,
        public string $updatedAt,
        public bool $excludeFromStats,
        public readonly string $model = ProbeModel::GENERIC,
    ) {}

    public function addIntegrityPercent(float $percentageToAdd, float $maxIntegrityPercent): void
    {
        $this->integrityPercent = round(min($maxIntegrityPercent, $this->integrityPercent + $percentageToAdd), 2);
    }

    public function subtractIntegrityPercent(float $percentageToSubtract): float
    {
        if ($percentageToSubtract < 0.0) {
            throw new \InvalidArgumentException('Integrity subtraction must be zero or positive.');
        }

        $before = $this->integrityPercent;
        $this->integrityPercent = round(max(0.0, $before - $percentageToSubtract), 2);
        if ($this->integrityPercent === 0.0) {
            $this->status = ProbeStatus::Dead;
        }

        return round($before - $this->integrityPercent, 2);
    }
}
