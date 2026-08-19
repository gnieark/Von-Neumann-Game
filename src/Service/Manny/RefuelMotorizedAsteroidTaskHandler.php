<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Service\MannyActionException;

final class RefuelMotorizedAsteroidTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $refreshOtherMannyStates,
        private readonly \Closure $getOrCreateSector,
        private readonly \Closure $refuelingAlreadyScheduled,
        private readonly \Closure $hasActiveTrajectory,
        private readonly \Closure $consumeFuel,
        private readonly \Closure $miningTravelSeconds,
        private readonly \Closure $releaseMannyFromStorage,
        private readonly \Closure $removeMannyFromSector,
        private readonly \Closure $saveManny,
        private readonly \Closure $saveSector,
        private readonly \Closure $clearTask,
        private readonly \Closure $registerMannyInSector,
        private readonly \Closure $findMannyById,
    ) {}

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_REFUELING_MOTORIZED_ASTEROID;
    }

    public function start(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to refuel an asteroid.');
        }

        $sector = ($this->getOrCreateSector)($probe->currentSector);
        $target = $sector->findObjectById($objectId);
        if (!$target instanceof Asteroid) {
            throw new MannyActionException(404, 'asteroid_not_found', 'The asteroid is not present in this sector.');
        }
        if (!$target->isMotorized()) {
            throw new MannyActionException(409, 'asteroid_not_motorized', 'This asteroid is not motorized.');
        }
        if ($target->getMotorFuelStatus() === Asteroid::MOTOR_FUEL_FULL) {
            throw new MannyActionException(409, 'asteroid_motor_already_full', 'This asteroid motor is already full.');
        }
        if (($this->hasActiveTrajectory)($objectId)) {
            throw new MannyActionException(409, 'asteroid_trajectory_already_active', 'This asteroid already has an active trajectory.');
        }
        if (($this->refuelingAlreadyScheduled)($probe, $objectId)) {
            throw new MannyActionException(409, 'asteroid_refueling_already_scheduled', 'This asteroid is already targeted by another refueling task.');
        }

        ($this->consumeFuel)($probe);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $travelSeconds = ($this->miningTravelSeconds)();
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_REFUELING_MOTORIZED_ASTEROID;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $travelSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'durationSeconds' => $travelSeconds,
            'target' => $target->toArray(),
        ];
        ($this->releaseMannyFromStorage)($manny);
        ($this->removeMannyFromSector)($manny);
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if ($manny->taskEndsAt === null || $now->getTimestamp() < (new \DateTimeImmutable($manny->taskEndsAt))->getTimestamp()) {
            return $manny;
        }

        $sector = ($this->getOrCreateSector)($manny->sector ?? $probe->currentSector);
        $objectId = (string) ($manny->taskPayload['objectId'] ?? '');
        $target = $sector->findObjectById($objectId);
        $result = ['lastTask' => Manny::TASK_REFUELING_MOTORIZED_ASTEROID, 'objectId' => $objectId];
        $refueled = false;
        if (!$target instanceof Asteroid) {
            $result += ['result' => 'failed', 'failureReason' => 'asteroid_not_found'];
        } elseif (!$target->isMotorized()) {
            $result += ['result' => 'failed', 'failureReason' => 'asteroid_not_motorized'];
        } elseif ($target->getMotorFuelStatus() === Asteroid::MOTOR_FUEL_FULL) {
            $result += ['result' => 'already_completed', 'asteroid' => $target->toArray()];
            $refueled = true;
        } elseif (($this->hasActiveTrajectory)($objectId)) {
            $result += ['result' => 'failed', 'failureReason' => 'asteroid_trajectory_already_active'];
        } else {
            $target = $target->withMotorFuelStatus(Asteroid::MOTOR_FUEL_FULL);
            $sector->replaceObject($target);
            ($this->saveSector)($sector);
            $result += ['result' => 'success', 'asteroid' => $target->toArray()];
            $refueled = true;
        }

        $manny->locationType = Manny::LOCATION_SECTOR;
        if ($refueled) {
            $returnSeconds = ($this->miningTravelSeconds)();
            $manny->currentTask = Manny::TASK_RETURNING;
            $manny->taskStartedAt = $now->format('c');
            $manny->taskEndsAt = $now->modify('+' . $returnSeconds . ' seconds')->format('c');
            $manny->taskPayload = $result + ['reason' => 'asteroid_refueling_completed', 'durationSeconds' => $returnSeconds];
            ($this->removeMannyFromSector)($manny);
        } else {
            ($this->clearTask)($manny, $result);
            ($this->registerMannyInSector)($manny, \VonNeumannGame\Sector\SectorManny::STATE_FORGOTTEN);
        }
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }
}
