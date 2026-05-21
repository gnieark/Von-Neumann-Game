<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

enum ProbeStatus: string
{
    case Idle = 'idle';
    case Accelerating = 'accelerating';
    case Cruising = 'cruising';
    case Decelerating = 'decelerating';
    case Orbiting = 'orbiting';
    case Disabled = 'disabled';
}
