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
        private array $detachedContainers = [],
        private array $hiddenDetachedContainers = [],
        private array $planetDroppedContainers = [],
        private array $returnToSpaceProgramMaterialDonations = [],
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
        return [...$this->objects, ...$this->detachedContainers];
    }

    /**
     * @return array<SectorDetachedContainer>
     */
    public function getHiddenDetachedContainers(): array
    {
        return $this->hiddenDetachedContainers;
    }

    /**
     * @return array<SectorDetachedContainer>
     */
    public function getPlanetDroppedContainers(): array
    {
        return $this->planetDroppedContainers;
    }

    /**
     * @return array<SectorDetachedContainer>
     */
    public function hiddenDetachedContainersForObject(string $objectId): array
    {
        return array_values(array_filter(
            $this->hiddenDetachedContainers,
            static fn(SectorDetachedContainer $container): bool => $container->getTargetObjectId() === $objectId,
        ));
    }

    /**
     * @return array<SectorDetachedContainer>
     */
    public function planetDroppedContainersForObject(string $objectId): array
    {
        return array_values(array_filter(
            $this->planetDroppedContainers,
            static fn(SectorDetachedContainer $container): bool => $container->getTargetObjectId() === $objectId,
        ));
    }

    public function findObjectById(string $id): ?UniverseObject
    {
        foreach ($this->detachedContainers as $container) {
            if ($container->getId() === $id) {
                return $container;
            }
        }

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
        if ($object instanceof SectorDetachedContainer) {
            $this->detachedContainers[] = $object;
            $this->touch();
            return;
        }

        $this->objects[] = $object;
        $this->updatedAt = $this->updatedAt === '' ? $this->createdAt : $this->updatedAt;
    }

    public function addHiddenDetachedContainer(SectorDetachedContainer $container): void
    {
        $this->hiddenDetachedContainers[] = $container;
        $this->touch();
    }

    public function addPlanetDroppedContainer(SectorDetachedContainer $container): void
    {
        $this->planetDroppedContainers[] = $container;
        $this->touch();
    }

    /**
     * @param array<string, float|int> $requirements
     * @return array<string, mixed>
     */
    public function ensureReturnToSpaceProgramMaterialCounter(string $planetId, ?string $planetName, array $requirements): array
    {
        $requirements = $this->normalizedMaterialAmounts($requirements);
        $existing = $this->returnToSpaceProgramMaterialDonations[$planetId] ?? null;
        if (is_array($existing)) {
            $existing['planetId'] = (string) ($existing['planetId'] ?? $planetId);
            if ($planetName !== null && $planetName !== '') {
                $existing['planetName'] = $planetName;
            }
            $existing['requirements'] = $requirements;
            $existing['totals'] = $this->normalizedMaterialAmounts(is_array($existing['totals'] ?? null) ? $existing['totals'] : []);
            $existing['donations'] = is_array($existing['donations'] ?? null) ? array_values($existing['donations']) : [];
            $existing['createdAt'] = (string) ($existing['createdAt'] ?? gmdate('c'));
            $existing['updatedAt'] = gmdate('c');
        } else {
            $existing = [
                'planetId' => $planetId,
                'planetName' => $planetName,
                'requirements' => $requirements,
                'totals' => [],
                'donations' => [],
                'createdAt' => gmdate('c'),
                'updatedAt' => gmdate('c'),
            ];
        }

        $existing['remaining'] = $this->remainingMaterialAmounts($requirements, is_array($existing['totals'] ?? null) ? $existing['totals'] : []);
        $this->returnToSpaceProgramMaterialDonations[$planetId] = $existing;
        $this->touch();

        return $existing;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function returnToSpaceProgramMaterialCounterForPlanet(string $planetId): ?array
    {
        $counter = $this->returnToSpaceProgramMaterialDonations[$planetId] ?? null;

        return is_array($counter) ? $counter : null;
    }

    /**
     * @param array<string, float|int> $requirements
     * @param array<string, float|int> $resources
     * @return array<string, mixed>
     */
    public function recordReturnToSpaceProgramMaterialDonation(
        string $planetId,
        ?string $planetName,
        array $requirements,
        int $playerId,
        int $probeId,
        string $containerObjectId,
        array $resources,
    ): array {
        $counter = $this->ensureReturnToSpaceProgramMaterialCounter($planetId, $planetName, $requirements);
        $resources = $this->normalizedMaterialAmounts($resources);
        $totals = $this->normalizedMaterialAmounts(is_array($counter['totals'] ?? null) ? $counter['totals'] : []);
        foreach ($resources as $type => $amount) {
            $totals[$type] = round((float) ($totals[$type] ?? 0.0) + $amount, 4);
        }

        $donations = is_array($counter['donations'] ?? null) ? array_values($counter['donations']) : [];
        $donations[] = [
            'playerId' => $playerId,
            'probeId' => $probeId,
            'containerObjectId' => $containerObjectId,
            'resources' => $resources,
            'createdAt' => gmdate('c'),
        ];

        $requirements = $this->normalizedMaterialAmounts($requirements);
        $counter['requirements'] = $requirements;
        $counter['totals'] = $totals;
        $counter['remaining'] = $this->remainingMaterialAmounts($requirements, $totals);
        $counter['donations'] = $donations;
        $counter['updatedAt'] = gmdate('c');
        $this->returnToSpaceProgramMaterialDonations[$planetId] = $counter;
        $this->touch();

        return $counter;
    }

    public function replaceObject(UniverseObject $replacement): bool
    {
        if ($replacement instanceof SectorDetachedContainer) {
            foreach ($this->detachedContainers as $index => $container) {
                if ($container->getId() === $replacement->getId()) {
                    $this->detachedContainers[$index] = $replacement;
                    $this->touch();

                    return true;
                }
            }
        }

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
        foreach ($this->detachedContainers as $index => $container) {
            if ($container->getId() === $id) {
                array_splice($this->detachedContainers, $index, 1);
                $this->touch();

                return true;
            }
        }

        foreach ($this->objects as $index => $object) {
            if ($object->getId() === $id) {
                array_splice($this->objects, $index, 1);
                $this->touch();

                return true;
            }
        }

        return false;
    }

    public function findHiddenDetachedContainerById(string $id): ?SectorDetachedContainer
    {
        foreach ($this->hiddenDetachedContainers as $container) {
            if ($container->getId() === $id) {
                return $container;
            }
        }

        return null;
    }

    public function removeHiddenDetachedContainerById(string $id): ?SectorDetachedContainer
    {
        foreach ($this->hiddenDetachedContainers as $index => $container) {
            if ($container->getId() !== $id) {
                continue;
            }

            array_splice($this->hiddenDetachedContainers, $index, 1);
            $this->touch();

            return $container;
        }

        return null;
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
            'detachedContainers' => array_map(static fn(SectorDetachedContainer $container): array => $container->toArray(), $this->detachedContainers),
            'hiddenDetachedContainers' => array_map(static fn(SectorDetachedContainer $container): array => $container->toArray(), $this->hiddenDetachedContainers),
            'planetDroppedContainers' => array_map(static fn(SectorDetachedContainer $container): array => $container->toArray(), $this->planetDroppedContainers),
            'returnToSpaceProgramMaterialDonations' => $this->returnToSpaceProgramMaterialDonations,
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
            array_map(static fn(array $object): SectorDetachedContainer => SectorDetachedContainer::fromArray($object), $data['detachedContainers'] ?? []),
            array_map(static fn(array $object): SectorDetachedContainer => SectorDetachedContainer::fromArray($object), $data['hiddenDetachedContainers'] ?? []),
            array_map(static fn(array $object): SectorDetachedContainer => SectorDetachedContainer::fromArray($object), $data['planetDroppedContainers'] ?? []),
            is_array($data['returnToSpaceProgramMaterialDonations'] ?? null) ? $data['returnToSpaceProgramMaterialDonations'] : [],
        );
    }

    /**
     * @param array<string, mixed> $amounts
     * @return array<string, float>
     */
    private function normalizedMaterialAmounts(array $amounts): array
    {
        $normalized = [];
        foreach ($amounts as $type => $amount) {
            if (!is_numeric($amount)) {
                continue;
            }
            $amount = round(max(0.0, (float) $amount), 4);
            if ($amount <= 0.0) {
                continue;
            }
            $normalized[(string) $type] = $amount;
        }

        return $normalized;
    }

    /**
     * @param array<string, float|int> $requirements
     * @param array<string, float|int> $totals
     * @return array<string, float>
     */
    private function remainingMaterialAmounts(array $requirements, array $totals): array
    {
        $remaining = [];
        foreach ($requirements as $type => $amount) {
            if (!is_numeric($amount)) {
                continue;
            }
            $remaining[(string) $type] = round(max(0.0, (float) $amount - (float) ($totals[$type] ?? 0.0)), 4);
        }

        return $remaining;
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
