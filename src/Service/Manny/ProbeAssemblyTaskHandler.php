<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Service\MannyActionException;

final class ProbeAssemblyTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe, Manny): void $refreshOtherMannyStates
     * @param \Closure(NeumannProbe): array<string, mixed> $probeAssemblyPlan
     * @param \Closure(NeumannProbe, list<string>): list<array<string, mixed>> $consumeEmptyAdditionalContainers
     * @param \Closure(NeumannProbe, array<string, mixed>): void $consumeProbeAssemblyPlan
     * @param \Closure(): int $probeAssemblySeconds
     * @param \Closure(): list<array{type:string,name:string,quantity:int,unit:string}> $probeAssemblyComponentRequirements
     * @param \Closure(Manny): void $releaseMannyFromStorage
     * @param \Closure(Manny): void $removeMannyFromSector
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(int): string $nextDroneProbeName
     * @param \Closure(int, string, SectorCoordinates): NeumannProbe $createProbeForPlayer
     * @param \Closure(NeumannProbe): void $ensureProbeStorage
     * @param \Closure(NeumannProbe, Manny): bool $placeMannyOnProbe
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
        private readonly \Closure $probeAssemblyPlan,
        private readonly \Closure $consumeEmptyAdditionalContainers,
        private readonly \Closure $consumeProbeAssemblyPlan,
        private readonly \Closure $probeAssemblySeconds,
        private readonly \Closure $probeAssemblyComponentRequirements,
        private readonly \Closure $releaseMannyFromStorage,
        private readonly \Closure $removeMannyFromSector,
        private readonly \Closure $saveManny,
        private readonly \Closure $nextDroneProbeName,
        private readonly \Closure $createProbeForPlayer,
        private readonly \Closure $ensureProbeStorage,
        private readonly \Closure $placeMannyOnProbe,
        private readonly \Closure $clearTask,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_ASSEMBLING_PROBE;
    }

    /**
     * @param list<string> $containerIds
     */
    public function start(NeumannProbe $probe, string $uid, array $containerIds): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to assemble a new probe.');
        }

        $plan = ($this->probeAssemblyPlan)($probe);
        $consumedContainers = ($this->consumeEmptyAdditionalContainers)($probe, $containerIds);
        ($this->consumeProbeAssemblyPlan)($probe, $plan);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $durationSeconds = ($this->probeAssemblySeconds)();
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_ASSEMBLING_PROBE;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = [
            'durationSeconds' => $durationSeconds,
            'components' => ($this->probeAssemblyComponentRequirements)(),
            'consumedItems' => is_array($plan['consumedItems'] ?? null) ? $plan['consumedItems'] : [],
            'consumedContainers' => $consumedContainers,
            'result' => 'pending',
        ];
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
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $droneName = ($this->nextDroneProbeName)($probe->playerId);
        $newProbe = ($this->createProbeForPlayer)($probe->playerId, $droneName, $manny->sector ?? $probe->currentSector);
        ($this->ensureProbeStorage)($newProbe);
        ($this->removeMannyFromSector)($manny);
        $manny->probeId = $newProbe->id;
        $manny->storageContainerId = null;
        $manny->locationType = Manny::LOCATION_PROBE;
        $manny->sector = null;
        ($this->placeMannyOnProbe)($newProbe, $manny);
        ($this->clearTask)($manny, [
            'lastTask' => Manny::TASK_ASSEMBLING_PROBE,
            'result' => 'success',
            'probe' => [
                'id' => $newProbe->id,
                'name' => $newProbe->name,
            ],
        ]);
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
