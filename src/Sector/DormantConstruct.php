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
    public const INSPECTION_SCENARIO_DISTRIBUTED_THRUST_ANCHORING = 'distributed_thrust_anchoring';
    public const INSPECTION_SCENARIO_ANATIFORM_ASTEROID_SCULPTING = 'anatiform_asteroid_sculpting';
    public const INSPECTION_SCENARIO_RELATIVISTIC_PATH_CLEARING = 'relativistic_path_clearing';
    public const SUBTYPE_OTHERS_MOTHERSHIP_WRECK = 'others_mothership_wreck';
    public const OTHERS_MOTHERSHIP_WRECK_NAME = 'Destroyed Others mothership';
    public const OTHERS_MOTHERSHIP_WRECK_DESCRIPTION = "The shattered remains of an Others mothership. Most systems are inert, but fragments of its navigation array still respond to close-range analysis.";
    public const OTHERS_MOTHERSHIP_WRECK_MASS_KG = 2_500_000_000.0;
    public const OTHERS_MOTHERSHIP_WRECK_RADIUS_METERS = 400.0;

    public const THRUST_ANCHORED_ASTEROID_NAME = 'Thrust-anchored asteroid';
    public const THRUST_ANCHORED_ASTEROID_DESCRIPTION = 'A deuterium engine has been mounted on this otherwise unremarkable asteroid. The engine is cold and inoperative. What stands out is not the engine itself, but the web of anchor points distributing its thrust through the rock without tearing it apart.';
    public const ANATIFORM_ASTEROID_NAME = 'Anatiform asteroid';
    public const ANATIFORM_ASTEROID_DESCRIPTION = 'An asteroid whose silhouette is uncannily reminiscent of a duck. Its surface appears inactive, but several unnaturally smooth planes suggest deliberate shaping.';

    public function __construct(
        string $id,
        ?string $name = 'Dormant construct',
        float $mass = 0.0,
        float $radius = 0.0,
        ?string $description = 'A non-natural structure of unknown origin drifting through space. It appears inactive, and its observed shape does not reveal whether it was a vessel, a factory, or something else.',
        array $waypointBookmarks = [],
        private readonly ?string $inspectionScenario = null,
        private readonly ?string $subtype = null,
        private readonly array $resourceAmounts = [],
        private readonly bool $identified = false,
        private readonly ?string $originalAuxiliaryId = null,
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
            self::INSPECTION_SCENARIO_DISTRIBUTED_THRUST_ANCHORING,
            self::INSPECTION_SCENARIO_ANATIFORM_ASTEROID_SCULPTING,
            self::INSPECTION_SCENARIO_RELATIVISTIC_PATH_CLEARING,
        ];
    }

    /** @return list<string> */
    public static function generatableInspectionScenarios(): array
    {
        return [
            self::INSPECTION_SCENARIO_DEUTERIUM_COMPRESSION,
            self::INSPECTION_SCENARIO_REINFORCED_CONTAINER_COUPLINGS,
            self::INSPECTION_SCENARIO_DISTRIBUTED_THRUST_ANCHORING,
            self::INSPECTION_SCENARIO_ANATIFORM_ASTEROID_SCULPTING,
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
            $this->subtype,
            $this->resourceAmounts,
            $this->identified,
            $this->originalAuxiliaryId,
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
        if ($this->subtype !== null) {
            $data['subtype'] = $this->subtype;
            $data['resourceAmounts'] = $this->resourceAmounts;
            $data['identified'] = $this->identified;
            if ($this->originalAuxiliaryId !== null) { $data['originalAuxiliaryId'] = $this->originalAuxiliaryId; }
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
            isset($data['subtype']) ? (string) $data['subtype'] : null,
            is_array($data['resourceAmounts'] ?? null) ? $data['resourceAmounts'] : [],
            (bool) ($data['identified'] ?? false),
            isset($data['originalAuxiliaryId']) ? (string) $data['originalAuxiliaryId'] : null,
        );
    }

    public static function fromOthersAuxiliary(string $auxiliaryId, bool $destroyed = false): self
    {
        return new self(
            ($destroyed ? 'others-auxiliary-wreck-' : 'dormant-others-auxiliary-') . $auxiliaryId,
            $destroyed ? 'Destroyed Others auxiliary' : 'Dormant construct',
            description: $destroyed ? 'A destroyed artificial auxiliary drifting through space.' : 'An inactive artificial auxiliary deprived of its carrier.',
            subtype: $destroyed ? 'others_auxiliary_wreck' : 'others_auxiliary',
            resourceAmounts: ['deuterium' => $destroyed ? 0.02 : 0.01, 'metals' => 5.0, 'ice' => 0.0, 'carbon_compounds' => 0.0],
            identified: false,
            originalAuxiliaryId: $auxiliaryId,
        );
    }

    /** @param array<string, float> $resourceAmounts */
    public static function fromOthersMothership(
        string $shipPublicId,
        array $resourceAmounts = [],
        float $massKg = self::OTHERS_MOTHERSHIP_WRECK_MASS_KG,
        float $radiusMeters = self::OTHERS_MOTHERSHIP_WRECK_RADIUS_METERS,
    ): self {
        return new self(
            'others-mothership-wreck-' . substr(hash('sha256', 'others-mothership-wreck|' . $shipPublicId), 0, 20),
            self::OTHERS_MOTHERSHIP_WRECK_NAME,
            $massKg,
            $radiusMeters,
            self::OTHERS_MOTHERSHIP_WRECK_DESCRIPTION,
            inspectionScenario: self::INSPECTION_SCENARIO_RELATIVISTIC_PATH_CLEARING,
            subtype: self::SUBTYPE_OTHERS_MOTHERSHIP_WRECK,
            resourceAmounts: $resourceAmounts,
            identified: false,
            originalAuxiliaryId: null,
        );
    }

    public function getSubtype(): ?string { return $this->subtype; }
    public function getResourceAmounts(): array { return $this->resourceAmounts; }
    public function isIdentified(): bool { return $this->identified; }
    public function getOriginalAuxiliaryId(): ?string { return $this->originalAuxiliaryId; }
    public function withResourceAmounts(array $amounts): self
    {
        return new self($this->getId(), $this->getName(), $this->getMass(), $this->getRadius(), $this->getDescription(), $this->getWaypointBookmarks(), $this->inspectionScenario, $this->subtype, $amounts, $this->identified, $this->originalAuxiliaryId);
    }

    public function withIdentification(bool $identified, ?array $resourceAmounts = null): self
    {
        return new self($this->getId(), $this->getName(), $this->getMass(), $this->getRadius(), $this->getDescription(), $this->getWaypointBookmarks(), $this->inspectionScenario, $this->subtype, $resourceAmounts ?? $this->resourceAmounts, $identified, $this->originalAuxiliaryId);
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
