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

    public function findObjectById(string $id): ?UniverseObject
    {
        foreach ($this->objects as $object) {
            if ($object->getId() === $id) {
                return $object;
            }

            if ($object instanceof SolarSystem) {
                foreach ($object->getStars() as $star) {
                    if ($star->getId() === $id) {
                        return $star;
                    }
                }
                foreach ($object->getOrbitalBodies() as $body) {
                    if ($body->getObject()->getId() === $id) {
                        return $body->getObject();
                    }
                }
            }
        }

        return null;
    }

    public function addObject(UniverseObject $object): void
    {
        $this->objects[] = $object;
        $this->updatedAt = $this->updatedAt === '' ? $this->createdAt : $this->updatedAt;
    }

    public function replaceObject(UniverseObject $replacement): bool
    {
        foreach ($this->objects as $index => $object) {
            if ($object->getId() === $replacement->getId()) {
                $this->objects[$index] = $replacement;
                $this->touch();

                return true;
            }

            if ($object instanceof SolarSystem) {
                $updatedSystem = $this->replaceObjectInSystem($object, $replacement);
                if ($updatedSystem !== null) {
                    $this->objects[$index] = $updatedSystem;
                    $this->touch();

                    return true;
                }
            }
        }

        return false;
    }

    public function removeObjectById(string $id): bool
    {
        foreach ($this->objects as $index => $object) {
            if ($object->getId() === $id) {
                array_splice($this->objects, $index, 1);
                $this->touch();

                return true;
            }
        }

        return false;
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

    private function replaceObjectInSystem(SolarSystem $system, UniverseObject $replacement): ?SolarSystem
    {
        $primaryStar = $system->getPrimaryStar();
        $secondaryStar = $system->getSecondaryStar();
        $updatedBodies = [];
        $replaced = false;

        if ($replacement instanceof Star && $primaryStar->getId() === $replacement->getId()) {
            $primaryStar = $replacement;
            $replaced = true;
        }
        if ($replacement instanceof Star && $secondaryStar !== null && $secondaryStar->getId() === $replacement->getId()) {
            $secondaryStar = $replacement;
            $replaced = true;
        }

        foreach ($system->getOrbitalBodies() as $body) {
            if ($body->getObject()->getId() === $replacement->getId()) {
                $updatedBodies[] = new OrbitingBody($replacement, $body->getOrbit());
                $replaced = true;
                continue;
            }

            $updatedBodies[] = $body;
        }

        if (!$replaced) {
            return null;
        }

        return new SolarSystem(
            $system->getId(),
            $system->getName(),
            $primaryStar,
            $secondaryStar,
            $updatedBodies,
            $system->getMass(),
            $system->getRadius(),
            $system->getDescription(),
            $system->getWaypointBookmarks(),
        );
    }

    private function touch(): void
    {
        $this->updatedAt = gmdate('c');
    }
}
