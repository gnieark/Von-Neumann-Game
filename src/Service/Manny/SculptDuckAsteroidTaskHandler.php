<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Service\MannyActionException;

final class SculptDuckAsteroidTaskHandler implements TaskHandlerInterface
{
    public const DURATION_SECONDS = 172800;

    public function __construct(
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $refreshOtherMannyStates,
        private readonly \Closure $getOrCreateSector,
        private readonly \Closure $sculptingAlreadyScheduled,
        private readonly \Closure $releaseMannyFromStorage,
        private readonly \Closure $removeMannyFromSector,
        private readonly \Closure $saveManny,
        private readonly \Closure $saveSector,
        private readonly \Closure $clearTask,
        private readonly \Closure $registerMannyInSector,
        private readonly \Closure $findMannyById,
        private readonly \Closure $miningTravelSeconds,
    ) {}

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_SCULPTING_DUCK_ASTEROID;
    }

    public function start(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to sculpt an asteroid.');
        }

        $sector = ($this->getOrCreateSector)($probe->currentSector);
        $target = $sector->findObjectById($objectId);
        if (!$target instanceof Asteroid) {
            throw new MannyActionException(422, 'invalid_asteroid_target', 'The target must be an asteroid in the probe sector.');
        }
        if ($target->isDuckShaped()) {
            throw new MannyActionException(409, 'asteroid_already_duck_shaped', 'This asteroid is already sculpted in the shape of a duck.');
        }
        if (($this->sculptingAlreadyScheduled)($probe, $objectId)) {
            throw new MannyActionException(409, 'asteroid_sculpting_already_scheduled', 'This asteroid is already targeted by another sculpting task.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_SCULPTING_DUCK_ASTEROID;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . self::DURATION_SECONDS . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'durationSeconds' => self::DURATION_SECONDS,
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
        $result = [
            'lastTask' => Manny::TASK_SCULPTING_DUCK_ASTEROID,
            'objectId' => $objectId,
            'target' => $manny->taskPayload['target'] ?? null,
        ];
        $sculpted = false;
        if (!$target instanceof Asteroid) {
            $result += ['result' => 'failed', 'failureReason' => 'target_unavailable'];
        } elseif ($target->isDuckShaped()) {
            $result += ['result' => 'already_completed', 'asteroid' => $target->toArray()];
            $sculpted = true;
        } else {
            $target = $target->sculptedInTheShapeOfADuck();
            if (!$sector->replaceObject($target)) {
                throw new \RuntimeException('Asteroid replacement failed during anatiform sculpting.');
            }
            ($this->saveSector)($sector);
            $result += ['result' => 'success', 'asteroid' => $target->toArray()];
            $sculpted = true;
        }

        $manny->locationType = Manny::LOCATION_SECTOR;
        if ($sculpted) {
            $returnDurationSeconds = ($this->miningTravelSeconds)();
            $manny->currentTask = Manny::TASK_RETURNING;
            $manny->taskStartedAt = $now->format('c');
            $manny->taskEndsAt = $now->modify('+' . $returnDurationSeconds . ' seconds')->format('c');
            $manny->taskPayload = $result + [
                'reason' => 'duck_asteroid_sculpting_completed',
                'durationSeconds' => $returnDurationSeconds,
            ];
            ($this->removeMannyFromSector)($manny);
        } else {
            ($this->clearTask)($manny, $result);
            ($this->registerMannyInSector)($manny, SectorManny::STATE_FORGOTTEN);
        }
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }
}
