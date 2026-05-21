<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Sector\SectorCoordinates;

final class VisitedSector
{
    public function __construct(
        public readonly int $id,
        public readonly int $playerId,
        public readonly SectorCoordinates $coordinates,
        public readonly string $firstVisitedAt,
        public string $lastVisitedAt,
        public int $visitCount,
    ) {}
}
