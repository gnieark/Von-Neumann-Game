<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Service\MannyActionException;
use VonNeumannGame\Service\ProbeStorageService;

final class RepairTaskHandler implements TaskHandlerInterface
{
    public const REPAIR_SECONDS_PER_INTEGRITY_PERCENT = 600;
    public const REPAIR_METALS_PER_INTEGRITY_PERCENT = 0.01;
    public const MAX_INTEGRITY_PERCENT = 100.0;

    /**
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     */
    public function __construct(
        private readonly MannyRepository $mannies,
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeStorageService $storage,
        private readonly array $config,
        private readonly \Closure $refreshMannyState,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_REPAIR;
    }

    public function start(NeumannProbe $probe, string $uid, float $integrityPercent): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = ($this->refreshMannyState)($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to repair it.');
        }

        $integrityPercent = round($integrityPercent, 2);
        if ($integrityPercent <= 0) {
            throw new MannyActionException(400, 'bad_request', 'Repair percent must be greater than zero.');
        }
        $missingIntegrity = round(max(0.0, $this->maxIntegrityPercent() - $probe->integrityPercent), 2);
        if ($missingIntegrity <= 0.0001) {
            throw new MannyActionException(409, 'probe_integrity_full', 'The probe integrity is already full.');
        }

        $integrityPercent = min($integrityPercent, $missingIntegrity);
        $metalsCost = round($integrityPercent * $this->repairMetalsPerIntegrityPercent(), 4);
        if ($this->storage->resourceStock($probe, ResourceComposition::METALS) + 0.00001 < $metalsCost) {
            throw new MannyActionException(422, 'insufficient_metals', 'Insufficient metals in probe inventory for this repair.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->storage->consumeResource($probe, ResourceComposition::METALS, $metalsCost);

        $manny->currentTask = Manny::TASK_REPAIR;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) ceil($integrityPercent * $this->repairSecondsPerIntegrityPercent()) . ' seconds')->format('c');
        $manny->taskPayload = [
            'integrityPercent' => $integrityPercent,
            'metalsCost' => $metalsCost,
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $integrityPercent = (float) ($manny->taskPayload['integrityPercent'] ?? 0);
        $probe->addIntegrityPercent($integrityPercent, $this->maxIntegrityPercent());
        $this->probes->save($probe);

        $this->clearTask($manny);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function requiredManny(NeumannProbe $probe, string $uid): Manny
    {
        return $this->mannies->findByUidForProbe($probe->id, $uid)
            ?? throw new MannyActionException(404, 'manny_not_found', 'Manny not found.');
    }

    private function ensureProbeAcceptsMannyOrders(NeumannProbe $probe): void
    {
        if ($probe->status === ProbeStatus::Dead) {
            throw new MannyActionException(409, 'probe_dead', 'The probe is no longer operational.');
        }
        if ($probe->status === ProbeStatus::TrappedByBlackHole) {
            throw new MannyActionException(409, 'probe_trapped_by_black_hole', 'The probe is trapped beyond a black hole escape threshold.');
        }
    }

    private function ensureMannyInRange(Manny $manny, NeumannProbe $probe): void
    {
        if (!$manny->isInSameSectorAs($probe)) {
            throw new MannyActionException(409, 'manny_out_of_range', 'The Manny is outside the probe current sector.');
        }
    }

    private function ensureMannyIdle(Manny $manny): void
    {
        if ($manny->currentTask !== null) {
            throw new MannyActionException(409, 'manny_busy', 'The Manny is already executing an order.');
        }
    }

    private function repairSecondsPerIntegrityPercent(): int
    {
        return max(1, Config::int($this->config, 'manny.actions.repairSecondsPerIntegrityPercent', self::REPAIR_SECONDS_PER_INTEGRITY_PERCENT));
    }

    private function repairMetalsPerIntegrityPercent(): float
    {
        return max(0.0, Config::float($this->config, 'manny.actions.repairMetalsPerIntegrityPercent', self::REPAIR_METALS_PER_INTEGRITY_PERCENT));
    }

    private function maxIntegrityPercent(): float
    {
        return max(0.0001, Config::float($this->config, 'probe.maxIntegrityPercent', self::MAX_INTEGRITY_PERCENT));
    }

    private function clearTask(Manny $manny): void
    {
        $manny->currentTask = null;
        $manny->taskStartedAt = null;
        $manny->taskEndsAt = null;
        $manny->taskPayload = [];
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
