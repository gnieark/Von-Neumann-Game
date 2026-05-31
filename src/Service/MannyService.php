<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SolarSystem;
use VonNeumannGame\Sector\UniverseObject;

final class MannyService
{
    public const REPAIR_SECONDS_PER_DAMAGE_PERCENT = 600;
    public const REPAIR_METALS_PER_DAMAGE_PERCENT = 0.01;
    public const MINING_TRAVEL_SECONDS = 900;
    public const MINING_AMOUNT_PER_TICK = 0.01;
    public const MINING_TICK_SECONDS = 300;
    public const MANNY_CARGO_CAPACITY = 0.3;
    public const MOON_MASS_EARTH_UNITS = 0.0123;

    public function __construct(
        private readonly MannyRepository $mannies,
        private readonly NeumannProbeRepository $probes,
        private readonly SectorService $sectors,
    ) {}

    /**
     * @return array<Manny>
     */
    public function manniesForProbe(NeumannProbe $probe): array
    {
        $this->mannies->ensureDefaultsForProbe($probe);
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            $this->refreshMannyState($manny, $probe);
        }

        return $this->mannies->findByProbeId($probe->id);
    }

    public function renameManny(NeumannProbe $probe, string $uid, string $name): Manny
    {
        $manny = $this->requiredManny($probe, $uid);
        $name = trim($name);
        if ($name === '' || strlen($name) > 40) {
            throw new MannyActionException(400, 'bad_request', 'Manny name must contain 1 to 40 characters.');
        }
        if ($this->mannies->nameExistsForProbe($probe->id, $name, $manny->id)) {
            throw new MannyActionException(409, 'duplicate_manny_name', 'Manny names must be unique for this probe.');
        }

        $manny->name = $name;
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startRepair(NeumannProbe $probe, string $uid, float $damagePercent): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to repair it.');
        }

        $damagePercent = round($damagePercent, 2);
        if ($damagePercent <= 0) {
            throw new MannyActionException(400, 'bad_request', 'Repair percent must be greater than zero.');
        }
        if ($probe->damagePercent <= 0.0001) {
            throw new MannyActionException(409, 'no_probe_damage', 'The probe has no damage to repair.');
        }

        $damagePercent = min($damagePercent, $probe->damagePercent);
        $metalsCost = round($damagePercent * self::REPAIR_METALS_PER_DAMAGE_PERCENT, 4);
        if ($probe->metalsStock + 0.00001 < $metalsCost) {
            throw new MannyActionException(422, 'insufficient_metals', 'Insufficient metals in probe inventory for this repair.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $probe->metalsStock = round($probe->metalsStock - $metalsCost, 4);
        $this->probes->save($probe);

        $manny->currentTask = Manny::TASK_REPAIR;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) ceil($damagePercent * self::REPAIR_SECONDS_PER_DAMAGE_PERCENT) . ' seconds')->format('c');
        $manny->taskPayload = [
            'damagePercent' => $damagePercent,
            'metalsCost' => $metalsCost,
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startMining(NeumannProbe $probe, string $uid, string $objectId, string $resourceType, float $targetAmount): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);

        $resourceType = strtolower(trim($resourceType));
        if (!in_array($resourceType, ['deuterium', 'metals', 'other'], true)) {
            throw new MannyActionException(400, 'bad_request', 'Mining resource must be deuterium, metals or other.');
        }
        $targetAmount = round($targetAmount, 4);
        if ($targetAmount <= 0) {
            throw new MannyActionException(400, 'bad_request', 'Mining target amount must be greater than zero.');
        }

        $target = $this->findObjectInCurrentSector($probe, $objectId);
        if ($target === null || !$this->isMineableObject($target)) {
            throw new MannyActionException(422, 'invalid_mining_target', 'This object cannot be mined by a Manny.');
        }

        $available = $this->availableResourceTypes($target);
        if (!in_array($resourceType, $available, true)) {
            throw new MannyActionException(422, 'resource_unavailable', 'The requested resource is not present on this object.');
        }
        if ($resourceType !== 'deuterium' && $targetAmount > $this->freeCargoCapacity($probe) + ($manny->isOnProbe() ? 0.05 : 0.0) + 0.00001) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for this mining target.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $manny->currentTask = Manny::TASK_MINING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $this->miningDurationSeconds($targetAmount) . ' seconds')->format('c');
        $manny->taskPayload = [
            'objectId' => $objectId,
            'resourceType' => $resourceType,
            'targetAmount' => $targetAmount,
            'depositedAmount' => 0.0,
            'availableResources' => $available,
        ];
        $manny->cargoDeuterium = 0.0;
        $manny->cargoMetals = 0.0;
        $manny->cargoOther = 0.0;
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function recallManny(NeumannProbe $probe, string $uid): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);

        if ($manny->isOnProbe()) {
            return $manny;
        }
        if ($manny->currentTask === Manny::TASK_RETURNING) {
            return $manny;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_RETURNING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . self::MINING_TRAVEL_SECONDS . ' seconds')->format('c');
        $manny->taskPayload = ['reason' => 'recall'];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function refreshMannyState(Manny $manny, NeumannProbe $probe): Manny
    {
        if ($manny->currentTask === null) {
            return $manny;
        }
        if (!$manny->isInSameSectorAs($probe)) {
            return $manny;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($manny->currentTask === Manny::TASK_REPAIR) {
            return $this->refreshRepair($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_MINING) {
            return $this->refreshMining($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_RETURNING) {
            return $this->refreshReturning($manny, $probe, $now);
        }

        return $manny;
    }

    public function publicArray(NeumannProbe $probe, Manny $manny, ?array $relativeSector = null): array
    {
        return [
            'id' => $manny->uid,
            'name' => $manny->name,
            'location' => $manny->isOnProbe()
                ? ['type' => Manny::LOCATION_PROBE]
                : ['type' => Manny::LOCATION_SECTOR, 'sector' => ['relative' => $relativeSector]],
            'currentTask' => $manny->currentTask,
            'taskProgressPercent' => $manny->taskProgressPercent(),
            'task' => $manny->taskPayload,
            'cargo' => $manny->cargoArray(),
            'canReceiveOrders' => $manny->isInSameSectorAs($probe) && $manny->currentTask === null,
        ];
    }

    private function requiredManny(NeumannProbe $probe, string $uid): Manny
    {
        $this->mannies->ensureDefaultsForProbe($probe);
        return $this->mannies->findByUidForProbe($probe->id, $uid)
            ?? throw new MannyActionException(404, 'manny_not_found', 'Manny not found.');
    }

    private function refreshRepair(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $damagePercent = (float) ($manny->taskPayload['damagePercent'] ?? 0);
        $probe->damagePercent = round(max(0.0, $probe->damagePercent - $damagePercent), 2);
        $probe->integrityPercent = round(max(0.0, 100.0 - $probe->damagePercent), 2);
        $this->probes->save($probe);

        $this->clearTask($manny);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshMining(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if ($manny->taskStartedAt === null) {
            return $manny;
        }

        $elapsed = max(0, $now->getTimestamp() - (new \DateTimeImmutable($manny->taskStartedAt))->getTimestamp());
        $progress = $this->miningProgress((float) ($manny->taskPayload['targetAmount'] ?? 0), $elapsed);
        $resourceType = (string) ($manny->taskPayload['resourceType'] ?? 'metals');
        $deposited = (float) ($manny->taskPayload['depositedAmount'] ?? 0);
        $delivered = round((float) $progress['deliveredAmount'], 4);
        if ($delivered > $deposited) {
            $accepted = $this->transferResourceToProbe($probe, $resourceType, round($delivered - $deposited, 4));
            $manny->taskPayload['depositedAmount'] = round($deposited + $accepted, 4);
        }

        $this->setMannyCargo($manny, $resourceType, (float) $progress['cargoAmount']);
        $manny->taskPayload['phase'] = $progress['phase'];
        $manny->taskPayload['tripIndex'] = $progress['tripIndex'];

        if ($progress['phase'] === 'complete' || $this->isAtOrAfter($now, $manny->taskEndsAt)) {
            $remaining = round((float) ($manny->taskPayload['targetAmount'] ?? 0) - (float) ($manny->taskPayload['depositedAmount'] ?? 0), 4);
            if ($remaining > 0) {
                $accepted = $this->transferResourceToProbe($probe, $resourceType, $remaining);
                $manny->taskPayload['depositedAmount'] = round((float) ($manny->taskPayload['depositedAmount'] ?? 0) + $accepted, 4);
            }
            $this->clearMannyCargo($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            $this->clearTask($manny);
        }

        $this->probes->save($probe);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshReturning(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $this->transferMannyCargoToProbe($manny, $probe);
        if ($manny->cargoDeuterium <= 0.0001 && $manny->cargoMetals <= 0.0001 && $manny->cargoOther <= 0.0001) {
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
        }
        $this->clearTask($manny);
        $this->probes->save($probe);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
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

    private function findObjectInCurrentSector(NeumannProbe $probe, string $objectId): ?UniverseObject
    {
        foreach ($this->sectors->getOrCreateSector($probe->currentSector)->getObjects() as $object) {
            if ($object->getId() === $objectId) {
                return $object;
            }
            if ($object instanceof SolarSystem) {
                foreach ($object->getOrbitalBodies() as $body) {
                    if ($body->getObject()->getId() === $objectId) {
                        return $body->getObject();
                    }
                }
            }
        }

        return null;
    }

    private function isMineableObject(UniverseObject $object): bool
    {
        return ($object instanceof Asteroid || $object instanceof Planet)
            && $object->getMass() <= self::MOON_MASS_EARTH_UNITS;
    }

    /**
     * @return array<string>
     */
    private function availableResourceTypes(UniverseObject $object): array
    {
        $data = $object->toArray();
        $resources = $data['estimatedResources'] ?? $data['resourceHints'] ?? [];
        $types = [];
        foreach ($resources as $resource) {
            $resource = strtolower((string) $resource);
            if (str_contains($resource, 'water') || str_contains($resource, 'ice') || str_contains($resource, 'volatile') || str_contains($resource, 'hydrogen')) {
                $types[] = 'deuterium';
                continue;
            }
            if (str_contains($resource, 'iron') || str_contains($resource, 'nickel') || str_contains($resource, 'metal') || str_contains($resource, 'platinum') || str_contains($resource, 'magnesium')) {
                $types[] = 'metals';
                continue;
            }
            $types[] = 'other';
        }

        return array_values(array_unique($types === [] ? ['other'] : $types));
    }

    private function miningDurationSeconds(float $targetAmount): int
    {
        $remaining = round($targetAmount, 4);
        $duration = 0;
        while ($remaining > 0.0001) {
            $tripAmount = min(self::MANNY_CARGO_CAPACITY, $remaining);
            $duration += self::MINING_TRAVEL_SECONDS;
            $duration += (int) ceil($tripAmount / self::MINING_AMOUNT_PER_TICK) * self::MINING_TICK_SECONDS;
            $duration += self::MINING_TRAVEL_SECONDS;
            $remaining = round($remaining - $tripAmount, 4);
        }

        return $duration;
    }

    private function miningProgress(float $targetAmount, int $elapsedSeconds): array
    {
        $remaining = round($targetAmount, 4);
        $cursor = 0;
        $delivered = 0.0;
        $tripIndex = 1;
        while ($remaining > 0.0001) {
            $tripAmount = min(self::MANNY_CARGO_CAPACITY, $remaining);
            $outboundEnd = $cursor + self::MINING_TRAVEL_SECONDS;
            if ($elapsedSeconds < $outboundEnd) {
                return ['phase' => 'outbound', 'tripIndex' => $tripIndex, 'deliveredAmount' => $delivered, 'cargoAmount' => 0.0];
            }

            $miningTicks = (int) ceil($tripAmount / self::MINING_AMOUNT_PER_TICK);
            $miningEnd = $outboundEnd + ($miningTicks * self::MINING_TICK_SECONDS);
            if ($elapsedSeconds < $miningEnd) {
                $ticksDone = (int) floor(($elapsedSeconds - $outboundEnd) / self::MINING_TICK_SECONDS);
                $cargo = min($tripAmount, $ticksDone * self::MINING_AMOUNT_PER_TICK);

                return ['phase' => 'mining', 'tripIndex' => $tripIndex, 'deliveredAmount' => $delivered, 'cargoAmount' => round($cargo, 4)];
            }

            $returnEnd = $miningEnd + self::MINING_TRAVEL_SECONDS;
            if ($elapsedSeconds < $returnEnd) {
                return ['phase' => 'returning', 'tripIndex' => $tripIndex, 'deliveredAmount' => $delivered, 'cargoAmount' => round($tripAmount, 4)];
            }

            $delivered = round($delivered + $tripAmount, 4);
            $remaining = round($remaining - $tripAmount, 4);
            $cursor = $returnEnd;
            $tripIndex++;
        }

        return ['phase' => 'complete', 'tripIndex' => max(1, $tripIndex - 1), 'deliveredAmount' => round($targetAmount, 4), 'cargoAmount' => 0.0];
    }

    private function transferMannyCargoToProbe(Manny $manny, NeumannProbe $probe): void
    {
        $manny->cargoDeuterium = round($manny->cargoDeuterium - $this->transferResourceToProbe($probe, 'deuterium', $manny->cargoDeuterium), 4);
        $manny->cargoMetals = round($manny->cargoMetals - $this->transferResourceToProbe($probe, 'metals', $manny->cargoMetals), 4);
        $manny->cargoOther = round($manny->cargoOther - $this->transferResourceToProbe($probe, 'other', $manny->cargoOther), 4);
    }

    private function transferResourceToProbe(NeumannProbe $probe, string $resourceType, float $amount): float
    {
        $amount = round(max(0.0, $amount), 4);
        if ($amount <= 0.0) {
            return 0.0;
        }

        if ($resourceType === 'deuterium') {
            $before = $probe->deuteriumStock;
            $probe->deuteriumStock = round(min(100.0, $probe->deuteriumStock + ($amount * 100.0)), 4);

            return round(($probe->deuteriumStock - $before) / 100.0, 4);
        }

        $accepted = min($amount, $this->freeCargoCapacity($probe));
        if ($resourceType === 'metals') {
            $probe->metalsStock = round($probe->metalsStock + $accepted, 4);
        } else {
            $probe->otherStock = round($probe->otherStock + $accepted, 4);
        }

        return round($accepted, 4);
    }

    private function freeCargoCapacity(NeumannProbe $probe): float
    {
        $used = ProbeInventory::defaultForProbe($probe, $this->mannies->findByProbeId($probe->id))->usedCapacity();

        return max(0.0, round(1.0 - $used, 4));
    }

    private function setMannyCargo(Manny $manny, string $resourceType, float $amount): void
    {
        $this->clearMannyCargo($manny);
        if ($resourceType === 'deuterium') {
            $manny->cargoDeuterium = round($amount, 4);
        } elseif ($resourceType === 'metals') {
            $manny->cargoMetals = round($amount, 4);
        } else {
            $manny->cargoOther = round($amount, 4);
        }
    }

    private function clearMannyCargo(Manny $manny): void
    {
        $manny->cargoDeuterium = 0.0;
        $manny->cargoMetals = 0.0;
        $manny->cargoOther = 0.0;
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
