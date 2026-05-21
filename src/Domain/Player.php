<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

use VonNeumannGame\Sector\SectorCoordinates;

final class Player
{
    public function __construct(
        public readonly int $id,
        public string $username,
        public ?string $displayName,
        public SectorCoordinates $homeSector,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}

    public function publicArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'displayName' => $this->displayName,
        ];
    }
}
