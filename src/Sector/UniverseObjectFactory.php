<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class UniverseObjectFactory
{
    public static function fromArray(array $data): UniverseObject
    {
        $type = UniverseObjectType::from((string) $data['type']);

        return match ($type) {
            UniverseObjectType::Star => Star::fromArray($data),
            UniverseObjectType::Planet => Planet::fromArray($data),
            UniverseObjectType::Asteroid => Asteroid::fromArray($data),
            UniverseObjectType::DustCloud => DustCloud::fromArray($data),
            UniverseObjectType::BlackHole => BlackHole::fromArray($data),
            UniverseObjectType::SolarSystem => SolarSystem::fromArray($data),
            UniverseObjectType::Manny => SectorManny::fromArray($data),
            UniverseObjectType::DriftingItem => SectorDriftingItem::fromArray($data),
            UniverseObjectType::DetachedContainer => SectorDetachedContainer::fromArray($data),
            UniverseObjectType::DeuteriumRefuelStation => DeuteriumRefuelStation::fromArray($data),
            UniverseObjectType::DormantConstruct => DormantConstruct::fromArray($data),
        };
    }
}
