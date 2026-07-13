<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ScutRelay;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorDriftingItem;
use VonNeumannGame\Sector\UniverseObject;
use VonNeumannGame\Service\MannyActionException;

final class SalvageTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe, Manny): void $refreshOtherMannyStates
     * @param \Closure(NeumannProbe, string): UniverseObject|ScutRelay|null $findCurrentSectorSalvageTarget
     * @param \Closure(UniverseObject|ScutRelay): bool $isSalvageableTarget
     * @param \Closure(NeumannProbe, ScutRelay, int): void $ensureScutRelayNotAlreadyBeingSalvaged
     * @param \Closure(NeumannProbe, SectorDriftingItem): array<string, mixed> $reserveDriftingItemForSalvage
     * @param \Closure(NeumannProbe, SectorDetachedContainer): array<string, mixed> $reserveDetachedContainerForSalvage
     * @param \Closure(UniverseObject|ScutRelay): array<string, mixed> $salvageTargetArray
     * @param \Closure(): int $salvageSeconds
     * @param \Closure(Manny): void $releaseMannyFromStorage
     * @param \Closure(Manny): void $removeMannyFromSector
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(mixed): SectorContent $getOrCreateSector
     * @param \Closure(Manny): ?array<string, mixed> $reservedSalvageItemPayload
     * @param \Closure(Manny): ?array<string, mixed> $reservedDetachedContainerPayload
     * @param \Closure(SectorContent, string): ?ScutRelay $findScutRelayInSector
     * @param \Closure(NeumannProbe, SectorContent, UniverseObject|ScutRelay): array<string, mixed> $completeSalvageTarget
     * @param \Closure(Manny, NeumannProbe, array<string, mixed>): void $finishSalvageActor
     * @param \Closure(NeumannProbe): void $saveProbe
     * @param \Closure(int): ?Manny $findMannyById
     */
    public function __construct(
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $refreshOtherMannyStates,
        private readonly \Closure $findCurrentSectorSalvageTarget,
        private readonly \Closure $isSalvageableTarget,
        private readonly \Closure $ensureScutRelayNotAlreadyBeingSalvaged,
        private readonly \Closure $reserveDriftingItemForSalvage,
        private readonly \Closure $reserveDetachedContainerForSalvage,
        private readonly \Closure $salvageTargetArray,
        private readonly \Closure $salvageSeconds,
        private readonly \Closure $releaseMannyFromStorage,
        private readonly \Closure $removeMannyFromSector,
        private readonly \Closure $saveManny,
        private readonly \Closure $getOrCreateSector,
        private readonly \Closure $reservedSalvageItemPayload,
        private readonly \Closure $reservedDetachedContainerPayload,
        private readonly \Closure $findScutRelayInSector,
        private readonly \Closure $completeSalvageTarget,
        private readonly \Closure $finishSalvageActor,
        private readonly \Closure $saveProbe,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_SALVAGE;
    }

    public function start(NeumannProbe $probe, string $uid, string $objectId): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);

        $target = ($this->findCurrentSectorSalvageTarget)($probe, $objectId);
        if ($target === null || !($this->isSalvageableTarget)($target)) {
            throw new MannyActionException(422, 'invalid_salvage_target', 'This object cannot be recovered by a Manny.');
        }
        if ($target instanceof ScutRelay) {
            ($this->ensureScutRelayNotAlreadyBeingSalvaged)($probe, $target, $manny->id);
        }

        $reservedItem = $target instanceof SectorDriftingItem
            ? ($this->reserveDriftingItemForSalvage)($probe, $target)
            : null;
        $reservedDetachedContainer = $target instanceof SectorDetachedContainer
            ? ($this->reserveDetachedContainerForSalvage)($probe, $target)
            : null;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $salvageSeconds = ($this->salvageSeconds)();
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_SALVAGE;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $salvageSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'durationSeconds' => $salvageSeconds,
            'target' => ($this->salvageTargetArray)($target),
            'result' => 'pending',
        ] + ($reservedItem !== null ? ['reservedItem' => $reservedItem] : [])
            + ($reservedDetachedContainer !== null ? ['reservedDetachedContainer' => $reservedDetachedContainer] : []);
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

        $objectId = (string) ($manny->taskPayload['objectId'] ?? '');
        $sectorCoordinates = $manny->sector ?? $probe->currentSector;
        $sector = ($this->getOrCreateSector)($sectorCoordinates);
        $reservedItem = ($this->reservedSalvageItemPayload)($manny);
        $result = [
            'lastTask' => Manny::TASK_SALVAGE,
            'objectId' => $objectId,
            'target' => $manny->taskPayload['target'] ?? null,
        ];

        if ($reservedItem !== null) {
            $result['result'] = 'success';
            $result['reservedItem'] = $reservedItem;
            $result['salvaged'] = [
                'type' => $reservedItem['type'],
                'name' => $reservedItem['name'],
                'quantity' => $reservedItem['quantity'],
                'containerSpace' => $reservedItem['containerSpace'],
            ];

            return $this->finishAndReload($manny, $probe, $result);
        }
        $reservedDetachedContainer = ($this->reservedDetachedContainerPayload)($manny);
        if ($reservedDetachedContainer !== null) {
            $result['result'] = 'success';
            $result['reservedDetachedContainer'] = $reservedDetachedContainer;
            $result['salvaged'] = [
                'type' => 'detached_storage_container',
                'id' => $reservedDetachedContainer['objectId'],
                'mode' => $reservedDetachedContainer['mode'],
                'capacity' => $reservedDetachedContainer['capacity'],
                'capacityUnit' => $reservedDetachedContainer['capacityUnit'],
            ];

            return $this->finishAndReload($manny, $probe, $result);
        }

        $target = $objectId !== '' ? $sector->findObjectById($objectId) : null;
        if ($target === null) {
            $target = ($this->findScutRelayInSector)($sector, $objectId);
        }
        if ($target === null || !($this->isSalvageableTarget)($target)) {
            $result['result'] = 'failed';
            $result['failureReason'] = 'target_unavailable';

            return $this->finishAndReload($manny, $probe, $result);
        }

        $result = array_merge($result, ($this->completeSalvageTarget)($probe, $sector, $target));

        return $this->finishAndReload($manny, $probe, $result);
    }

    /**
     * @param array<string, mixed> $resultPayload
     */
    private function finishAndReload(Manny $manny, NeumannProbe $probe, array $resultPayload): Manny
    {
        ($this->finishSalvageActor)($manny, $probe, $resultPayload);
        ($this->saveProbe)($probe);
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
