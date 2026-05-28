<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeDirection;
use VonNeumannGame\Domain\ProbeMovement;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;

final class ProbeMovementService
{
    private readonly SectorGrid $grid;

    public function __construct(
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeMovementRepository $movements,
        private readonly VisitedSectorRepository $visitedSectors,
        private readonly MovementDurationCalculator $durations = new MovementDurationCalculator(),
        private readonly DeterministicRiskRoll $riskRoll = new DeterministicRiskRoll(),
        private readonly string $worldSeed = 'default-world',
        ?SectorGrid $grid = null,
    ) {
        $this->grid = $grid ?? new SectorGrid();
    }

    public function startMovement(NeumannProbe $probe, SectorCoordinates $target): ProbeMovement
    {
        $probe = $this->refreshProbeMovementState($probe);
        $this->ensureProbeOperational($probe);

        if ($this->movements->findActiveByProbeId($probe->id) !== null) {
            throw new ProbeMovementException(409, 'probe_already_moving', 'The probe is already moving between sectors.');
        }

        if ($probe->currentSector->equals($target)) {
            throw new ProbeMovementException(400, 'same_destination', 'Destination is identical to the current sector.');
        }

        $distance = $this->grid->getDistance($probe->currentSector, $target);
        if ($distance <= 0) {
            throw new ProbeMovementException(400, 'same_destination', 'Destination is identical to the current sector.');
        }

        if ($probe->deuteriumStock <= 0.0001) {
            throw new ProbeMovementException(422, 'insufficient_fuel', 'Insufficient deuterium for movement.');
        }

        $fuelCost = round($probe->deuteriumStock * 0.02, 4);
        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $movement = $this->movements->create(
            $probe->id,
            $probe->currentSector,
            $target,
            $distance,
            $this->durations->timeline($startedAt, $distance),
            $fuelCost,
        );

        $probe->deuteriumStock = round($probe->deuteriumStock - $fuelCost, 4);
        $probe->status = ProbeStatus::Preparing;
        $probe->velocityC = 0.0;
        $probe->accelerationCPerDay = 0.0;
        $probe->direction = $this->directionBetween($movement->origin, $movement->target);
        $probe->currentTask = 'intersector_movement';
        $this->probes->save($probe);

        return $movement;
    }

    public function refreshProbeMovementState(NeumannProbe $probe): NeumannProbe
    {
        $movement = $this->movements->findActiveByProbeId($probe->id);
        if ($movement === null) {
            return $probe;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($this->isAtOrAfter($now, $movement->arrivalAt)) {
            $movement->status = 'arrived';
            $this->movements->save($movement);

            $probe->currentSector = $movement->target;
            $probe->status = ProbeStatus::Idle;
            $probe->velocityC = 0.0;
            $probe->accelerationCPerDay = 0.0;
            $probe->direction = new ProbeDirection(0.0, 0.0, 0.0);
            $probe->currentTask = null;
            $probe->enteredCurrentSectorAt = $now->format('c');
            $this->probes->save($probe);
            $this->visitedSectors->markVisitedByPlayerId($probe->playerId, $movement->target);

            return $this->probes->findById($probe->id) ?? $probe;
        }

        $phase = $this->phaseAt($movement, $now);
        $this->checkDestructionAtCruiseStart($probe, $movement, $phase, $now);
        if ($movement->status === 'destroyed') {
            return $this->probes->findById($probe->id) ?? $probe;
        }

        $movement->status = $phase;
        $this->movements->save($movement);

        $probe->status = ProbeStatus::from($phase);
        $probe->velocityC = $this->estimatedVelocityC($movement, $now);
        $probe->accelerationCPerDay = $phase === 'accelerating' ? 0.36 : ($phase === 'decelerating' ? -0.36 : 0.0);
        $probe->direction = $this->directionBetween($movement->origin, $movement->target);
        $this->probes->save($probe);

        return $this->probes->findById($probe->id) ?? $probe;
    }

    public function activeMovementForProbe(NeumannProbe $probe): ?ProbeMovement
    {
        return $this->movements->findActiveByProbeId($probe->id);
    }

    public function latestMovementForProbe(NeumannProbe $probe): ?ProbeMovement
    {
        return $this->movements->findLatestByProbeId($probe->id);
    }

    public function phaseFor(?ProbeMovement $movement): string
    {
        if ($movement === null) {
            return 'idle';
        }
        if (in_array($movement->status, ['arrived', 'failed', 'destroyed'], true)) {
            return $movement->status;
        }

        return $this->phaseAt($movement, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public function sensorModeFor(?ProbeMovement $movement, ProbeStatus $probeStatus): string
    {
        if ($probeStatus === ProbeStatus::Dead) {
            return 'blind';
        }

        return match ($this->phaseFor($movement)) {
            'accelerating', 'decelerating' => 'degraded',
            'cruising', 'destroyed' => 'blind',
            default => 'normal',
        };
    }

    public function observableSectorFor(NeumannProbe $probe, ?ProbeMovement $movement): ?SectorCoordinates
    {
        if ($probe->status === ProbeStatus::Dead) {
            return null;
        }

        return match ($this->phaseFor($movement)) {
            'preparing', 'accelerating' => $movement?->origin,
            'cruising', 'destroyed' => null,
            'decelerating' => $movement?->target,
            default => $probe->currentSector,
        };
    }

    public function estimatedVelocityC(?ProbeMovement $movement, ?\DateTimeImmutable $now = null): float
    {
        if ($movement === null || in_array($movement->status, ['arrived', 'failed', 'destroyed'], true)) {
            return 0.0;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $phase = $this->phaseAt($movement, $now);
        $max = min(0.95, 0.42 + ($movement->distance * 0.1));

        if ($phase === 'preparing') {
            return 0.0;
        }
        if ($phase === 'cruising') {
            return round($max, 2);
        }
        if ($phase === 'accelerating') {
            return round($max * $this->progressBetween($now, $movement->preparationEndsAt, $movement->accelerationEndsAt), 2);
        }
        if ($phase === 'decelerating') {
            return round($max * (1 - $this->progressBetween($now, $movement->cruiseEndsAt, $movement->decelerationEndsAt)), 2);
        }

        return 0.0;
    }

    public function secondsRemaining(?ProbeMovement $movement): int
    {
        if ($movement === null || in_array($movement->status, ['arrived', 'failed', 'destroyed'], true)) {
            return 0;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $arrival = new \DateTimeImmutable($movement->arrivalAt);

        return max(0, $arrival->getTimestamp() - $now->getTimestamp());
    }

    public function ensureProbeOperational(NeumannProbe $probe): void
    {
        if ($probe->status === ProbeStatus::Dead) {
            throw new ProbeMovementException(409, 'probe_dead', 'The probe is no longer operational.');
        }
    }

    private function phaseAt(ProbeMovement $movement, \DateTimeImmutable $now): string
    {
        if ($this->isBefore($now, $movement->preparationEndsAt)) {
            return 'preparing';
        }
        if ($this->isBefore($now, $movement->accelerationEndsAt)) {
            return 'accelerating';
        }
        if ($this->isBefore($now, $movement->cruiseEndsAt)) {
            return 'cruising';
        }
        if ($this->isBefore($now, $movement->decelerationEndsAt)) {
            return 'decelerating';
        }

        return 'arrived';
    }

    private function checkDestructionAtCruiseStart(NeumannProbe $probe, ProbeMovement $movement, string $phase, \DateTimeImmutable $now): void
    {
        if ($phase !== 'cruising' || $movement->destructionCheckedAt !== null) {
            return;
        }

        $movement->destructionCheckedAt = $now->format('c');
        $risk = match (true) {
            $movement->distance <= 2 => 0.0,
            $movement->distance === 3 => 0.05,
            $movement->distance === 4 => 0.12,
            $movement->distance === 5 => 0.25,
            default => 0.40,
        };

        if ($risk > 0 && $this->riskRoll->roll($this->worldSeed, $movement) < $risk) {
            $movement->status = 'destroyed';
            $movement->destroyedAt = $now->format('c');
            $movement->destructionReason = 'High velocity collision with undetected celestial object';
            $this->movements->save($movement);

            $probe->status = ProbeStatus::Dead;
            $probe->velocityC = 0.0;
            $probe->accelerationCPerDay = 0.0;
            $probe->currentTask = null;
            $this->probes->save($probe);
            return;
        }

        $this->movements->save($movement);
    }

    private function directionBetween(SectorCoordinates $origin, SectorCoordinates $target): ProbeDirection
    {
        $dx = $target->getX() - $origin->getX();
        $dy = $target->getY() - $origin->getY();
        $dz = $target->getZ() - $origin->getZ();
        $length = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz));
        if ($length <= 0.0) {
            return new ProbeDirection(0.0, 0.0, 0.0);
        }

        return new ProbeDirection(round($dx / $length, 4), round($dy / $length, 4), round($dz / $length, 4));
    }

    private function progressBetween(\DateTimeImmutable $now, string $start, string $end): float
    {
        $startTime = (new \DateTimeImmutable($start))->getTimestamp();
        $endTime = (new \DateTimeImmutable($end))->getTimestamp();
        $duration = max(1, $endTime - $startTime);

        return max(0.0, min(1.0, ($now->getTimestamp() - $startTime) / $duration));
    }

    private function isBefore(\DateTimeImmutable $now, string $date): bool
    {
        return $now->getTimestamp() < (new \DateTimeImmutable($date))->getTimestamp();
    }

    private function isAtOrAfter(\DateTimeImmutable $now, string $date): bool
    {
        return $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
