<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\UniverseObject;
use VonNeumannGame\Service\MannyActionException;

final class WaypointBookmarkInstallationTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe, Manny): void $refreshOtherMannyStates
     * @param \Closure(NeumannProbe): ?ProbeItem $firstWaypointBookmarkItem
     * @param \Closure(NeumannProbe, string): UniverseObject $deployableTarget
     * @param \Closure(UniverseObject): array<string, mixed> $bookmarkTargetArray
     * @param \Closure(ProbeItem): void $deleteItem
     * @param \Closure(): int $waypointBookmarkInstallSeconds
     * @param \Closure(ProbeItem): array<string, mixed> $consumedItemPayload
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(mixed): ?SectorCoordinates $taskSectorCoordinates
     * @param \Closure(NeumannProbe, int, string, string, string, ?SectorCoordinates): UniverseObject $deployBookmarkForPlayer
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
        private readonly \Closure $firstWaypointBookmarkItem,
        private readonly \Closure $deployableTarget,
        private readonly \Closure $bookmarkTargetArray,
        private readonly \Closure $deleteItem,
        private readonly \Closure $waypointBookmarkInstallSeconds,
        private readonly \Closure $consumedItemPayload,
        private readonly \Closure $saveManny,
        private readonly \Closure $taskSectorCoordinates,
        private readonly \Closure $deployBookmarkForPlayer,
        private readonly \Closure $clearTask,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_INSTALLING_WAYPOINT_BOOKMARK;
    }

    public function start(NeumannProbe $probe, Player $player, string $uid, string $objectId, string $name): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to install a waypoint bookmark.');
        }

        $name = trim($name);
        if ($name === '' || strlen($name) > 80) {
            throw new MannyActionException(400, 'bad_request', 'Waypoint bookmark name must contain 1 to 80 characters.');
        }
        $item = ($this->firstWaypointBookmarkItem)($probe)
            ?? throw new MannyActionException(404, 'waypoint_bookmark_not_found', 'Waypoint bookmark not found in probe inventory.');
        $target = ($this->deployableTarget)($probe, $objectId);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        ($this->deleteItem)($item);

        $durationSeconds = ($this->waypointBookmarkInstallSeconds)();
        $manny->currentTask = Manny::TASK_INSTALLING_WAYPOINT_BOOKMARK;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'name' => $name,
            'durationSeconds' => $durationSeconds,
            'targetSector' => $probe->currentSector->toArray(),
            'target' => ($this->bookmarkTargetArray)($target),
            'playerId' => $player->id,
            'playerName' => $player->displayName ?? $player->username,
            'reservedItem' => ($this->consumedItemPayload)($item),
        ];
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $sectorCoordinates = ($this->taskSectorCoordinates)($manny->taskPayload['targetSector'] ?? null) ?? $probe->currentSector;
        $result = [
            'lastTask' => Manny::TASK_INSTALLING_WAYPOINT_BOOKMARK,
            'objectId' => (string) ($manny->taskPayload['objectId'] ?? ''),
            'name' => (string) ($manny->taskPayload['name'] ?? ''),
            'target' => $manny->taskPayload['target'] ?? null,
        ];

        try {
            $object = ($this->deployBookmarkForPlayer)(
                $probe,
                (int) ($manny->taskPayload['playerId'] ?? 0),
                (string) ($manny->taskPayload['playerName'] ?? ''),
                (string) ($manny->taskPayload['objectId'] ?? ''),
                (string) ($manny->taskPayload['name'] ?? ''),
                $sectorCoordinates,
            );
            $result['result'] = 'success';
            $result['object'] = $object->toArray();
        } catch (MannyActionException $e) {
            $result['result'] = 'failed';
            $result['failureReason'] = $e->errorCode;
        }

        ($this->clearTask)($manny, $result);
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
