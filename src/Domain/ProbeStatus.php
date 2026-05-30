<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

enum ProbeStatus: string
{
    case Idle = 'idle';
    case Preparing = 'preparing';
    case Accelerating = 'accelerating';
    case Cruising = 'cruising';
    case Decelerating = 'decelerating';
    case Orbiting = 'orbiting';
    case Disabled = 'disabled';
    case TrappedByBlackHole = 'trapped_by_black_hole';
    case Dead = 'dead';
}
