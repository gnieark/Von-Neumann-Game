<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\UniverseObject;
use VonNeumannGame\Service\MannyActionException;

final class InspectSectorObjectTaskHandler implements TaskHandlerInterface
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
     * @param \Closure(SectorContent, string, int): ?UniverseObject $findInspectableSectorObject
     * @param \Closure(SectorContent, string, ?int): ?array<string, mixed> $hiddenDetachedContainerDetection
     * @param \Closure(): int $miningTravelSeconds
     * @param \Closure(UniverseObject): array<string, mixed> $targetArray
     * @param \Closure(Manny): void $releaseMannyFromStorage
     * @param \Closure(Manny): void $removeMannyFromSector
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(SectorDetachedContainer): array<string, mixed> $detachedContainerInspectionReport
     * @param \Closure(NeumannProbe, SectorContent, DormantConstruct): array<string, string> $dormantConstructInspectionReport
     * @param \Closure(int, SectorCoordinates, string, string, string, string, ?string): void $createMannyReportAlert
     * @param \Closure(Manny, array<string, mixed>): void $clearTask
     * @param \Closure(Manny, string): void $registerMannyInSector
     * @param \Closure(NeumannProbe, Manny): bool $placeMannyOnProbe
     * @param \Closure(Manny, array<string, mixed>): void $waitForStorageSpace
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
        private readonly \Closure $findInspectableSectorObject,
        private readonly \Closure $hiddenDetachedContainerDetection,
        private readonly \Closure $miningTravelSeconds,
        private readonly \Closure $targetArray,
        private readonly \Closure $releaseMannyFromStorage,
        private readonly \Closure $removeMannyFromSector,
        private readonly \Closure $saveManny,
        private readonly \Closure $detachedContainerInspectionReport,
        private readonly \Closure $dormantConstructInspectionReport,
        private readonly \Closure $createMannyReportAlert,
        private readonly \Closure $clearTask,
        private readonly \Closure $registerMannyInSector,
        private readonly \Closure $placeMannyOnProbe,
        private readonly \Closure $waitForStorageSpace,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_INSPECTING_SECTOR_OBJECT || $task === 'inspecting_asteroid';
    }

    public function start(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        $remoteViaScut = !$manny->isInSameSectorAs($probe);
        if ($remoteViaScut && !($this->canOrderRemoteMannyViaScut)($manny, $probe)) {
            ($this->ensureMannyInRange)($manny, $probe);
        } elseif (!$remoteViaScut) {
            ($this->ensureMannyInRange)($manny, $probe);
        }

        $taskSector = $manny->sector ?? $probe->currentSector;
        $sector = ($this->getOrCreateSector)($taskSector);
        $target = ($this->findInspectableSectorObject)($sector, $objectId, $probe->playerId);
        if (!$target instanceof UniverseObject) {
            throw new MannyActionException(422, 'invalid_sector_object_target', 'This object cannot be inspected by a Manny.');
        }

        $detection = $target instanceof Asteroid
            ? ($this->hiddenDetachedContainerDetection)($sector, $objectId, $probe->playerId)
            : null;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $durationSeconds = ($this->miningTravelSeconds)() * 2;
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $taskSector;
        $manny->currentTask = Manny::TASK_INSPECTING_SECTOR_OBJECT;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'durationSeconds' => $durationSeconds,
            'target' => ($this->targetArray)($target),
            'targetMode' => $target instanceof SectorDetachedContainer ? $target->getMode() : null,
        ] + ($detection !== null ? ['artificialObjectDetected' => $detection] : []);
        ($this->releaseMannyFromStorage)($manny);
        ($this->removeMannyFromSector)($manny);
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $sector = ($this->getOrCreateSector)($manny->sector ?? $probe->currentSector);
        $objectId = (string) ($manny->taskPayload['objectId'] ?? '');
        $target = ($this->findInspectableSectorObject)($sector, $objectId, $probe->playerId);
        $detection = $target instanceof Asteroid
            ? ($this->hiddenDetachedContainerDetection)($sector, $objectId, $probe->playerId)
            : null;
        $result = [
            'lastTask' => Manny::TASK_INSPECTING_SECTOR_OBJECT,
            'result' => 'success',
            'objectId' => $objectId,
            'target' => $manny->taskPayload['target'] ?? null,
        ] + ($detection !== null ? ['artificialObjectDetected' => $detection] : []);
        $reportScheduledAt = is_string($manny->taskEndsAt) && trim($manny->taskEndsAt) !== ''
            ? $manny->taskEndsAt
            : null;

        if ($target === null) {
            $result['result'] = 'failed';
            $result['failureReason'] = 'target_unavailable';
        } elseif ($target instanceof SectorDetachedContainer) {
            $report = ($this->detachedContainerInspectionReport)($target);
            $result['containerReport'] = $report;
            ($this->createMannyReportAlert)(
                $probe->id,
                $sector->getCoordinates(),
                $target->getId(),
                (string) ($target->getName() ?? $target->getId()),
                (string) $report['message'],
                'detached_storage_container',
                $reportScheduledAt,
            );
        } elseif ($target instanceof DormantConstruct) {
            $report = ($this->dormantConstructInspectionReport)($probe, $sector, $target);
            ($this->createMannyReportAlert)(
                $probe->id,
                $sector->getCoordinates(),
                $target->getId(),
                (string) ($target->getName() ?? $target->getId()),
                $report['message'],
                'dormant_construct',
                $reportScheduledAt,
            );
        }

        if (!$manny->isInSameSectorAs($probe)) {
            ($this->clearTask)($manny, $result);
            $manny->locationType = Manny::LOCATION_SECTOR;
            ($this->registerMannyInSector)($manny, SectorManny::STATE_FORGOTTEN);
            ($this->saveManny)($manny);

            return ($this->findMannyById)($manny->id) ?? $manny;
        }

        if (!($this->placeMannyOnProbe)($probe, $manny)) {
            ($this->waitForStorageSpace)($manny, ['reason' => 'return_to_probe'] + $result);
            ($this->saveManny)($manny);

            return ($this->findMannyById)($manny->id) ?? $manny;
        }

        ($this->removeMannyFromSector)($manny);
        $manny->locationType = Manny::LOCATION_PROBE;
        $manny->sector = null;
        ($this->clearTask)($manny, $result);
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
