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
        };
    }
}
