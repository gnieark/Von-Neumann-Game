<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Service\MannyActionException;

final class DropStorageContainerTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe, Manny): void $refreshOtherMannyStates
     * @param \Closure(NeumannProbe, string): mixed $findObjectInCurrentSector
     * @param \Closure(NeumannProbe): ?ProbeItem $findAtmosphericDropKit
     * @param \Closure(ProbeItem): array<string, mixed> $consumedItemPayload
     * @param \Closure(NeumannProbe, string, int): array<string, mixed> $detachAdditionalContainerSnapshot
     * @param \Closure(ProbeItem): void $deleteProbeItem
     * @param \Closure(): int $dropStorageContainerSeconds
     * @param \Closure(mixed): array<string, mixed> $targetArray
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(mixed): SectorContent $getOrCreateSector
     * @param \Closure(SectorContent): void $saveSector
     * @param \Closure(NeumannProbe, SectorContent, string, int, string, array<string, mixed>): void $handleReturnToSpaceProgramMaterialDrop
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
        private readonly \Closure $findAtmosphericDropKit,
        private readonly \Closure $consumedItemPayload,
        private readonly \Closure $detachAdditionalContainerSnapshot,
        private readonly \Closure $deleteProbeItem,
        private readonly \Closure $dropStorageContainerSeconds,
        private readonly \Closure $targetArray,
        private readonly \Closure $saveManny,
        private readonly \Closure $getOrCreateSector,
        private readonly \Closure $saveSector,
        private readonly \Closure $handleReturnToSpaceProgramMaterialDrop,
        private readonly \Closure $clearTask,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_DROPPING_STORAGE_CONTAINER;
    }

    public function start(NeumannProbe $probe, int $ownerPlayerId, string $uid, string $containerId, string $planetId): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to drop storage.');
        }

        $target = ($this->findObjectInCurrentSector)($probe, $planetId);
        if (!$target instanceof Planet) {
            throw new MannyActionException(422, 'invalid_planet_target', 'Storage containers can only be dropped on a planet in the current sector.');
        }

        $kit = ($this->findAtmosphericDropKit)($probe);
        if ($kit === null) {
            throw new MannyActionException(422, 'missing_atmospheric_drop_kit', 'An atmospheric drop kit is required in probe inventory.');
        }

        $kitPayload = ($this->consumedItemPayload)($kit);
        $snapshot = ($this->detachAdditionalContainerSnapshot)($probe, $containerId, $ownerPlayerId);
        $snapshot['items'] = array_values(array_filter(
            is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [],
            static fn(array $item): bool => ($item['uid'] ?? null) !== $kit->uid,
        ));
        ($this->deleteProbeItem)($kit);

        $detachedObjectId = SectorDetachedContainer::planetDropObjectIdForContainer((string) $snapshot['sourceContainerId']);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $durationSeconds = ($this->dropStorageContainerSeconds)();

        $manny->currentTask = Manny::TASK_DROPPING_STORAGE_CONTAINER;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'containerId' => $containerId,
            'objectId' => $detachedObjectId,
            'planetId' => $planetId,
            'targetObjectId' => $planetId,
            'durationSeconds' => $durationSeconds,
            'snapshot' => $snapshot,
            'consumedKit' => $kitPayload,
            'target' => ($this->targetArray)($target),
        ];
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $snapshot = is_array($manny->taskPayload['snapshot'] ?? null) ? $manny->taskPayload['snapshot'] : [];
        $objectId = (string) ($manny->taskPayload['objectId'] ?? SectorDetachedContainer::planetDropObjectIdForContainer((string) ($snapshot['sourceContainerId'] ?? 'storage')));
        $targetObjectId = (string) ($manny->taskPayload['targetObjectId'] ?? $manny->taskPayload['planetId'] ?? '');
        $sectorCoordinates = $probe->currentSector;
        $sector = ($this->getOrCreateSector)($sectorCoordinates);
        $containerData = is_array($snapshot['container'] ?? null) ? $snapshot['container'] : [];

        $object = new SectorDetachedContainer(
            $objectId,
            (string) ($containerData['label'] ?? 'Planet-dropped storage container'),
            SectorDetachedContainer::MODE_DROPPED_ON_PLANET,
            (int) ($snapshot['ownerProbeId'] ?? $probe->id),
            (int) ($snapshot['ownerPlayerId'] ?? $probe->playerId),
            $probe->id,
            $targetObjectId !== '' ? $targetObjectId : null,
            (float) ($containerData['capacity'] ?? 0.0),
            ProbeInventory::CAPACITY_UNIT,
            gmdate('c'),
            $snapshot + [
                'mode' => SectorDetachedContainer::MODE_DROPPED_ON_PLANET,
                'sector' => $sectorCoordinates->toArray(),
                'targetObjectId' => $targetObjectId,
                'originProbeId' => $probe->id,
                'consumedKit' => $manny->taskPayload['consumedKit'] ?? null,
            ],
            'Storage container dropped on a planet with an atmospheric descent kit.',
        );

        $sector->addPlanetDroppedContainer($object);
        ($this->handleReturnToSpaceProgramMaterialDrop)(
            $probe,
            $sector,
            $targetObjectId,
            (int) ($snapshot['ownerPlayerId'] ?? $probe->playerId),
            $object->getId(),
            is_array($snapshot['resources'] ?? null) ? $snapshot['resources'] : [],
        );
        ($this->saveSector)($sector);

        ($this->clearTask)($manny, [
            'lastTask' => Manny::TASK_DROPPING_STORAGE_CONTAINER,
            'result' => 'success',
            'objectId' => $object->getId(),
            'mode' => SectorDetachedContainer::MODE_DROPPED_ON_PLANET,
            'targetObjectId' => $targetObjectId,
        ]);
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
