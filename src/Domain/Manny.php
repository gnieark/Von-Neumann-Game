<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Sector\SectorCoordinates;

final class Manny
{
    public const LOCATION_PROBE = 'probe';
    public const LOCATION_SECTOR = 'sector';

    public const TASK_REPAIR = 'repair';
    public const TASK_MINING = 'mining';
    public const TASK_CRAFTING = 'crafting';
    public const TASK_SALVAGE = 'salvage';
    public const TASK_RETURNING = 'returning';
    public const TASK_WAITING_FOR_SPACE = 'waiting_for_space';
    public const CARGO_CAPACITY = 0.05;
    public const CONTAINER_SPACE = 0.05;

    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public ?int $probeId,
        public string $name,
        public string $locationType,
        public ?SectorCoordinates $sector,
        public ?string $currentTask,
        public ?string $taskStartedAt,
        public ?string $taskEndsAt,
        public array $taskPayload,
        public float $cargoDeuterium,
        public float $cargoMetals,
        public float $cargoIce,
        public float $cargoOrganicCompounds,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}

    public function isOnProbe(): bool
    {
        return $this->locationType === self::LOCATION_PROBE;
    }

    public function isInSameSectorAs(NeumannProbe $probe): bool
    {
        return $this->isOnProbe() || ($this->sector !== null && $this->sector->equals($probe->currentSector));
    }

    public function taskProgressPercent(): float
    {
        if ($this->currentTask === null || $this->taskStartedAt === null || $this->taskEndsAt === null) {
            return 0.0;
        }

        $started = (new \DateTimeImmutable($this->taskStartedAt))->getTimestamp();
        $ends = (new \DateTimeImmutable($this->taskEndsAt))->getTimestamp();
        $duration = max(1, $ends - $started);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();

        return round(max(0.0, min(100.0, (($now - $started) / $duration) * 100)), 2);
    }

    public function cargoArray(): array
    {
        return [
            'capacity' => self::CARGO_CAPACITY,
            'deuterium' => $this->cargoDeuterium,
            'metals' => $this->cargoMetals,
            'ice' => $this->cargoIce,
            'organicCompounds' => $this->cargoOrganicCompounds,
            'capacityUnit' => ProbeInventory::CAPACITY_UNIT,
        ];
    }
}
