<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

enum UniverseObjectType: string
{
    case Star = 'star';
    case Planet = 'planet';
    case Asteroid = 'asteroid';
    case DustCloud = 'dust_cloud';
    case BlackHole = 'black_hole';
    case SolarSystem = 'solar_system';
    case Manny = 'manny';
    case DriftingItem = 'drifting_item';
    case DetachedContainer = 'detached_container';
}
