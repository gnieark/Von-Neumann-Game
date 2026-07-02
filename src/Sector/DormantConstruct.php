<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class DormantConstruct extends UniverseObject
{
    public const ACTIVITY_STATUS = 'dormant';
    public const APPARENT_ORIGIN = 'unknown_non_natural';
    public const KNOWN_FUNCTION = 'unknown';
    public const INSPECTION_SCENARIO_DEUTERIUM_COMPRESSION = 'deuterium_compression';
    public const INSPECTION_SCENARIO_REINFORCED_CONTAINER_COUPLINGS = 'reinforced_container_couplings';

    public function __construct(
        string $id,
        ?string $name = 'Dormant construct',
        float $mass = 0.0,
        float $radius = 0.0,
        ?string $description = 'A non-natural structure of unknown origin drifting through space. It appears inactive, and its observed shape does not reveal whether it was a vessel, a factory, or something else.',
        array $waypointBookmarks = [],
        private readonly ?string $inspectionScenario = null,
    ) {
        parent::__construct($id, $name, UniverseObjectType::DormantConstruct, $mass, $radius, $description, $waypointBookmarks);
    }

    /**
     * @return list<string>
     */
    public static function inspectionScenarios(): array
    {
        return [
            self::INSPECTION_SCENARIO_DEUTERIUM_COMPRESSION,
            self::INSPECTION_SCENARIO_REINFORCED_CONTAINER_COUPLINGS,
        ];
    }

    public static function objectIdForSector(SectorCoordinates $coordinates, string $worldSeed): string
    {
        return 'dormant-construct-' . substr(hash('sha256', $worldSeed . '|dormant-construct|' . $coordinates->toKey()), 0, 20);
    }

    public function getInspectionScenario(): ?string
    {
        return self::normalizeInspectionScenario($this->inspectionScenario);
    }

    public function withInspectionScenario(string $scenario): self
    {
        return new self(
            $this->getId(),
            $this->getName(),
            $this->getMass(),
            $this->getRadius(),
            $this->getDescription(),
            $this->getWaypointBookmarks(),
            self::normalizeInspectionScenario($scenario),
        );
    }

    public function toArray(): array
    {
        $data = parent::toArray() + [
            'apparentOrigin' => self::APPARENT_ORIGIN,
            'activityStatus' => self::ACTIVITY_STATUS,
            'knownFunction' => self::KNOWN_FUNCTION,
        ];
        if ($this->getInspectionScenario() !== null) {
            $data['inspectionScenario'] = $this->getInspectionScenario();
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? 'Dormant construct',
            (float) ($data['mass'] ?? 0.0),
            (float) ($data['radius'] ?? 0.0),
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
            self::normalizeInspectionScenario($data['inspectionScenario'] ?? null),
        );
    }

    private static function normalizeInspectionScenario(mixed $scenario): ?string
    {
        if (!is_string($scenario)) {
            return null;
        }
        $scenario = strtolower(str_replace([' ', '-'], '_', trim($scenario)));

        return in_array($scenario, self::inspectionScenarios(), true) ? $scenario : null;
    }
}
