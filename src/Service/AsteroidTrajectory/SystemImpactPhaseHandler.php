<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\AsteroidTrajectoryRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DeterministicRandom;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\Star;

final class SystemImpactPhaseHandler implements PhaseHandlerInterface
{
    private readonly MaterialDistributionCalculator $distribution;

    public function __construct(
        private readonly AsteroidTrajectoryRepository $trajectories,
        private readonly SectorService $sectors,
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeMovementRepository $movements,
        private readonly ImpactDamageResolver $damage,
    ) {
        $this->distribution = new MaterialDistributionCalculator();
    }

    public function supports(AsteroidTrajectory $trajectory): bool
    {
        return $trajectory->mode === AsteroidTrajectory::MODE_SYSTEM_IMPACT
            && $trajectory->status === AsteroidTrajectory::STATUS_COASTING;
    }

    public function handle(AsteroidTrajectory $trajectory, \DateTimeImmutable $now): AsteroidTrajectory
    {
        $sector = $this->sectors->getOrCreateSector($trajectory->currentSector);
        $source = $sector->findObjectById($trajectory->asteroidId);
        if (!$source instanceof Asteroid) {
            return $this->terminal($trajectory, AsteroidTrajectory::STATUS_MISSED, 'source_missing', 'source_missing');
        }

        $targetProbe = $trajectory->targetProbeId !== null ? $this->probes->findById($trajectory->targetProbeId) : null;
        $target = $targetProbe ?? ($trajectory->targetObjectId !== null ? $sector->findObjectById($trajectory->targetObjectId) : null);
        if ($target === null || ($targetProbe !== null && !$targetProbe->currentSector->equals($trajectory->currentSector))) {
            $this->destroyObject($sector, $source->getId());
            $this->sectors->saveSector($sector);
            return $this->terminal($trajectory, AsteroidTrajectory::STATUS_MISSED, 'target_missing', 'target_missing');
        }
        $targetMovement = $targetProbe !== null ? $this->movements->findActiveByProbeId($targetProbe->id) : null;
        if (
            $targetMovement !== null
            && $now >= new \DateTimeImmutable($targetMovement->preparationEndsAt)
            && $now < new \DateTimeImmutable($targetMovement->arrivalAt)
        ) {
            $this->destroyObject($sector, $source->getId());
            $this->sectors->saveSector($sector);
            return $this->terminal($trajectory, AsteroidTrajectory::STATUS_MISSED, 'moving_probe', 'moving_probe');
        }

        $random = new DeterministicRandom('asteroid-impact:' . $trajectory->uid);
        $resolution = $this->damage->resolve($source, $target, (float) $trajectory->targetSpeedC, $random);
        if ($target instanceof Star) {
            $this->destroyObject($sector, $source->getId());
            $this->sectors->saveSector($sector);
            return $this->terminal($trajectory, AsteroidTrajectory::STATUS_NO_EFFECT, 'star_unchanged', 'star_unchanged');
        }
        if ($targetProbe !== null) {
            $damage = (float) ($resolution['integrityDamagePercent'] ?? 0.0);
            $targetProbe->integrityPercent = round(max(0.0, $targetProbe->integrityPercent - $damage), 2);
            if (($resolution['targetDestroyed'] ?? false) === true) {
                $targetProbe->status = ProbeStatus::Dead;
                $this->destroyObject($sector, $source->getId());
            }
            $this->probes->save($targetProbe);
            $this->sectors->saveSector($sector);
            return $this->terminal(
                $trajectory,
                ($resolution['targetDestroyed'] ?? false) ? AsteroidTrajectory::STATUS_DESTROYED : AsteroidTrajectory::STATUS_COMPLETED,
                (string) $resolution['outcome'],
                null,
            );
        }
        if ($target instanceof Asteroid && ($resolution['outcome'] ?? null) === 'merged') {
            $this->mergeAsteroids($sector, $source, $target, $trajectory->uid);
        } elseif ($target instanceof Asteroid) {
            $this->fragmentAsteroids($sector, $source, $target, $resolution, $trajectory->uid, $random);
        } elseif ($target instanceof Planet) {
            $this->resolvePlanet($sector, $source, $target, $resolution, $trajectory->uid, $random);
        }
        $this->sectors->saveSector($sector);

        return $this->terminal(
            $trajectory,
            ($resolution['outcome'] ?? '') === 'destroyed' ? AsteroidTrajectory::STATUS_DESTROYED : AsteroidTrajectory::STATUS_COMPLETED,
            (string) ($resolution['outcome'] ?? 'completed'),
            null,
        );
    }

    private function mergeAsteroids(SectorContent $sector, Asteroid $source, Asteroid $target, string $seed): void
    {
        $sourceData = $source->toArray();
        $resources = [];
        foreach (array_unique([...array_keys($source->getResourceAmounts()), ...array_keys($target->getResourceAmounts())]) as $type) {
            $resources[$type] = round(($source->getResourceAmounts()[$type] ?? 0.0) + ($target->getResourceAmounts()[$type] ?? 0.0), 4);
        }
        $mergedId = 'ast_merge_' . substr(hash('sha256', $seed), 0, 20);
        $merged = new Asteroid(
            $mergedId,
            Asteroid::generatedName($resources, $seed),
            (string) ($sourceData['composition'] ?? 'impact_merge'),
            array_keys(array_filter($resources, static fn(float $amount): bool => $amount > 0.0)),
            'merged',
            $source->getMass() + $target->getMass(),
            (($source->getRadius() ** 3) + ($target->getRadius() ** 3)) ** (1 / 3),
            'Asteroids fused by a relativistic impact.',
            $resources,
            [...$source->getWaypointBookmarks(), ...$target->getWaypointBookmarks()],
            motorized: true,
            motorFuelStatus: Asteroid::MOTOR_FUEL_EMPTY,
        );
        $sector->removeObjectById($source->getId());
        $sector->removeObjectById($target->getId());
        $sector->retargetContainers([$source->getId(), $target->getId()], $mergedId);
        $sector->addObject($merged);
    }

    private function fragmentAsteroids(SectorContent $sector, Asteroid $source, Asteroid $target, array $resolution, string $seed, DeterministicRandom $random): void
    {
        $count = (int) $resolution['fragmentCount'];
        $recoverableMass = ($source->getMass() + $target->getMass()) * (1.0 - (float) $resolution['unrecoverableMassFraction']);
        $masses = $this->distribution->distribute($recoverableMass, $count, $random, 8);
        $resourceTotals = [];
        foreach ([$source->getResourceAmounts(), $target->getResourceAmounts()] as $amounts) {
            foreach ($amounts as $type => $amount) {
                $resourceTotals[$type] = round(($resourceTotals[$type] ?? 0.0) + $amount, 4);
            }
        }
        $resourceSplits = [];
        foreach ($resourceTotals as $type => $total) {
            $resourceSplits[$type] = $this->distribution->distribute($total * (1.0 - (float) $resolution['unrecoverableMassFraction']), $count, $random);
        }
        $sector->removeObjectById($source->getId());
        $sector->removeObjectById($target->getId());
        $sector->removeContainersForObject($source->getId());
        $sector->removeContainersForObject($target->getId());
        for ($index = 0; $index < $count; $index++) {
            $resources = [];
            foreach ($resourceSplits as $type => $amounts) {
                $resources[$type] = $amounts[$index];
            }
            $sector->addObject(new Asteroid(
                'ast_frag_' . substr(hash('sha256', $seed . ':' . $index), 0, 20), null, 'impact_fragment',
                array_keys(array_filter($resources, static fn(float $amount): bool => $amount > 0.0)), 'fragment',
                $masses[$index], max(0.000001, (($source->getRadius() ** 3 + $target->getRadius() ** 3) * ($masses[$index] / max(1.0e-12, $source->getMass() + $target->getMass()))) ** (1 / 3)),
                'Recoverable impact fragment.', $resources,
            ));
        }
    }

    private function resolvePlanet(SectorContent $sector, Asteroid $source, Planet $target, array $resolution, string $seed, DeterministicRandom $random): void
    {
        $this->destroyObject($sector, $source->getId());
        if (($resolution['outcome'] ?? null) === 'damaged') {
            return;
        }
        $count = (int) $resolution['fragmentCount'];
        $recoverable = $target->getMass() * (1.0 - (float) $resolution['unrecoverableMassFraction']);
        $residualMass = 0.0;
        if (($resolution['targetDestroyed'] ?? false) !== true) {
            $residualMass = min($recoverable, $recoverable * (float) $resolution['residualPlanetMassFraction']);
            $data = $target->toArray();
            $data['mass'] = $residualMass;
            $data['radius'] = $target->getRadius() * (($residualMass / $target->getMass()) ** (1 / 3));
            $data['intelligentLife'] = false;
            $sector->replaceObject(Planet::fromArray($data));
        } else {
            $sector->removeObjectById($target->getId());
            $sector->removeContainersForObject($target->getId());
        }
        $masses = $this->distribution->distribute(max(0.0, $recoverable - $residualMass), $count, $random, 8);
        $hints = is_array($target->toArray()['resourceHints'] ?? null) ? $target->toArray()['resourceHints'] : [];
        foreach ($masses as $index => $mass) {
            $sector->addObject(new Asteroid(
                'ast_frag_' . substr(hash('sha256', $seed . ':planet:' . $index), 0, 20), null, 'planet_fragment',
                $hints, 'fragment', $mass,
                max(0.000001, $target->getRadius() * (($mass / max(1.0e-12, $target->getMass())) ** (1 / 3))),
                'Fragment of a disrupted planet.',
            ));
        }
    }

    private function destroyObject(SectorContent $sector, string $objectId): void
    {
        $sector->removeObjectById($objectId);
        $sector->removeContainersForObject($objectId);
    }

    private function terminal(AsteroidTrajectory $trajectory, string $status, string $result, ?string $failure): AsteroidTrajectory
    {
        return $this->trajectories->transition($trajectory->id, [AsteroidTrajectory::STATUS_COASTING], [
            'status' => $status,
            'result' => $result,
            'failure_reason' => $failure,
        ]);
    }
}
