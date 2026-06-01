<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeItem
{
    public const TYPE_WAYPOINT_BOOKMARK = 'waypoint_bookmark';
    public const WAYPOINT_BOOKMARK_NAME = 'Waypoint bookmark';

    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly int $probeId,
        public readonly string $type,
        public string $name,
        public float $containerSpace,
        public array $metadata,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}

    public function inventoryItem(): ProbeInventoryItem
    {
        return new ProbeInventoryItem(
            $this->uid,
            $this->type,
            $this->name,
            $this->containerSpace,
            null,
            0.0,
            null,
            null,
            $this->metadata + [
                'createdAt' => $this->createdAt,
                'updatedAt' => $this->updatedAt,
            ],
        );
    }
}
