<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class OrbitDescriptor
{
    public function __construct(
        private readonly float $semiMajorAxisAU,
        private readonly float $eccentricity,
        private readonly float $inclinationDegrees,
        private readonly ?float $orbitalPeriodDays = null,
        private readonly ?float $phaseAtEpochDegrees = null,
    ) {}

    public function toArray(): array
    {
        return [
            'semiMajorAxisAU' => $this->semiMajorAxisAU,
            'eccentricity' => $this->eccentricity,
            'inclinationDegrees' => $this->inclinationDegrees,
            'orbitalPeriodDays' => $this->orbitalPeriodDays,
            'phaseAtEpochDegrees' => $this->phaseAtEpochDegrees,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (float) $data['semiMajorAxisAU'],
            (float) $data['eccentricity'],
            (float) $data['inclinationDegrees'],
            isset($data['orbitalPeriodDays']) ? (float) $data['orbitalPeriodDays'] : null,
            isset($data['phaseAtEpochDegrees']) ? (float) $data['phaseAtEpochDegrees'] : null,
        );
    }
}
