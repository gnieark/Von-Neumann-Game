<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\UniverseObject;
use VonNeumannGame\Service\MannyActionException;

final class MiningTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe, Manny): void $refreshOtherMannyStates
     * @param \Closure(Manny, NeumannProbe): bool $canOrderRemoteMannyViaScut
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(mixed): SectorContent $getOrCreateSector
     * @param \Closure(UniverseObject): bool $isMineableObject
     * @param \Closure(NeumannProbe, Asteroid, mixed): array<string, float> $availableAsteroidResourceAmountsForOrders
     * @param \Closure(UniverseObject): array<string, float> $resourceComposition
     * @param \Closure(array<string, float>, array<string, float>, float): void $ensureAsteroidHasResources
     * @param \Closure(SectorContent, string, ?int): ?array<string, mixed> $hiddenDetachedContainerDetection
     * @param \Closure(SectorContent, string, string): array{container:SectorDetachedContainer, sameAsteroid:bool} $miningTargetContainer
     * @param \Closure(SectorDetachedContainer): float $detachedContainerFreeCapacity
     * @param \Closure(float, array<string, float>): array<string, float> $resourceAmountsForTotal
     * @param \Closure(NeumannProbe, array<string, float>, Manny): bool $canAcceptMiningStart
     * @param \Closure(UniverseObject): array<string, mixed> $miningTargetArray
     * @param \Closure(SectorDetachedContainer, bool): array<string, mixed> $miningTargetContainerPayload
     * @param \Closure(): int $miningTravelSeconds
     * @param \Closure(float, ?int): int $miningDurationSeconds
     * @param \Closure(Manny): void $releaseMannyFromStorage
     * @param \Closure(Manny): void $removeMannyFromSector
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(float, int, ?int): array{phase:string, tripIndex:int, deliveredAmount:float, cargoAmount:float} $miningProgress
     * @param \Closure(Manny): int $miningTaskTravelSeconds
     * @param \Closure(Manny): array<string, float> $miningResourceProfile
     * @param \Closure(Manny): ?string $miningTaskTargetContainerId
     * @param \Closure(Manny, array<string, float>, float): float $depleteMiningTarget
     * @param \Closure(Manny, array<string, float>, float): float $transferMiningResourcesToDetachedContainer
     * @param \Closure(NeumannProbe, array<string, float>, float, bool): bool $canAcceptMiningDelivery
     * @param \Closure(Manny, array<string, float>, float): void $setMannyCargoProfile
     * @param \Closure(Manny, array<string, mixed>): void $waitForStorageSpace
     * @param \Closure(NeumannProbe, array<string, float>, float): void $transferMiningResourcesToProbe
     * @param \Closure(Manny): void $clearMannyCargo
     * @param \Closure(Manny, array<string, mixed>): void $clearTask
     * @param \Closure(Manny, string): void $registerMannyInSector
     * @param \Closure(NeumannProbe, Manny): bool $placeMannyOnProbe
     * @param \Closure(int): ?Manny $findMannyById
     */
    public function __construct(
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $refreshOtherMannyStates,
        private readonly \Closure $canOrderRemoteMannyViaScut,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $getOrCreateSector,
        private readonly \Closure $isMineableObject,
        private readonly \Closure $availableAsteroidResourceAmountsForOrders,
        private readonly \Closure $resourceComposition,
        private readonly \Closure $ensureAsteroidHasResources,
        private readonly \Closure $hiddenDetachedContainerDetection,
        private readonly \Closure $miningTargetContainer,
        private readonly \Closure $detachedContainerFreeCapacity,
        private readonly \Closure $resourceAmountsForTotal,
        private readonly \Closure $canAcceptMiningStart,
        private readonly \Closure $miningTargetArray,
        private readonly \Closure $miningTargetContainerPayload,
        private readonly \Closure $miningTravelSeconds,
        private readonly \Closure $miningDurationSeconds,
        private readonly \Closure $releaseMannyFromStorage,
        private readonly \Closure $removeMannyFromSector,
        private readonly \Closure $saveManny,
        private readonly \Closure $miningProgress,
        private readonly \Closure $miningTaskTravelSeconds,
        private readonly \Closure $miningResourceProfile,
        private readonly \Closure $miningTaskTargetContainerId,
        private readonly \Closure $depleteMiningTarget,
        private readonly \Closure $transferMiningResourcesToDetachedContainer,
        private readonly \Closure $canAcceptMiningDelivery,
        private readonly \Closure $setMannyCargoProfile,
        private readonly \Closure $waitForStorageSpace,
        private readonly \Closure $transferMiningResourcesToProbe,
        private readonly \Closure $clearMannyCargo,
        private readonly \Closure $clearTask,
        private readonly \Closure $registerMannyInSector,
        private readonly \Closure $placeMannyOnProbe,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_MINING;
    }

    public function start(NeumannProbe $probe, string $uid, string $objectId, string|array $resourceTypes, float $targetAmount, ?string $targetContainerId = null): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        $remoteViaScut = !$manny->isInSameSectorAs($probe);
        if ($remoteViaScut) {
            if (!($this->canOrderRemoteMannyViaScut)($manny, $probe)) {
                ($this->ensureMannyInRange)($manny, $probe);
            }
            if ($targetContainerId === null) {
                throw new MannyActionException(422, 'invalid_storage_container', 'Remote SCUT mining requires a detached target container in the Manny sector.');
            }
        }

        try {
            $selectedResources = ResourceComposition::normalizeSelection($resourceTypes);
        } catch (\InvalidArgumentException $e) {
            throw new MannyActionException(400, 'bad_request', $e->getMessage());
        }
        $targetAmount = round($targetAmount, 4);
        if ($targetAmount <= 0) {
            throw new MannyActionException(400, 'bad_request', 'Mining target amount must be greater than zero.');
        }

        $taskSector = $manny->sector ?? $probe->currentSector;
        $sector = ($this->getOrCreateSector)($taskSector);
        $target = $sector->findObjectById($objectId);
        if ($target === null || !($this->isMineableObject)($target)) {
            throw new MannyActionException(422, 'invalid_mining_target', 'This object cannot be mined by a Manny.');
        }

        $availableAmounts = $target instanceof Asteroid
            ? ($this->availableAsteroidResourceAmountsForOrders)($probe, $target, $taskSector)
            : null;
        $composition = $availableAmounts !== null
            ? ResourceComposition::fromAmounts($availableAmounts)
            : ($this->resourceComposition)($target);
        $available = ResourceComposition::availableTypes($composition);
        $unavailable = array_diff($selectedResources, $available);
        if ($unavailable !== []) {
            throw new MannyActionException(422, 'resource_unavailable', 'The requested resource is not present on this object.');
        }

        $targetContainer = null;
        $miningTravelSeconds = ($this->miningTravelSeconds)();
        $requestedTargetAmount = $targetAmount;
        if ($targetContainerId !== null) {
            $targetContainer = ($this->miningTargetContainer)($sector, $targetContainerId, $objectId);
            if (in_array(ResourceComposition::DEUTERIUM, $selectedResources, true)) {
                throw new MannyActionException(422, 'invalid_storage_container', 'Detached storage containers cannot receive deuterium.');
            }
            $targetContainerFreeCapacity = ($this->detachedContainerFreeCapacity)($targetContainer['container']);
            if ($targetContainerFreeCapacity <= 0.0001) {
                throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Target detached container is full.');
            }
            $targetAmount = round(min($targetAmount, $targetContainerFreeCapacity), 4);
            $miningTravelSeconds = $targetContainer['sameAsteroid'] ? 0 : $miningTravelSeconds;
        }

        $resourceProfile = ResourceComposition::profileForSelection($composition, $selectedResources);
        if ($target instanceof Asteroid && $availableAmounts !== null) {
            ($this->ensureAsteroidHasResources)($availableAmounts, $resourceProfile, $targetAmount);
        }
        $artificialObjectDetected = $target instanceof Asteroid
            ? ($this->hiddenDetachedContainerDetection)($sector, $target->getId(), $probe->playerId)
            : null;
        $probeIncomingResources = $targetContainer === null ? ($this->resourceAmountsForTotal)($targetAmount, $resourceProfile) : [];
        if (!($this->canAcceptMiningStart)($probe, $probeIncomingResources, $manny)) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for this mining target.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $taskSector;
        $manny->currentTask = Manny::TASK_MINING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . ($this->miningDurationSeconds)($targetAmount, $miningTravelSeconds) . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'resourceType' => $selectedResources[0],
            'resourceTypes' => $selectedResources,
            'targetAmount' => $targetAmount,
            'depositedAmount' => 0.0,
            'depositedResources' => [],
            'extractedAmount' => 0.0,
            'extractedResources' => [],
            'availableResources' => $available,
            'resourceComposition' => $composition,
            'resourceProfile' => $resourceProfile,
            'target' => ($this->miningTargetArray)($target),
            'miningTravelSeconds' => $miningTravelSeconds,
        ]
            + ($requestedTargetAmount > $targetAmount ? ['requestedTargetAmount' => $requestedTargetAmount] : [])
            + ($targetContainer !== null ? ['targetContainer' => ($this->miningTargetContainerPayload)($targetContainer['container'], $targetContainer['sameAsteroid'])] : [])
            + ($artificialObjectDetected !== null ? ['artificialObjectDetected' => $artificialObjectDetected] : []);
        $manny->cargoDeuterium = 0.0;
        $manny->cargoMetals = 0.0;
        $manny->cargoIce = 0.0;
        $manny->cargoOrganicCompounds = 0.0;
        ($this->releaseMannyFromStorage)($manny);
        ($this->removeMannyFromSector)($manny);
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if ($manny->taskStartedAt === null) {
            return $manny;
        }

        $elapsed = max(0, $now->getTimestamp() - (new \DateTimeImmutable($manny->taskStartedAt))->getTimestamp());
        $targetAmount = (float) ($manny->taskPayload['targetAmount'] ?? 0);
        $progress = ($this->miningProgress)($targetAmount, $elapsed, ($this->miningTaskTravelSeconds)($manny));
        $resourceProfile = ($this->miningResourceProfile)($manny);
        $targetContainerId = ($this->miningTaskTargetContainerId)($manny);
        $plannedExtracted = round(min($targetAmount, (float) $progress['deliveredAmount'] + (float) $progress['cargoAmount']), 4);
        $extracted = round((float) ($manny->taskPayload['extractedAmount'] ?? 0), 4);
        if ($plannedExtracted > $extracted) {
            $requestedDelta = round($plannedExtracted - $extracted, 4);
            $actualDelta = ($this->depleteMiningTarget)($manny, $resourceProfile, $requestedDelta);
            $extracted = round($extracted + $actualDelta, 4);
            $manny->taskPayload['extractedAmount'] = $extracted;
            $manny->taskPayload['extractedResources'] = ($this->resourceAmountsForTotal)($extracted, $resourceProfile);
            if ($actualDelta + 0.00001 < $requestedDelta) {
                $manny->taskPayload['sourceExhausted'] = true;
            }
        }

        $deposited = (float) ($manny->taskPayload['depositedAmount'] ?? 0);
        $complete = $progress['phase'] === 'complete' || $this->isAtOrAfter($now, $manny->taskEndsAt);
        $delivered = $complete ? $deposited : round(min((float) $progress['deliveredAmount'], $extracted), 4);
        if ($delivered > $deposited) {
            $deliveryAmount = round($delivered - $deposited, 4);
            if ($targetContainerId !== null) {
                $acceptedDelivery = ($this->transferMiningResourcesToDetachedContainer)($manny, $resourceProfile, $deliveryAmount);
                $delivered = round($deposited + $acceptedDelivery, 4);
                if ($acceptedDelivery + 0.00001 < $deliveryAmount) {
                    $complete = true;
                    $manny->taskPayload['targetContainerFull'] = true;
                }
            } elseif (!($this->canAcceptMiningDelivery)($probe, $resourceProfile, $deliveryAmount, false)) {
                ($this->setMannyCargoProfile)($manny, $resourceProfile, $deliveryAmount);
                ($this->waitForStorageSpace)($manny, [
                    'reason' => 'cargo_delivery',
                    'pendingAmount' => $deliveryAmount,
                    'resourceProfile' => $resourceProfile,
                ]);
                ($this->saveManny)($manny);

                return ($this->findMannyById)($manny->id) ?? $manny;
            }

            if ($targetContainerId === null) {
                ($this->transferMiningResourcesToProbe)($probe, $resourceProfile, $deliveryAmount);
            }
            $manny->taskPayload['depositedAmount'] = $delivered;
            $manny->taskPayload['depositedResources'] = ($this->resourceAmountsForTotal)((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
        }

        $cargoAmount = round(min((float) $progress['cargoAmount'], max(0.0, $extracted - $delivered)), 4);
        ($this->setMannyCargoProfile)($manny, $resourceProfile, $cargoAmount);
        $manny->taskPayload['phase'] = $progress['phase'];
        $manny->taskPayload['tripIndex'] = $progress['tripIndex'];

        if ($complete) {
            $remaining = round((float) ($manny->taskPayload['extractedAmount'] ?? 0) - (float) ($manny->taskPayload['depositedAmount'] ?? 0), 4);
            if ($targetContainerId !== null) {
                $acceptedRemaining = ($this->transferMiningResourcesToDetachedContainer)($manny, $resourceProfile, $remaining);
                if ($acceptedRemaining > 0.0) {
                    $manny->taskPayload['depositedAmount'] = round((float) ($manny->taskPayload['depositedAmount'] ?? 0) + $acceptedRemaining, 4);
                    $manny->taskPayload['depositedResources'] = ($this->resourceAmountsForTotal)((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
                }
                if ($acceptedRemaining + 0.00001 < $remaining) {
                    $manny->taskPayload['targetContainerFull'] = true;
                }
            } elseif (!($this->canAcceptMiningDelivery)($probe, $resourceProfile, $remaining, true)) {
                ($this->setMannyCargoProfile)($manny, $resourceProfile, $remaining);
                ($this->waitForStorageSpace)($manny, [
                    'reason' => 'return_to_probe',
                    'pendingAmount' => $remaining,
                    'resourceProfile' => $resourceProfile,
                ]);
                ($this->saveManny)($manny);

                return ($this->findMannyById)($manny->id) ?? $manny;
            }
            if ($targetContainerId === null && $remaining > 0) {
                ($this->transferMiningResourcesToProbe)($probe, $resourceProfile, $remaining);
                $manny->taskPayload['depositedAmount'] = round((float) ($manny->taskPayload['depositedAmount'] ?? 0) + $remaining, 4);
                $manny->taskPayload['depositedResources'] = ($this->resourceAmountsForTotal)((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
            }
            ($this->clearMannyCargo)($manny);
            if (!$manny->isInSameSectorAs($probe)) {
                ($this->clearTask)($manny, []);
                ($this->registerMannyInSector)($manny, SectorManny::STATE_FORGOTTEN);
                ($this->saveManny)($manny);

                return ($this->findMannyById)($manny->id) ?? $manny;
            }
            if (!($this->placeMannyOnProbe)($probe, $manny)) {
                ($this->waitForStorageSpace)($manny, ['reason' => 'return_to_probe']);
                ($this->saveManny)($manny);

                return ($this->findMannyById)($manny->id) ?? $manny;
            }
            ($this->removeMannyFromSector)($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            ($this->clearTask)($manny, []);
        }

        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
