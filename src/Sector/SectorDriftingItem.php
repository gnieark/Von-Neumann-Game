<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

use VonNeumannGame\Domain\ProbeInventory;

final class SectorDriftingItem extends UniverseObject
{
    public static function objectIdForItemType(string $itemType): string
    {
        return 'drifting-item-' . preg_replace('/[^a-z0-9_]+/', '-', strtolower($itemType));
    }

    public function __construct(
        string $id,
        ?string $name,
        private readonly string $itemType,
        private readonly int $quantity,
        private readonly float $containerSpace,
        private readonly string $capacityUnit = ProbeInventory::CAPACITY_UNIT,
        ?string $description = null,
        array $waypointBookmarks = [],
    ) {
        parent::__construct($id, $name, UniverseObjectType::DriftingItem, 0.0, 0.0, $description, $waypointBookmarks);
    }

    public function getItemType(): string
    {
        return $this->itemType;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getContainerSpace(): float
    {
        return $this->containerSpace;
    }

    public function getCapacityUnit(): string
    {
        return $this->capacityUnit;
    }

    public function withQuantity(int $quantity): self
    {
        return new self(
            $this->getId(),
            $this->getName(),
            $this->itemType,
            max(0, $quantity),
            $this->containerSpace,
            $this->capacityUnit,
            $this->getDescription(),
            $this->getWaypointBookmarks(),
        );
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'itemType' => $this->itemType,
            'quantity' => $this->quantity,
            'containerSpace' => $this->containerSpace,
            'capacityUnit' => $this->capacityUnit,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (string) ($data['itemType'] ?? ''),
            max(0, (int) ($data['quantity'] ?? 0)),
            round(max(0.0, (float) ($data['containerSpace'] ?? 0.0)), 4),
            (string) ($data['capacityUnit'] ?? ProbeInventory::CAPACITY_UNIT),
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
        );
    }
}
