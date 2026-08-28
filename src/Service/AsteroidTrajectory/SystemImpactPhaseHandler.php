<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeDamageWarning;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\AsteroidTrajectoryRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Service\OthersService;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DeterministicRandom;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
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
        private readonly ?OthersRepository $others = null,
        private readonly ?OthersService $othersService = null,
        private readonly ?ProbeDamageWarningRepository $alerts = null,
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
            return $this->finish($trajectory, AsteroidTrajectory::STATUS_MISSED, 'source_missing', 'source_missing', 'unknown', null, [], $now);
        }

        $targetProbe = $trajectory->targetProbeId !== null ? $this->probes->findById($trajectory->targetProbeId) : null;
        $target = $targetProbe ?? ($trajectory->targetObjectId !== null ? $sector->findObjectById($trajectory->targetObjectId) : null);
        $targetOthers = $target === null && $trajectory->targetObjectId !== null ? $this->others?->findShipByPublicId($trajectory->targetObjectId) : null;
        $othersPresent = $targetOthers !== null && $targetOthers['destroyed_at'] === null
            && !in_array((string)$targetOthers['status'], ['transit','removed','destroyed'], true)
            && [(int)$targetOthers['sector_x'],(int)$targetOthers['sector_y'],(int)$targetOthers['sector_z']] === [$trajectory->currentSector->getX(),$trajectory->currentSector->getY(),$trajectory->currentSector->getZ()];
        if (($target === null && !$othersPresent) || ($targetProbe !== null && !$targetProbe->currentSector->equals($trajectory->currentSector))) {
            $this->destroyObject($sector, $source->getId());
            $this->sectors->saveSector($sector);
            $missingKind = $targetProbe !== null ? 'probe' : ($targetOthers !== null ? 'others_ship' : 'unknown');
            $missingTarget = $targetProbe ?? $targetOthers;
            return $this->finish($trajectory, AsteroidTrajectory::STATUS_MISSED, 'target_missing', 'target_missing', $missingKind, $missingTarget, [], $now);
        }
        if ($othersPresent) {
            $this->destroyObject($sector, $source->getId());
            $this->sectors->saveSector($sector);
            $relativistic = (float)$trajectory->targetSpeedC >= 0.05;
            $after = $this->othersService?->damageShip((string)$targetOthers['public_id'], $relativistic ? (int)$targetOthers['integrity'] : 10, 'asteroid-impact:'.$trajectory->uid, $relativistic);
            $applied = max(0, (int) $targetOthers['integrity'] - (int) ($after['integrity'] ?? 0));
            $details = [
                'targetDestroyed' => $after === null || $after['destroyed_at'] !== null,
                'integrityDamagePercent' => round(100 * $applied / max(1, (int) $targetOthers['max_integrity']), 2),
            ];
            return $this->finish(
                $trajectory,
                $relativistic ? AsteroidTrajectory::STATUS_DESTROYED : AsteroidTrajectory::STATUS_COMPLETED,
                $relativistic ? 'destroyed' : 'damaged',
                null,
                'others_ship',
                $targetOthers,
                $details,
                $now,
            );
        }
        $targetMovement = $targetProbe !== null ? $this->movements->findActiveByProbeId($targetProbe->id) : null;
        if (
            $targetMovement !== null
            && $now >= new \DateTimeImmutable($targetMovement->preparationEndsAt)
            && $now < new \DateTimeImmutable($targetMovement->arrivalAt)
        ) {
            $this->destroyObject($sector, $source->getId());
            $this->sectors->saveSector($sector);
            return $this->finish($trajectory, AsteroidTrajectory::STATUS_MISSED, 'moving_probe', 'moving_probe', 'probe', $targetProbe, [], $now);
        }

        $random = new DeterministicRandom('asteroid-impact:' . $trajectory->uid);
        $resolution = $this->damage->resolve($source, $target, (float) $trajectory->targetSpeedC, $random);
        if ($target instanceof Star) {
            $this->destroyObject($sector, $source->getId());
            $this->sectors->saveSector($sector);
            return $this->finish($trajectory, AsteroidTrajectory::STATUS_NO_EFFECT, 'star_unchanged', 'star_unchanged', 'star', null, $resolution, $now);
        }
        if ($targetProbe !== null) {
            $damage = (float) ($resolution['integrityDamagePercent'] ?? 0.0);
            $appliedDamage = $targetProbe->subtractIntegrityPercent($damage);
            if ($targetProbe->status === ProbeStatus::Dead) {
                $this->destroyObject($sector, $source->getId());
            }
            $this->probes->save($targetProbe);
            $this->sectors->saveSector($sector);
            return $this->finish(
                $trajectory,
                $targetProbe->status === ProbeStatus::Dead ? AsteroidTrajectory::STATUS_DESTROYED : AsteroidTrajectory::STATUS_COMPLETED,
                (string) $resolution['outcome'],
                null,
                'probe',
                $targetProbe,
                ['integrityDamagePercent' => $appliedDamage] + $resolution,
                $now,
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

        return $this->finish(
            $trajectory,
            ($resolution['outcome'] ?? '') === 'destroyed' ? AsteroidTrajectory::STATUS_DESTROYED : AsteroidTrajectory::STATUS_COMPLETED,
            (string) ($resolution['outcome'] ?? 'completed'),
            null,
            $target instanceof Asteroid ? 'asteroid' : 'planet',
            null,
            $resolution,
            $now,
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

    /** @param array<string, mixed>|NeumannProbe|null $target */
    private function finish(
        AsteroidTrajectory $trajectory,
        string $status,
        string $result,
        ?string $failure,
        string $targetKind,
        array|NeumannProbe|null $target,
        array $details,
        \DateTimeImmutable $resolvedAt,
    ): AsteroidTrajectory {
        $updated = $this->terminal($trajectory, $status, $result, $failure);
        $sector = $trajectory->currentSector;
        $targetLabel = $this->targetLabel($trajectory, $targetKind, $target);
        $scheduledAt = $resolvedAt->format('c');

        if ($trajectory->launcherProbeId !== null && $this->alerts !== null) {
            $launcher = $this->probes->findById($trajectory->launcherProbeId);
            if ($launcher !== null && $this->probeIsPresentInSector($launcher, $sector)) {
                $this->alerts->createAsteroidImpactAlert(
                    $launcher->id,
                    $sector,
                    'asteroid-result-' . $trajectory->uid,
                    $trajectory->asteroidId,
                    $this->resultMessage($trajectory->asteroidId, $targetLabel, $result, $details),
                    ProbeDamageWarning::PHASE_WEAPON_RESULT,
                    $scheduledAt,
                );
            }
        }

        if (!in_array($result, ['source_missing', 'target_missing', 'moving_probe'], true)) {
            $victimMessage = $this->victimMessage($trajectory->asteroidId, $targetLabel, $result, $details);
            if ($targetKind === 'probe' && $target instanceof NeumannProbe && $this->alerts !== null) {
                $this->alerts->createAsteroidImpactAlert(
                    $target->id,
                    $sector,
                    'asteroid-damage-' . $trajectory->uid,
                    $trajectory->asteroidId,
                    $victimMessage,
                    ProbeDamageWarning::PHASE_WEAPON_DAMAGE,
                    $scheduledAt,
                );
            } elseif ($targetKind === 'others_ship' && is_array($target) && $this->others !== null) {
                $this->others->createAlert(
                    (int) $target['player_id'],
                    (string) $target['public_id'],
                    'asteroid_impact_damage',
                    ProbeDamageWarning::PHASE_WEAPON_DAMAGE,
                    'asteroid-damage-' . $trajectory->uid,
                    $victimMessage,
                );
            }
        }

        return $updated;
    }

    private function probeIsPresentInSector(NeumannProbe $probe, SectorCoordinates $sector): bool
    {
        return $probe->currentSector->equals($sector)
            && !in_array($probe->status, [ProbeStatus::Dead, ProbeStatus::Accelerating, ProbeStatus::Cruising, ProbeStatus::Decelerating], true);
    }

    /** @param array<string, mixed>|NeumannProbe|null $target */
    private function targetLabel(AsteroidTrajectory $trajectory, string $targetKind, array|NeumannProbe|null $target): string
    {
        if ($target instanceof NeumannProbe) {
            return 'probe ' . $target->name . ' (#' . $target->id . ')';
        }
        if ($targetKind === 'others_ship' && is_array($target)) {
            return 'Others ship ' . (string) $target['public_id'];
        }

        return $targetKind . ' ' . ($trajectory->targetObjectId ?? 'unknown');
    }

    /** @param array<string, mixed> $details */
    private function resultMessage(string $asteroidId, string $targetLabel, string $result, array $details): string
    {
        $prefix = 'Motorized asteroid ' . $asteroidId;

        return match ($result) {
            'source_missing' => $prefix . ' could not complete its impact trajectory because the impactor was no longer present.',
            'target_missing' => $prefix . ' missed target ' . $targetLabel . ' because it was no longer present.',
            'moving_probe' => $prefix . ' missed target ' . $targetLabel . ' after the probe left on an intersector movement.',
            'star_unchanged', 'no_effect' => $prefix . ' impacted target ' . $targetLabel . '; no effect on target, impactor destroyed.',
            'destroyed' => $prefix . ' impacted target ' . $targetLabel . '; target destroyed.',
            'damaged' => $prefix . ' impacted target ' . $targetLabel . $this->damageSuffix($details),
            'merged' => $prefix . ' impacted target ' . $targetLabel . '; impactor and target merged.',
            'fragmented' => $prefix . ' impacted target ' . $targetLabel . '; target fragmented' . $this->fragmentSuffix($details) . '.',
            'dislocated' => $prefix . ' impacted target ' . $targetLabel . '; target dislocated' . $this->fragmentSuffix($details) . '.',
            default => $prefix . ' resolved against target ' . $targetLabel . ' with outcome ' . $result . '.',
        };
    }

    /** @param array<string, mixed> $details */
    private function victimMessage(string $asteroidId, string $targetLabel, string $result, array $details): string
    {
        return $this->resultMessage($asteroidId, $targetLabel, $result, $details);
    }

    /** @param array<string, mixed> $details */
    private function damageSuffix(array $details): string
    {
        if (!isset($details['integrityDamagePercent']) || !is_numeric($details['integrityDamagePercent'])) {
            return '; target damaged.';
        }

        $percent = rtrim(rtrim(number_format((float) $details['integrityDamagePercent'], 2, '.', ''), '0'), '.');

        return '; damage: ' . $percent . '% of total integrity.';
    }

    /** @param array<string, mixed> $details */
    private function fragmentSuffix(array $details): string
    {
        $count = (int) ($details['fragmentCount'] ?? 0);

        return $count > 0 ? ' into ' . $count . ' recoverable fragments' : '';
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
