<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeDirection
{
    public function __construct(
        public readonly float $x = 0.0,
        public readonly float $y = 0.0,
        public readonly float $z = 0.0,
    ) {}

    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'z' => $this->z];
    }
}
