<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

use VonNeumannGame\Domain\ProbeInventory;

final class SectorDetachedContainer extends UniverseObject
{
    public const MODE_DRIFTING = 'drifting';
    public const MODE_HIDDEN_ON_ASTEROID = 'hidden_on_asteroid';
    public const MODE_DROPPED_ON_PLANET = 'dropped_on_planet';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $id,
        ?string $name,
        private readonly string $mode,
        private readonly int $ownerProbeId,
        private readonly int $ownerPlayerId,
        private readonly ?int $originProbeId,
        private readonly ?string $targetObjectId,
        private readonly float $capacity,
        private readonly string $capacityUnit,
        private readonly string $createdAt,
        private readonly array $payload,
        ?string $description = null,
        array $waypointBookmarks = [],
    ) {
        parent::__construct($id, $name, UniverseObjectType::DetachedContainer, 0.0, 0.0, $description, $waypointBookmarks);
    }

    public static function objectIdForContainer(string $containerUid): string
    {
        return 'detached-container-' . preg_replace('/[^a-z0-9_]+/', '-', strtolower($containerUid));
    }

    public static function planetDropObjectIdForContainer(string $containerUid): string
    {
        return 'planet-drop-' . preg_replace('/[^a-z0-9_]+/', '-', strtolower($containerUid));
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getOwnerProbeId(): int
    {
        return $this->ownerProbeId;
    }

    public function getOwnerPlayerId(): int
    {
        return $this->ownerPlayerId;
    }

    public function getOriginProbeId(): ?int
    {
        return $this->originProbeId;
    }

    public function getTargetObjectId(): ?string
    {
        return $this->targetObjectId;
    }

    public function getCapacity(): float
    {
        return $this->capacity;
    }

    public function getCapacityUnit(): string
    {
        return $this->capacityUnit;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'mode' => $this->mode,
            'ownerProbeId' => $this->ownerProbeId,
            'ownerPlayerId' => $this->ownerPlayerId,
            'originProbeId' => $this->originProbeId,
            'targetObjectId' => $this->targetObjectId,
            'capacity' => $this->capacity,
            'capacityUnit' => $this->capacityUnit,
            'createdAt' => $this->createdAt,
            'payload' => $this->payload,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (string) ($data['mode'] ?? self::MODE_DRIFTING),
            max(0, (int) ($data['ownerProbeId'] ?? 0)),
            max(0, (int) ($data['ownerPlayerId'] ?? 0)),
            isset($data['originProbeId']) ? max(0, (int) $data['originProbeId']) : null,
            isset($data['targetObjectId']) ? (string) $data['targetObjectId'] : null,
            round(max(0.0, (float) ($data['capacity'] ?? 0.0)), 4),
            (string) ($data['capacityUnit'] ?? ProbeInventory::CAPACITY_UNIT),
            (string) ($data['createdAt'] ?? gmdate('c')),
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
        );
    }
}
