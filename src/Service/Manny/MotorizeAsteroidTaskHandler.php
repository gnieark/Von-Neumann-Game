<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Service\MannyActionException;

final class MotorizeAsteroidTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $refreshOtherMannyStates,
        private readonly \Closure $getOrCreateSector,
        private readonly \Closure $probeItems,
        private readonly \Closure $motorizationAlreadyScheduled,
        private readonly \Closure $deleteItem,
        private readonly \Closure $consumedItemPayload,
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
        return $task === Manny::TASK_MOTORIZING_ASTEROID;
    }

    public function start(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to motorize an asteroid.');
        }

        $sector = ($this->getOrCreateSector)($probe->currentSector);
        $target = $sector->findObjectById($objectId);
        $installed = false;
        if (!$target instanceof Asteroid) {
            throw new MannyActionException(422, 'invalid_asteroid_target', 'The target must be an asteroid in the probe sector.');
        }
        if ($target->isMotorized()) {
            throw new MannyActionException(409, 'asteroid_already_motorized', 'This asteroid is already motorized.');
        }
        if (($this->motorizationAlreadyScheduled)($probe, $objectId)) {
            throw new MannyActionException(409, 'asteroid_motorization_already_scheduled', 'This asteroid is already targeted by another motorization task.');
        }

        $itemsByType = [];
        foreach (($this->probeItems)($probe) as $item) {
            if ($item instanceof ProbeItem) {
                $itemsByType[$item->type][] = $item;
            }
        }
        $engines = $itemsByType[ProbeItem::TYPE_DEUTERIUM_ENGINE] ?? [];
        $steelBars = $itemsByType[ProbeItem::TYPE_STEEL_BAR] ?? [];
        if (count($engines) < 1 || count($steelBars) < 4) {
            throw new MannyActionException(422, 'insufficient_asteroid_motorization_components', 'One deuterium engine and four steel bars are required.');
        }

        $consumedItems = [($this->consumedItemPayload)($engines[0])];
        ($this->deleteItem)($engines[0]);
        foreach (array_slice($steelBars, 0, 4) as $steelBar) {
            $consumedItems[] = ($this->consumedItemPayload)($steelBar);
            ($this->deleteItem)($steelBar);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $durationSeconds = ($this->miningTravelSeconds)() + 300;
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_MOTORIZING_ASTEROID;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'durationSeconds' => $durationSeconds,
            'target' => $target->toArray(),
            'consumedItems' => $consumedItems,
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
            'lastTask' => Manny::TASK_MOTORIZING_ASTEROID,
            'objectId' => $objectId,
            'target' => $manny->taskPayload['target'] ?? null,
        ];
        if (!$target instanceof Asteroid) {
            $result += ['result' => 'failed', 'failureReason' => 'target_unavailable'];
        } elseif ($target->isMotorized()) {
            $result += ['result' => 'failed', 'failureReason' => 'asteroid_already_motorized'];
        } else {
            $motorized = $target->withDeuteriumEngine();
            $sector->replaceObject($motorized);
            ($this->saveSector)($sector);
            $result += ['result' => 'success', 'asteroid' => $motorized->toArray()];
            $installed = true;
        }

        $manny->locationType = Manny::LOCATION_SECTOR;
        if ($installed) {
            $returnDurationSeconds = ($this->miningTravelSeconds)();
            $manny->currentTask = Manny::TASK_RETURNING;
            $manny->taskStartedAt = $now->format('c');
            $manny->taskEndsAt = $now->modify('+' . $returnDurationSeconds . ' seconds')->format('c');
            $manny->taskPayload = $result + [
                'reason' => 'asteroid_motorization_completed',
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
