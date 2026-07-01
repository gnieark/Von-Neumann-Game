<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class DormantConstruct extends UniverseObject
{
    public const ACTIVITY_STATUS = 'dormant';
    public const APPARENT_ORIGIN = 'unknown_non_natural';
    public const KNOWN_FUNCTION = 'unknown';

    public function __construct(
        string $id,
        ?string $name = 'Dormant construct',
        float $mass = 0.0,
        float $radius = 0.0,
        ?string $description = 'A non-natural structure of unknown origin drifting through space. It appears inactive, and its observed shape does not reveal whether it was a vessel, a factory, or something else.',
        array $waypointBookmarks = [],
    ) {
        parent::__construct($id, $name, UniverseObjectType::DormantConstruct, $mass, $radius, $description, $waypointBookmarks);
    }

    public static function objectIdForSector(SectorCoordinates $coordinates, string $worldSeed): string
    {
        return 'dormant-construct-' . substr(hash('sha256', $worldSeed . '|dormant-construct|' . $coordinates->toKey()), 0, 20);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'apparentOrigin' => self::APPARENT_ORIGIN,
            'activityStatus' => self::ACTIVITY_STATUS,
            'knownFunction' => self::KNOWN_FUNCTION,
        ];
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
        );
    }
}
