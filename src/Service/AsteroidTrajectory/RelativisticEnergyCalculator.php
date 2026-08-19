<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

final class RelativisticEnergyCalculator
{
    public const EARTH_MASS_KG = 5.9722e24;
    public const EARTH_RADIUS_METERS = 6371000.0;
    public const SOLAR_MASS_KG = 1.98847e30;
    public const SOLAR_RADIUS_METERS = 6.957e8;
    public const SPEED_OF_LIGHT_METERS_PER_SECOND = 299792458.0;
    public const GRAVITATIONAL_CONSTANT = 6.67430e-11;

    public function kineticEnergyJoules(float $massEarth, float $speedC): float
    {
        if ($massEarth < 0.0 || $speedC < 0.0 || $speedC >= 1.0) {
            throw new \InvalidArgumentException('Relativistic energy inputs are outside their physical domain.');
        }

        $gamma = 1.0 / sqrt(1.0 - ($speedC ** 2));
        return ($gamma - 1.0)
            * ($massEarth * self::EARTH_MASS_KG)
            * (self::SPEED_OF_LIGHT_METERS_PER_SECOND ** 2);
    }

    public function disruptionEnergyJoules(float $massKg, float $radiusMeters): float
    {
        if ($massKg <= 0.0 || $radiusMeters <= 0.0) {
            throw new \InvalidArgumentException('Target mass and radius must be positive.');
        }

        return (3.0 * self::GRAVITATIONAL_CONSTANT * ($massKg ** 2)) / (5.0 * $radiusMeters);
    }

    public function disruptionRatio(float $energyJoules, float $targetMassKg, float $targetRadiusMeters): float
    {
        return max(0.0, $energyJoules) / $this->disruptionEnergyJoules($targetMassKg, $targetRadiusMeters);
    }
}
