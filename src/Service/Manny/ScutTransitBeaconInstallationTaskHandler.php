<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\ScutRelay;
use VonNeumannGame\Service\MannyActionException;

final class ScutTransitBeaconInstallationTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(): void $ensureScutRelayServiceAvailable
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe, Manny): void $refreshOtherMannyStates
     * @param \Closure(int): ?ScutRelay $relayById
     * @param \Closure(NeumannProbe, string): ?ProbeItem $firstItemOfType
     * @param \Closure(ProbeItem): array<string, mixed> $consumedItemPayload
     * @param \Closure(ProbeItem): void $deleteItem
     * @param \Closure(): int $durationSeconds
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(int): ScutRelay $installTransitBeacon
     * @param \Closure(Manny, array<string, mixed>): void $clearTask
     * @param \Closure(int): ?Manny $findMannyById
     */
    public function __construct(
        private readonly \Closure $ensureScutRelayServiceAvailable,
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $refreshOtherMannyStates,
        private readonly \Closure $relayById,
        private readonly \Closure $firstItemOfType,
        private readonly \Closure $consumedItemPayload,
        private readonly \Closure $deleteItem,
        private readonly \Closure $durationSeconds,
        private readonly \Closure $saveManny,
        private readonly \Closure $installTransitBeacon,
        private readonly \Closure $clearTask,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_INSTALLING_SCUT_TRANSIT_BEACON;
    }

    public function start(NeumannProbe $probe, string $uid, int $relayId): Manny
    {
        ($this->ensureScutRelayServiceAvailable)();
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to install a SCUT transit beacon.');
        }

        $relay = ($this->relayById)($relayId)
            ?? throw new MannyActionException(404, 'scut_relay_not_found', 'SCUT relay not found.');
        if (!$relay->sector->equals($probe->currentSector)) {
            throw new MannyActionException(422, 'scut_relay_not_in_sector', 'SCUT relay must be in the current sector.');
        }
        if (!$relay->isOn()) {
            throw new MannyActionException(422, 'scut_relay_not_active', 'SCUT relay must be active before installing a transit beacon.');
        }
        if ($relay->isTransitBeacon) {
            throw new MannyActionException(409, 'scut_transit_beacon_already_installed', 'SCUT relay already has a transit beacon.');
        }

        $beacon = ($this->firstItemOfType)($probe, ProbeItem::TYPE_SCUT_TRANSIT_BEACON);
        if ($beacon === null) {
            throw new MannyActionException(422, 'missing_scut_transit_beacon', 'A SCUT transit beacon is required.');
        }
        ($this->deleteItem)($beacon);

        $durationSeconds = ($this->durationSeconds)();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_INSTALLING_SCUT_TRANSIT_BEACON;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'relayId' => $relay->id,
            'durationSeconds' => $durationSeconds,
            'consumedItem' => ($this->consumedItemPayload)($beacon),
        ];
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }
        ($this->ensureScutRelayServiceAvailable)();

        $relayId = (int) ($manny->taskPayload['relayId'] ?? 0);
        $result = [
            'lastTask' => Manny::TASK_INSTALLING_SCUT_TRANSIT_BEACON,
            'relayId' => $relayId,
        ];

        try {
            $relay = ($this->installTransitBeacon)($relayId);
            $result['result'] = 'success';
            $result['isTransitBeacon'] = $relay->isTransitBeacon;
            $result['status'] = $relay->status;
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
