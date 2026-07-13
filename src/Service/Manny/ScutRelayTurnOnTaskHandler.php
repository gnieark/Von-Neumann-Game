<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\ScutRelay;
use VonNeumannGame\Service\MannyActionException;

final class ScutRelayTurnOnTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(): void $ensureScutRelayServiceAvailable
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe, Manny): void $refreshOtherMannyStates
     * @param \Closure(NeumannProbe): bool $currentSectorHasStar
     * @param \Closure(int): ?ScutRelay $relayById
     * @param \Closure(NeumannProbe, string): ?ProbeItem $firstItemOfType
     * @param \Closure(ProbeItem): array<string, mixed> $consumedItemPayload
     * @param \Closure(ProbeItem): void $deleteItem
     * @param \Closure(): int $scutRelayTurnOnSeconds
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(int, ?string): ScutRelay $turnOnRelay
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
        private readonly \Closure $currentSectorHasStar,
        private readonly \Closure $relayById,
        private readonly \Closure $firstItemOfType,
        private readonly \Closure $consumedItemPayload,
        private readonly \Closure $deleteItem,
        private readonly \Closure $scutRelayTurnOnSeconds,
        private readonly \Closure $saveManny,
        private readonly \Closure $turnOnRelay,
        private readonly \Closure $clearTask,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_TURNING_ON_SCUT_RELAY;
    }

    public function start(NeumannProbe $probe, string $uid, int $relayId, ?string $networkName = null): Manny
    {
        ($this->ensureScutRelayServiceAvailable)();
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to turn on a SCUT relay.');
        }
        if (!($this->currentSectorHasStar)($probe)) {
            throw new MannyActionException(422, 'scut_relay_requires_star', 'A SCUT relay needs solar energy and can only be turned on in a sector with a star.');
        }

        $relay = ($this->relayById)($relayId)
            ?? throw new MannyActionException(404, 'scut_relay_not_found', 'SCUT relay not found.');
        if (!$relay->sector->equals($probe->currentSector)) {
            throw new MannyActionException(422, 'scut_relay_not_in_sector', 'SCUT relay must be in the current sector.');
        }
        if ($relay->isOn()) {
            throw new MannyActionException(409, 'scut_relay_already_on', 'SCUT relay is already on.');
        }

        $circuit = ($this->firstItemOfType)($probe, ProbeItem::TYPE_INTEGRATED_CIRCUIT);
        if ($circuit === null) {
            throw new MannyActionException(422, 'missing_electronic_card', 'A SCUT relay requires one integrated circuit.');
        }
        ($this->deleteItem)($circuit);

        $durationSeconds = ($this->scutRelayTurnOnSeconds)();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_TURNING_ON_SCUT_RELAY;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'relayId' => $relay->id,
            'networkName' => $networkName !== null ? trim($networkName) : null,
            'durationSeconds' => $durationSeconds,
            'consumedItem' => ($this->consumedItemPayload)($circuit),
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
        $networkName = isset($manny->taskPayload['networkName']) && is_string($manny->taskPayload['networkName'])
            ? $manny->taskPayload['networkName']
            : null;
        $result = [
            'lastTask' => Manny::TASK_TURNING_ON_SCUT_RELAY,
            'relayId' => $relayId,
        ];

        try {
            $relay = ($this->turnOnRelay)($relayId, $networkName);
            $result['result'] = 'success';
            $result['networkId'] = $relay->networkId;
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
