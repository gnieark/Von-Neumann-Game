<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Service\MannyActionException;

final class DetachStorageContainerTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe, Manny): void $refreshOtherMannyStates
     * @param \Closure(NeumannProbe, string): mixed $findObjectInCurrentSector
     * @param \Closure(NeumannProbe, string, int): array<string, mixed> $detachAdditionalContainerSnapshot
     * @param \Closure(): int $detachStorageContainerSeconds
     * @param \Closure(mixed): array<string, mixed> $targetArray
     * @param \Closure(int): ?NeumannProbe $findProbeById
     * @param \Closure(NeumannProbe): bool $probeAcceptsMannyOrders
     * @param \Closure(NeumannProbe, array<string, mixed>): void $restoreDetachedContainerSnapshot
     * @param \Closure(string, ?string): array<string, mixed> $hiddenDetachedContainerDetectionPayload
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(mixed): SectorContent $getOrCreateSector
     * @param \Closure(SectorContent): void $saveSector
     * @param \Closure(SectorDetachedContainer): array<string, mixed> $detachedContainerPublicArray
     * @param \Closure(Manny, array<string, mixed>): void $clearTask
     * @param \Closure(int): ?Manny $findMannyById
     */
    public function __construct(
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $refreshOtherMannyStates,
        private readonly \Closure $findObjectInCurrentSector,
        private readonly \Closure $detachAdditionalContainerSnapshot,
        private readonly \Closure $detachStorageContainerSeconds,
        private readonly \Closure $targetArray,
        private readonly \Closure $findProbeById,
        private readonly \Closure $probeAcceptsMannyOrders,
        private readonly \Closure $restoreDetachedContainerSnapshot,
        private readonly \Closure $hiddenDetachedContainerDetectionPayload,
        private readonly \Closure $saveManny,
        private readonly \Closure $getOrCreateSector,
        private readonly \Closure $saveSector,
        private readonly \Closure $detachedContainerPublicArray,
        private readonly \Closure $clearTask,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_DETACHING_STORAGE_CONTAINER;
    }

    public function start(NeumannProbe $probe, int $ownerPlayerId, string $uid, string $containerId, string $mode, ?string $objectId = null): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to detach storage.');
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, [SectorDetachedContainer::MODE_DRIFTING, SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID, SectorDetachedContainer::MODE_ATTACH_TO_PROBE], true)) {
            throw new MannyActionException(400, 'bad_request', 'Detach mode must be drifting, hidden_on_asteroid, or attach_to_probe.');
        }

        $target = null;
        if ($mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID) {
            if ($objectId === null || trim($objectId) === '') {
                throw new MannyActionException(400, 'bad_request', 'objectId is required for hidden_on_asteroid mode.');
            }
            $target = ($this->findObjectInCurrentSector)($probe, $objectId);
            if (!$target instanceof Asteroid) {
                throw new MannyActionException(422, 'invalid_asteroid_target', 'Hidden containers must be attached to an asteroid in the current sector.');
            }
        } elseif ($mode === SectorDetachedContainer::MODE_ATTACH_TO_PROBE) {
            if ($objectId === null || trim($objectId) === '') {
                throw new MannyActionException(400, 'bad_request', 'objectId is required for attach_to_probe mode.');
            }
            $targetProbeId = filter_var($objectId, FILTER_VALIDATE_INT);
            if ($targetProbeId === false || $targetProbeId <= 0 || $targetProbeId === $probe->id) {
                throw new MannyActionException(422, 'invalid_target_probe', 'Target probe must reference another owned probe in the current sector.');
            }
            $target = ($this->findProbeById)((int) $targetProbeId);
            if (!$target instanceof NeumannProbe || $target->playerId !== $ownerPlayerId) {
                throw new MannyActionException(404, 'target_probe_not_found', 'Target probe not found.');
            }
            ($this->ensureProbeAcceptsMannyOrders)($target);
            if (!$target->currentSector->equals($probe->currentSector)) {
                throw new MannyActionException(422, 'probe_not_in_same_sector', 'Target probe must be in the same sector as the source probe.');
            }
        }

        $snapshot = ($this->detachAdditionalContainerSnapshot)($probe, $containerId, $ownerPlayerId);
        $targetObjectId = in_array($mode, [SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID, SectorDetachedContainer::MODE_ATTACH_TO_PROBE], true) ? $objectId : null;
        $detachedObjectId = SectorDetachedContainer::objectIdForContainer((string) $snapshot['sourceContainerId']);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $durationSeconds = ($this->detachStorageContainerSeconds)();

        $manny->currentTask = Manny::TASK_DETACHING_STORAGE_CONTAINER;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'containerId' => $containerId,
            'objectId' => $detachedObjectId,
            'mode' => $mode,
            'targetObjectId' => $targetObjectId,
            'durationSeconds' => $durationSeconds,
            'snapshot' => $snapshot,
            'target' => $target instanceof Asteroid ? ($this->targetArray)($target) : null,
            'targetProbe' => $target instanceof NeumannProbe ? [
                'id' => $target->id,
                'name' => $target->name,
            ] : null,
        ] + ($mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID
            ? ['artificialObjectDetected' => ($this->hiddenDetachedContainerDetectionPayload)($detachedObjectId, (string) $targetObjectId)]
            : []);
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $snapshot = is_array($manny->taskPayload['snapshot'] ?? null) ? $manny->taskPayload['snapshot'] : [];
        $mode = (string) ($manny->taskPayload['mode'] ?? SectorDetachedContainer::MODE_DRIFTING);
        $objectId = (string) ($manny->taskPayload['objectId'] ?? SectorDetachedContainer::objectIdForContainer((string) ($snapshot['sourceContainerId'] ?? 'storage')));
        $targetObjectId = isset($manny->taskPayload['targetObjectId']) ? (string) $manny->taskPayload['targetObjectId'] : null;
        $sectorCoordinates = $probe->currentSector;
        $sector = ($this->getOrCreateSector)($sectorCoordinates);
        $containerData = is_array($snapshot['container'] ?? null) ? $snapshot['container'] : [];

        if ($mode === SectorDetachedContainer::MODE_ATTACH_TO_PROBE) {
            $targetProbeId = filter_var($targetObjectId, FILTER_VALIDATE_INT);
            $targetProbe = $targetProbeId !== false && $targetProbeId > 0 ? ($this->findProbeById)((int) $targetProbeId) : null;
            if (
                !$targetProbe instanceof NeumannProbe
                || $targetProbe->playerId !== (int) ($snapshot['ownerPlayerId'] ?? $probe->playerId)
                || !$targetProbe->currentSector->equals($probe->currentSector)
                || !($this->probeAcceptsMannyOrders)($targetProbe)
            ) {
                ($this->clearTask)($manny, [
                    'lastTask' => Manny::TASK_DETACHING_STORAGE_CONTAINER,
                    'result' => 'failed',
                    'failureReason' => $targetProbe instanceof NeumannProbe ? 'target_probe_unavailable' : 'target_probe_not_found',
                    'mode' => $mode,
                    'targetObjectId' => $targetObjectId,
                ]);
                ($this->saveManny)($manny);

                return ($this->findMannyById)($manny->id) ?? $manny;
            }

            ($this->restoreDetachedContainerSnapshot)($targetProbe, $snapshot);
            ($this->clearTask)($manny, [
                'lastTask' => Manny::TASK_DETACHING_STORAGE_CONTAINER,
                'result' => 'success',
                'mode' => $mode,
                'targetObjectId' => $targetObjectId,
                'targetProbeId' => $targetProbe->id,
                'targetProbeName' => $targetProbe->name,
            ]);
            ($this->saveManny)($manny);

            return ($this->findMannyById)($manny->id) ?? $manny;
        }

        $object = new SectorDetachedContainer(
            $objectId,
            (string) ($containerData['label'] ?? 'Detached storage container'),
            $mode,
            (int) ($snapshot['ownerProbeId'] ?? $probe->id),
            (int) ($snapshot['ownerPlayerId'] ?? $probe->playerId),
            null,
            $targetObjectId,
            (float) ($containerData['capacity'] ?? 0.0),
            ProbeInventory::CAPACITY_UNIT,
            gmdate('c'),
            $snapshot + [
                'mode' => $mode,
                'sector' => $sectorCoordinates->toArray(),
                'targetObjectId' => $targetObjectId,
            ],
            $mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID
                ? 'Detached storage container hidden on an asteroid.'
                : 'Detached storage container drifting in open space.',
            [],
            $mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID ? [(int) ($snapshot['ownerPlayerId'] ?? $probe->playerId)] : [],
        );

        if ($mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID) {
            $sector->addHiddenDetachedContainer($object);
        } else {
            if (!$sector->replaceObject($object)) {
                $sector->addObject($object);
            }
        }
        ($this->saveSector)($sector);

        ($this->clearTask)($manny, [
            'lastTask' => Manny::TASK_DETACHING_STORAGE_CONTAINER,
            'result' => 'success',
            'objectId' => $object->getId(),
            'mode' => $mode,
            'targetObjectId' => $targetObjectId,
            'detachedContainer' => ($this->detachedContainerPublicArray)($object),
        ] + ($mode === SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID
            ? ['artificialObjectDetected' => ($this->hiddenDetachedContainerDetectionPayload)($object->getId(), $targetObjectId)]
            : []));
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
