<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class SectorContent
{
    /**
     * @param array<UniverseObject> $objects
     */
    public function __construct(
        private readonly SectorCoordinates $coordinates,
        private array $objects = [],
        private readonly string $createdAt = '',
        private string $updatedAt = '',
        private readonly int $generationVersion = 1,
        private readonly string $source = 'generated',
    ) {}

    public function getCoordinates(): SectorCoordinates
    {
        return $this->coordinates;
    }

    public function hasStar(): bool
    {
        foreach ($this->objects as $object) {
            if ($object instanceof Star || $object instanceof SolarSystem) {
                return true;
            }
        }

        return false;
    }

    public function hasBlackHole(): bool
    {
        foreach ($this->objects as $object) {
            if ($object instanceof BlackHole) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<UniverseObject>
     */
    public function getObjects(): array
    {
        return $this->objects;
    }

    public function addObject(UniverseObject $object): void
    {
        $this->objects[] = $object;
        $this->updatedAt = $this->updatedAt === '' ? $this->createdAt : $this->updatedAt;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function toArray(): array
    {
        return [
            'coordinates' => $this->coordinates->toArray(),
            'objects' => array_map(static fn(UniverseObject $object): array => $object->toArray(), $this->objects),
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'generationVersion' => $this->generationVersion,
            'source' => $this->source,
        ];
    }

    public static function fromArray(array $data, string $source = 'loaded'): self
    {
        $coord = $data['coordinates'];

        return new self(
            new SectorCoordinates((int) $coord['x'], (int) $coord['y'], (int) $coord['z']),
            array_map(static fn(array $object): UniverseObject => UniverseObject::fromArray($object), $data['objects'] ?? []),
            (string) ($data['createdAt'] ?? ''),
            (string) ($data['updatedAt'] ?? ''),
            (int) ($data['generationVersion'] ?? 1),
            $source,
        );
    }
}
