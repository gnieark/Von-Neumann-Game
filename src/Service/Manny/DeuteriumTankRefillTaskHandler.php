<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Service\MannyActionException;

final class DeuteriumTankRefillTaskHandler implements TaskHandlerInterface
{
    public const DEUTERIUM_TANK_REFILL_SECONDS = 60;

    /**
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(NeumannProbe): void $completeReadyReturnToSpacePrograms
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe): bool $currentSectorHasDeuteriumRefuelStation
     * @param \Closure(?NeumannProbe): float $maxDeuteriumPercent
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(NeumannProbe): void $saveProbe
     * @param \Closure(Manny, array<string, mixed>): void $clearTask
     * @param \Closure(int): ?Manny $findMannyById
     */
    public function __construct(
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $completeReadyReturnToSpacePrograms,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $currentSectorHasDeuteriumRefuelStation,
        private readonly \Closure $maxDeuteriumPercent,
        private readonly \Closure $saveManny,
        private readonly \Closure $saveProbe,
        private readonly \Closure $clearTask,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_REFILLING_DEUTERIUM_TANK;
    }

    public function start(NeumannProbe $probe, string $uid): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        ($this->completeReadyReturnToSpacePrograms)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to refill its deuterium tank.');
        }
        if (!($this->currentSectorHasDeuteriumRefuelStation)($probe)) {
            throw new MannyActionException(422, 'deuterium_refuel_station_not_found', 'No deuterium refuel station is available in the current sector.');
        }
        if ($probe->deuteriumStock >= ($this->maxDeuteriumPercent)($probe) - 0.0001) {
            throw new MannyActionException(409, 'probe_deuterium_full', 'The probe deuterium tank is already full.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_REFILLING_DEUTERIUM_TANK;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . self::DEUTERIUM_TANK_REFILL_SECONDS . ' seconds')->format('c');
        $manny->taskPayload = [
            'durationSeconds' => self::DEUTERIUM_TANK_REFILL_SECONDS,
            'resourceType' => ResourceComposition::DEUTERIUM,
        ];
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $probe->deuteriumStock = ($this->maxDeuteriumPercent)($probe);
        ($this->saveProbe)($probe);
        ($this->clearTask)($manny, [
            'lastTask' => Manny::TASK_REFILLING_DEUTERIUM_TANK,
            'result' => 'success',
            'resourceType' => ResourceComposition::DEUTERIUM,
        ]);
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
