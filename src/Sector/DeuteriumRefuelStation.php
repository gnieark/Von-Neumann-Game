<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class DeuteriumRefuelStation extends UniverseObject
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $id,
        ?string $name,
        private readonly string $planetId,
        private readonly ?string $planetName,
        private readonly string $createdAt,
        private readonly array $payload = [],
        ?string $description = null,
        array $waypointBookmarks = [],
    ) {
        parent::__construct($id, $name, UniverseObjectType::DeuteriumRefuelStation, 0.0, 0.0, $description, $waypointBookmarks);
    }

    public static function objectIdForPlanet(string $planetId): string
    {
        return 'deuterium-refuel-station-' . preg_replace('/[^a-z0-9_]+/', '-', strtolower($planetId));
    }

    public function getPlanetId(): string
    {
        return $this->planetId;
    }

    public function getPlanetName(): ?string
    {
        return $this->planetName;
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
            'planetId' => $this->planetId,
            'planetName' => $this->planetName,
            'createdAt' => $this->createdAt,
            'payload' => $this->payload,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (string) ($data['planetId'] ?? ''),
            isset($data['planetName']) ? (string) $data['planetName'] : null,
            (string) ($data['createdAt'] ?? gmdate('c')),
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
        );
    }
}
