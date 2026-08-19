<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DeterministicRandom;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\Star;
use VonNeumannGame\Sector\UniverseObject;

final class ImpactDamageResolver
{
    public function __construct(
        private readonly RelativisticEnergyCalculator $energy = new RelativisticEnergyCalculator(),
        private readonly float $probeAutomaticDestructionSpeedC = 0.05,
        private readonly float $probeReferenceMassEarth = 0.00005,
        private readonly float $factorMinimum = 0.9,
        private readonly float $factorMaximum = 1.1,
        private readonly int $fragmentCountMinimum = 3,
        private readonly int $fragmentCountMaximum = 10,
        private readonly float $planetaryLossMinimum = 0.01,
        private readonly float $planetaryLossMaximum = 0.3,
    ) {}

    /** @return array<string, mixed> */
    public function resolve(Asteroid $source, UniverseObject|NeumannProbe $target, float $targetSpeedC, DeterministicRandom $random): array
    {
        if ($target instanceof Star) {
            return ['outcome' => 'no_effect', 'sourceDestroyed' => true, 'targetDestroyed' => false];
        }
        if ($target instanceof NeumannProbe) {
            if ($targetSpeedC >= $this->probeAutomaticDestructionSpeedC) {
                return ['outcome' => 'destroyed', 'sourceDestroyed' => true, 'targetDestroyed' => true, 'integrityDamagePercent' => 100.0];
            }
            $factor = $random->nextFloatBetween($this->factorMinimum, $this->factorMaximum);
            $damage = max(0.0, min(100.0,
                100.0
                * ($source->getMass() / $this->probeReferenceMassEarth)
                * (($targetSpeedC / $this->probeAutomaticDestructionSpeedC) ** 2)
                * $factor,
            ));
            return [
                'outcome' => $damage >= $target->integrityPercent ? 'destroyed' : 'damaged',
                'sourceDestroyed' => false,
                'targetDestroyed' => $damage >= $target->integrityPercent,
                'integrityDamagePercent' => $damage,
                'deterministicFactor' => $factor,
            ];
        }
        if (!$target instanceof Asteroid && !$target instanceof Planet) {
            throw new \InvalidArgumentException('Impact target type is not supported.');
        }

        $impactEnergy = $this->energy->kineticEnergyJoules($source->getMass(), $targetSpeedC);
        $targetMassKg = $target->getMass() * RelativisticEnergyCalculator::EARTH_MASS_KG;
        $targetRadiusMeters = $target->getRadius() * RelativisticEnergyCalculator::EARTH_RADIUS_METERS;
        $ratio = $this->energy->disruptionRatio($impactEnergy, $targetMassKg, $targetRadiusMeters);
        $factor = $random->nextFloatBetween($this->factorMinimum, $this->factorMaximum);
        $effectiveRatio = $ratio * $factor;
        $base = [
            'energyJoules' => $impactEnergy,
            'disruptionRatio' => $ratio,
            'deterministicFactor' => $factor,
            'effectiveRatio' => $effectiveRatio,
        ];
        if ($target instanceof Asteroid && $effectiveRatio < 0.01) {
            return $base + ['outcome' => 'merged', 'sourceDestroyed' => true, 'targetDestroyed' => true, 'fragmentCount' => 1, 'unrecoverableMassFraction' => 0.0];
        }
        if ($target instanceof Planet && $effectiveRatio < 0.01) {
            return $base + ['outcome' => 'damaged', 'sourceDestroyed' => true, 'targetDestroyed' => false, 'intelligentLifeDestroyed' => false, 'fragmentCount' => 0, 'unrecoverableMassFraction' => 0.0];
        }

        $totalDestruction = $effectiveRatio >= 1.0;
        $fragmentCount = $random->nextInt($this->fragmentCountMinimum, $this->fragmentCountMaximum);
        $lossFraction = $target instanceof Planet && !$totalDestruction
            ? $random->nextFloatBetween($this->planetaryLossMinimum, $this->planetaryLossMaximum)
            : (new MaterialDistributionCalculator())->unrecoverableMassFraction($effectiveRatio);
        $outcome = $totalDestruction
            ? 'destroyed'
            : ($effectiveRatio >= 0.1 ? 'dislocated' : 'fragmented');

        return $base + [
            'outcome' => $outcome,
            'sourceDestroyed' => true,
            'targetDestroyed' => $target instanceof Asteroid || $totalDestruction,
            'intelligentLifeDestroyed' => $target instanceof Planet,
            'fragmentCount' => $fragmentCount,
            'unrecoverableMassFraction' => $lossFraction,
            'residualPlanetMassFraction' => $target instanceof Planet && !$totalDestruction ? max(0.1, 1.0 - $effectiveRatio) : null,
        ];
    }
}
