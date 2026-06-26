<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Sector\SectorCoordinates;

final class ScutRelay
{
    public const STATUS_OFF = 'off';
    public const STATUS_ON = 'on';
    public const RADIUS_SECTORS = 10;

    /**
     * @param array<array{x:int,y:int,z:int}> $coveredSectors
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $createdByProbeId,
        public readonly SectorCoordinates $sector,
        public string $status,
        public ?int $networkId,
        public array $coveredSectors,
        public readonly string $createdAt,
        public ?string $activatedAt,
        public string $updatedAt,
    ) {}

    public function isOn(): bool
    {
        return $this->status === self::STATUS_ON;
    }
}
