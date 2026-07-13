<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Service\MannyActionException;

final class DeuteriumTransferTaskHandler implements TaskHandlerInterface
{
    public const DEUTERIUM_TRANSFER_SECONDS = 300;

    /**
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(int): ?NeumannProbe $findProbeById
     * @param \Closure(NeumannProbe): void $saveProbe
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(int): ?Manny $findMannyById
     * @param \Closure(Manny, array<string, mixed>): void $clearTask
     * @param \Closure(?NeumannProbe): float $maxDeuteriumPercent
     * @param \Closure(NeumannProbe): bool $probeAcceptsMannyOrders
     */
    public function __construct(
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $findProbeById,
        private readonly \Closure $saveProbe,
        private readonly \Closure $saveManny,
        private readonly \Closure $findMannyById,
        private readonly \Closure $clearTask,
        private readonly \Closure $maxDeuteriumPercent,
        private readonly \Closure $probeAcceptsMannyOrders,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_TRANSFERRING_DEUTERIUM_TO_PROBE;
    }

    public function start(NeumannProbe $probe, string $uid, int $targetProbeId, float $amount): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the source probe to transfer deuterium.');
        }

        if ($targetProbeId <= 0 || $targetProbeId === $probe->id) {
            throw new MannyActionException(400, 'bad_request', 'Target probe id must reference another probe.');
        }

        $targetProbe = ($this->findProbeById)($targetProbeId);
        if ($targetProbe === null) {
            throw new MannyActionException(404, 'not_found', 'Target probe not found.');
        }
        ($this->ensureProbeAcceptsMannyOrders)($targetProbe);
        if (!$targetProbe->currentSector->equals($probe->currentSector)) {
            throw new MannyActionException(422, 'probe_not_in_same_sector', 'Target probe must be in the same sector as the source probe.');
        }

        $amount = round($amount, 4);
        if ($amount <= 0.0) {
            throw new MannyActionException(400, 'bad_request', 'Transferred deuterium amount must be greater than zero.');
        }
        if ($amount + 0.00001 >= $probe->deuteriumStock) {
            throw new MannyActionException(422, 'insufficient_deuterium', 'Transferred deuterium amount must be lower than the source probe deuterium reserve.');
        }
        if ($targetProbe->deuteriumStock >= ($this->maxDeuteriumPercent)($targetProbe) - 0.0001) {
            throw new MannyActionException(409, 'probe_deuterium_full', 'The target probe deuterium tank is already full.');
        }

        $probe->deuteriumStock = round(max(0.0, $probe->deuteriumStock - $amount), 4);
        ($this->saveProbe)($probe);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_TRANSFERRING_DEUTERIUM_TO_PROBE;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . self::DEUTERIUM_TRANSFER_SECONDS . ' seconds')->format('c');
        $manny->taskPayload = [
            'durationSeconds' => self::DEUTERIUM_TRANSFER_SECONDS,
            'resourceType' => ResourceComposition::DEUTERIUM,
            'sourceProbeId' => $probe->id,
            'sourceProbeName' => $probe->name,
            'targetProbeId' => $targetProbe->id,
            'targetProbeName' => $targetProbe->name,
            'requestedAmount' => $amount,
            'reservedAmount' => $amount,
            'result' => 'pending',
        ];
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $reservedAmount = round(max(0.0, (float) ($manny->taskPayload['reservedAmount'] ?? $manny->taskPayload['requestedAmount'] ?? 0.0)), 4);
        $targetProbeId = (int) ($manny->taskPayload['targetProbeId'] ?? 0);
        $targetProbe = $targetProbeId > 0 ? ($this->findProbeById)($targetProbeId) : null;
        $result = [
            'lastTask' => Manny::TASK_TRANSFERRING_DEUTERIUM_TO_PROBE,
            'resourceType' => ResourceComposition::DEUTERIUM,
            'sourceProbeId' => $probe->id,
            'sourceProbeName' => $probe->name,
            'targetProbeId' => $targetProbeId,
            'targetProbeName' => (string) ($manny->taskPayload['targetProbeName'] ?? ''),
            'requestedAmount' => round((float) ($manny->taskPayload['requestedAmount'] ?? $reservedAmount), 4),
            'reservedAmount' => $reservedAmount,
        ];

        if (
            $reservedAmount <= 0.0
            || $targetProbe === null
            || !$targetProbe->currentSector->equals($probe->currentSector)
            || !($this->probeAcceptsMannyOrders)($targetProbe)
        ) {
            $probe->deuteriumStock = round($probe->deuteriumStock + $reservedAmount, 4);
            ($this->saveProbe)($probe);
            $result['result'] = 'failed';
            $result['failureReason'] = $targetProbe === null ? 'target_probe_not_found' : 'target_probe_unavailable';
            $result['returnedAmount'] = $reservedAmount;
            $result['transferredAmount'] = 0.0;
            ($this->clearTask)($manny, $result);
            ($this->saveManny)($manny);

            return ($this->findMannyById)($manny->id) ?? $manny;
        }

        $targetCapacity = round(max(0.0, ($this->maxDeuteriumPercent)($targetProbe) - $targetProbe->deuteriumStock), 4);
        $transferredAmount = round(min($reservedAmount, $targetCapacity), 4);
        $returnedAmount = round(max(0.0, $reservedAmount - $transferredAmount), 4);
        $targetProbe->deuteriumStock = round($targetProbe->deuteriumStock + $transferredAmount, 4);
        $probe->deuteriumStock = round($probe->deuteriumStock + $returnedAmount, 4);
        ($this->saveProbe)($targetProbe);
        ($this->saveProbe)($probe);

        $result['result'] = 'success';
        $result['targetProbeName'] = $targetProbe->name;
        $result['transferredAmount'] = $transferredAmount;
        $result['returnedAmount'] = $returnedAmount;
        $result['targetDeuterium'] = $targetProbe->deuteriumStock;
        $result['targetMaxDeuterium'] = ($this->maxDeuteriumPercent)($targetProbe);
        ($this->clearTask)($manny, $result);
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
