<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeItem
{
    public const TYPE_WAYPOINT_BOOKMARK = 'waypoint_bookmark';
    public const TYPE_STEEL_BAR = 'steel_bar';
    public const TYPE_STEEL_PLATE = 'steel_plate';
    public const TYPE_ADDITIONAL_CONTAINER = 'additional_container';
    public const WAYPOINT_BOOKMARK_NAME = 'Waypoint bookmark';
    public const STEEL_BAR_NAME = 'Steel bar';
    public const STEEL_PLATE_NAME = 'Steel plate';
    public const ADDITIONAL_CONTAINER_NAME = 'Additional container';

    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly int $probeId,
        public ?int $storageContainerId,
        public readonly string $type,
        public string $name,
        public float $containerSpace,
        public array $metadata,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}

    public function inventoryItem(?array $container = null): ProbeInventoryItem
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
                'movable' => true,
            ],
            $container,
        );
    }
}
