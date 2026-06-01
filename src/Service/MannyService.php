<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\UniverseObject;

final class MannyService
{
    public const REPAIR_SECONDS_PER_INTEGRITY_PERCENT = 600;
    public const REPAIR_METALS_PER_INTEGRITY_PERCENT = 0.01;
    public const MINING_TRAVEL_SECONDS = 900;
    public const MINING_AMOUNT_PER_TICK = 0.01;
    public const MINING_TICK_SECONDS = 300;
    public const MANNY_CARGO_CAPACITY = 0.3;
    public const MANNY_CONTAINER_SPACE = 0.05;
    public const MOON_MASS_EARTH_UNITS = 0.0123;
    public const WAYPOINT_BOOKMARK_METALS_COST = 0.01;
    public const WAYPOINT_BOOKMARK_CONTAINER_SPACE = 0.01;
    public const WAYPOINT_BOOKMARK_CRAFTING_SECONDS = 600;

    public function __construct(
        private readonly MannyRepository $mannies,
        private readonly NeumannProbeRepository $probes,
        private readonly SectorService $sectors,
        private readonly ProbeItemRepository $items,
    ) {}

    /**
     * @return array<Manny>
     */
    public function manniesForProbe(NeumannProbe $probe): array
    {
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

    public function startRepair(NeumannProbe $probe, string $uid, float $integrityPercent): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to repair it.');
        }

        $integrityPercent = round($integrityPercent, 2);
        if ($integrityPercent <= 0) {
            throw new MannyActionException(400, 'bad_request', 'Repair percent must be greater than zero.');
        }
        $missingIntegrity = round(max(0.0, 100.0 - $probe->integrityPercent), 2);
        if ($missingIntegrity <= 0.0001) {
            throw new MannyActionException(409, 'probe_integrity_full', 'The probe integrity is already full.');
        }

        $integrityPercent = min($integrityPercent, $missingIntegrity);
        $metalsCost = round($integrityPercent * self::REPAIR_METALS_PER_INTEGRITY_PERCENT, 4);
        if ($probe->metalsStock + 0.00001 < $metalsCost) {
            throw new MannyActionException(422, 'insufficient_metals', 'Insufficient metals in probe inventory for this repair.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $probe->metalsStock = round($probe->metalsStock - $metalsCost, 4);
        $this->probes->save($probe);

        $manny->currentTask = Manny::TASK_REPAIR;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) ceil($integrityPercent * self::REPAIR_SECONDS_PER_INTEGRITY_PERCENT) . ' seconds')->format('c');
        $manny->taskPayload = [
            'integrityPercent' => $integrityPercent,
            'metalsCost' => $metalsCost,
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startMining(NeumannProbe $probe, string $uid, string $objectId, string|array $resourceTypes, float $targetAmount): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);

        try {
            $selectedResources = ResourceComposition::normalizeSelection($resourceTypes);
        } catch (\InvalidArgumentException $e) {
            throw new MannyActionException(400, 'bad_request', $e->getMessage());
        }
        $targetAmount = round($targetAmount, 4);
        if ($targetAmount <= 0) {
            throw new MannyActionException(400, 'bad_request', 'Mining target amount must be greater than zero.');
        }

        $target = $this->findObjectInCurrentSector($probe, $objectId);
        if ($target === null || !$this->isMineableObject($target)) {
            throw new MannyActionException(422, 'invalid_mining_target', 'This object cannot be mined by a Manny.');
        }

        $availableAmounts = $target instanceof Asteroid
            ? $this->availableAsteroidResourceAmountsForOrders($probe, $target)
            : null;
        $composition = $availableAmounts !== null
            ? ResourceComposition::fromAmounts($availableAmounts)
            : $this->resourceComposition($target);
        $available = ResourceComposition::availableTypes($composition);
        $unavailable = array_diff($selectedResources, $available);
        if ($unavailable !== []) {
            throw new MannyActionException(422, 'resource_unavailable', 'The requested resource is not present on this object.');
        }

        $resourceProfile = ResourceComposition::profileForSelection($composition, $selectedResources);
        if ($target instanceof Asteroid && $availableAmounts !== null) {
            $this->ensureAsteroidHasResources($availableAmounts, $resourceProfile, $targetAmount);
        }
        $storedAmount = $this->cargoAmountForResourceProfile($targetAmount, $resourceProfile);
        if ($storedAmount > $this->freeCargoCapacity($probe) + ($manny->isOnProbe() ? 0.05 : 0.0) + 0.00001) {
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
            'resourceType' => $selectedResources[0],
            'resourceTypes' => $selectedResources,
            'targetAmount' => $targetAmount,
            'depositedAmount' => 0.0,
            'depositedResources' => [],
            'extractedAmount' => 0.0,
            'extractedResources' => [],
            'availableResources' => $available,
            'resourceComposition' => $composition,
            'resourceProfile' => $resourceProfile,
            'target' => $this->miningTargetArray($target),
        ];
        $manny->cargoDeuterium = 0.0;
        $manny->cargoMetals = 0.0;
        $manny->cargoOther = 0.0;
        $this->removeMannyFromSector($manny);
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function startCrafting(NeumannProbe $probe, string $uid, string $recipe): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        $this->refreshOtherMannyStates($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to craft.');
        }

        $recipe = strtolower(str_replace([' ', '-'], '_', trim($recipe)));
        if ($recipe !== ProbeItem::TYPE_WAYPOINT_BOOKMARK) {
            throw new MannyActionException(400, 'invalid_recipe', 'Unknown crafting recipe.');
        }
        if ($probe->metalsStock + 0.00001 < self::WAYPOINT_BOOKMARK_METALS_COST) {
            throw new MannyActionException(422, 'insufficient_metals', 'Insufficient metals in probe inventory for this recipe.');
        }
        $freeAfterCost = round($this->freeCargoCapacity($probe) + self::WAYPOINT_BOOKMARK_METALS_COST, 4);
        if ($freeAfterCost + 0.00001 < self::WAYPOINT_BOOKMARK_CONTAINER_SPACE) {
            throw new MannyActionException(422, 'insufficient_cargo_capacity', 'Insufficient probe cargo capacity for the crafted item.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $probe->metalsStock = round($probe->metalsStock - self::WAYPOINT_BOOKMARK_METALS_COST, 4);
        $this->probes->save($probe);

        $manny->currentTask = Manny::TASK_CRAFTING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . self::WAYPOINT_BOOKMARK_CRAFTING_SECONDS . ' seconds')->format('c');
        $manny->taskPayload = [
            'recipe' => ProbeItem::TYPE_WAYPOINT_BOOKMARK,
            'recipeName' => ProbeItem::WAYPOINT_BOOKMARK_NAME,
            'metalsCost' => self::WAYPOINT_BOOKMARK_METALS_COST,
            'containerSpace' => self::WAYPOINT_BOOKMARK_CONTAINER_SPACE,
        ];
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function recallManny(NeumannProbe $probe, string $uid): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);

        if ($manny->currentTask === Manny::TASK_REPAIR || $manny->currentTask === Manny::TASK_CRAFTING) {
            $metalsCost = round(max(0.0, (float) ($manny->taskPayload['metalsCost'] ?? 0.0)), 4);
            if ($metalsCost > 0.0) {
                $this->transferResourceToProbe($probe, 'metals', $metalsCost);
                $this->probes->save($probe);
            }
            $this->clearTask($manny);
            $this->mannies->save($manny);

            return $this->requiredManny($probe, $uid);
        }
        if ($manny->isOnProbe()) {
            return $manny;
        }
        if ($manny->currentTask === Manny::TASK_RETURNING) {
            $this->removeMannyFromSector($manny);
            return $manny;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_RETURNING;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . self::MINING_TRAVEL_SECONDS . ' seconds')->format('c');
        $manny->taskPayload = ['reason' => 'recall'];
        $this->removeMannyFromSector($manny);
        $this->mannies->save($manny);

        return $this->requiredManny($probe, $uid);
    }

    public function jettisonMannyFromProbe(NeumannProbe $probe, string $uid): Manny
    {
        $this->ensureProbeAcceptsMannyOrders($probe);
        $manny = $this->refreshMannyState($this->requiredManny($probe, $uid), $probe);
        $this->ensureMannyInRange($manny, $probe);
        $this->ensureMannyIdle($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny is already outside the probe.');
        }

        $manny->locationType = Manny::LOCATION_SECTOR;
        $manny->sector = $probe->currentSector;
        $this->registerMannyInSector($manny, SectorManny::STATE_ABANDONED);
        $manny->probeId = null;
        $this->mannies->save($manny);

        return $this->mannies->findByUid($uid) ?? $manny;
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
        if ($manny->currentTask === Manny::TASK_CRAFTING) {
            return $this->refreshCrafting($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_RETURNING) {
            return $this->refreshReturning($manny, $probe, $now);
        }
        if ($manny->currentTask === Manny::TASK_WAITING_FOR_SPACE) {
            return $this->refreshWaitingForSpace($manny, $probe, $now);
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
            'canReceiveOrders' => $manny->probeId === $probe->id && $manny->isInSameSectorAs($probe) && $manny->currentTask === null,
        ];
    }

    private function requiredManny(NeumannProbe $probe, string $uid): Manny
    {
        return $this->mannies->findByUidForProbe($probe->id, $uid)
            ?? throw new MannyActionException(404, 'manny_not_found', 'Manny not found.');
    }

    private function refreshRepair(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $integrityPercent = (float) ($manny->taskPayload['integrityPercent'] ?? 0);
        $probe->integrityPercent = round(min(100.0, $probe->integrityPercent + $integrityPercent), 2);
        $this->probes->save($probe);

        $this->clearTask($manny);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshCrafting(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $recipe = (string) ($manny->taskPayload['recipe'] ?? '');
        if ($recipe === ProbeItem::TYPE_WAYPOINT_BOOKMARK) {
            $this->items->create(
                $probe->id,
                ProbeItem::TYPE_WAYPOINT_BOOKMARK,
                ProbeItem::WAYPOINT_BOOKMARK_NAME,
                self::WAYPOINT_BOOKMARK_CONTAINER_SPACE,
                [
                    'recipe' => ProbeItem::TYPE_WAYPOINT_BOOKMARK,
                    'craftedByMannyId' => $manny->uid,
                    'craftedByMannyName' => $manny->name,
                    'craftedAt' => $now->format('c'),
                ],
            );
        }

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
        $targetAmount = (float) ($manny->taskPayload['targetAmount'] ?? 0);
        $progress = $this->miningProgress($targetAmount, $elapsed);
        $resourceProfile = $this->miningResourceProfile($manny);
        $plannedExtracted = round(min($targetAmount, (float) $progress['deliveredAmount'] + (float) $progress['cargoAmount']), 4);
        $extracted = round((float) ($manny->taskPayload['extractedAmount'] ?? 0), 4);
        if ($plannedExtracted > $extracted) {
            $requestedDelta = round($plannedExtracted - $extracted, 4);
            $actualDelta = $this->depleteMiningTarget($manny, $resourceProfile, $requestedDelta);
            $extracted = round($extracted + $actualDelta, 4);
            $manny->taskPayload['extractedAmount'] = $extracted;
            $manny->taskPayload['extractedResources'] = $this->resourceAmountsForTotal($extracted, $resourceProfile);
            if ($actualDelta + 0.00001 < $requestedDelta) {
                $manny->taskPayload['sourceExhausted'] = true;
            }
        }

        $deposited = (float) ($manny->taskPayload['depositedAmount'] ?? 0);
        $complete = $progress['phase'] === 'complete' || $this->isAtOrAfter($now, $manny->taskEndsAt);
        $delivered = $complete ? $deposited : round(min((float) $progress['deliveredAmount'], $extracted), 4);
        if ($delivered > $deposited) {
            $deliveryAmount = round($delivered - $deposited, 4);
            if (!$this->canAcceptMiningDelivery($probe, $resourceProfile, $deliveryAmount, false)) {
                $this->setMannyCargoProfile($manny, $resourceProfile, $deliveryAmount);
                $this->waitForStorageSpace($manny, [
                    'reason' => 'cargo_delivery',
                    'pendingAmount' => $deliveryAmount,
                    'resourceProfile' => $resourceProfile,
                ]);
                $this->probes->save($probe);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }

            $this->transferMiningResourcesToProbe($probe, $resourceProfile, $deliveryAmount);
            $manny->taskPayload['depositedAmount'] = $delivered;
            $manny->taskPayload['depositedResources'] = $this->resourceAmountsForTotal((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
        }

        $cargoAmount = round(min((float) $progress['cargoAmount'], max(0.0, $extracted - $delivered)), 4);
        $this->setMannyCargoProfile($manny, $resourceProfile, $cargoAmount);
        $manny->taskPayload['phase'] = $progress['phase'];
        $manny->taskPayload['tripIndex'] = $progress['tripIndex'];

        if ($complete) {
            $remaining = round((float) ($manny->taskPayload['extractedAmount'] ?? 0) - (float) ($manny->taskPayload['depositedAmount'] ?? 0), 4);
            if (!$this->canAcceptMiningDelivery($probe, $resourceProfile, $remaining, true)) {
                $this->setMannyCargoProfile($manny, $resourceProfile, $remaining);
                $this->waitForStorageSpace($manny, [
                    'reason' => 'return_to_probe',
                    'pendingAmount' => $remaining,
                    'resourceProfile' => $resourceProfile,
                ]);
                $this->probes->save($probe);
                $this->mannies->save($manny);

                return $this->mannies->findById($manny->id) ?? $manny;
            }
            if ($remaining > 0) {
                $this->transferMiningResourcesToProbe($probe, $resourceProfile, $remaining);
                $manny->taskPayload['depositedAmount'] = round((float) ($manny->taskPayload['depositedAmount'] ?? 0) + $remaining, 4);
                $manny->taskPayload['depositedResources'] = $this->resourceAmountsForTotal((float) $manny->taskPayload['depositedAmount'], $resourceProfile);
            }
            $this->clearMannyCargo($manny);
            $this->removeMannyFromSector($manny);
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

        if (!$this->canAcceptMannyDocking($probe, $manny)) {
            $this->waitForStorageSpace($manny, ['reason' => 'return_to_probe']);
            $this->mannies->save($manny);

            return $this->mannies->findById($manny->id) ?? $manny;
        }

        $this->transferMannyCargoToProbe($manny, $probe);
        if ($manny->cargoDeuterium <= 0.0001 && $manny->cargoMetals <= 0.0001 && $manny->cargoOther <= 0.0001) {
            $this->removeMannyFromSector($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            $this->clearTask($manny);
        } else {
            $this->waitForStorageSpace($manny, ['reason' => 'cargo_delivery']);
        }
        $this->probes->save($probe);
        $this->mannies->save($manny);

        return $this->mannies->findById($manny->id) ?? $manny;
    }

    private function refreshWaitingForSpace(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->canAcceptMannyDocking($probe, $manny)) {
            return $manny;
        }

        $this->transferMannyCargoToProbe($manny, $probe);
        if ($manny->cargoDeuterium <= 0.0001 && $manny->cargoMetals <= 0.0001 && $manny->cargoOther <= 0.0001) {
            $this->removeMannyFromSector($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            $this->clearTask($manny);
        } else {
            $this->waitForStorageSpace($manny, ['reason' => 'cargo_delivery']);
        }

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

    private function refreshOtherMannyStates(NeumannProbe $probe, Manny $currentManny): void
    {
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->id === $currentManny->id || $manny->currentTask === null) {
                continue;
            }

            $this->refreshMannyState($manny, $probe);
        }
    }

    private function findObjectInCurrentSector(NeumannProbe $probe, string $objectId): ?UniverseObject
    {
        return $this->sectors->getOrCreateSector($probe->currentSector)->findObjectById($objectId);
    }

    private function isMineableObject(UniverseObject $object): bool
    {
        return $object instanceof Asteroid
            || ($object instanceof Planet && $object->getMass() <= self::MOON_MASS_EARTH_UNITS);
    }

    /**
     * @return array<string, float>
     */
    private function resourceComposition(UniverseObject $object): array
    {
        if ($object instanceof Asteroid) {
            return ResourceComposition::fromAmounts($object->getResourceAmounts());
        }

        $data = $object->toArray();

        return ResourceComposition::fromHints($data['estimatedResources'] ?? $data['resourceHints'] ?? []);
    }

    /**
     * @return array<string, float>
     */
    private function availableAsteroidResourceAmountsForOrders(NeumannProbe $probe, Asteroid $asteroid): array
    {
        $availableAmounts = $asteroid->getResourceAmounts();
        foreach ($this->mannies->findByProbeId($probe->id) as $manny) {
            if ($manny->currentTask !== Manny::TASK_MINING || ($manny->taskPayload['objectId'] ?? null) !== $asteroid->getId()) {
                continue;
            }

            $remainingTarget = round(
                max(0.0, (float) ($manny->taskPayload['targetAmount'] ?? 0.0) - (float) ($manny->taskPayload['extractedAmount'] ?? 0.0)),
                4,
            );
            if ($remainingTarget <= 0.0) {
                continue;
            }

            foreach ($this->resourceAmountsForTotal($remainingTarget, $this->miningResourceProfile($manny)) as $type => $reservedAmount) {
                $availableAmounts[$type] = round(max(0.0, (float) ($availableAmounts[$type] ?? 0.0) - $reservedAmount), 4);
            }
        }

        return $availableAmounts;
    }

    /**
     * @param array<string, float> $availableAmounts
     * @param array<string, float> $resourceProfile
     */
    private function ensureAsteroidHasResources(array $availableAmounts, array $resourceProfile, float $targetAmount): void
    {
        foreach ($this->resourceAmountsForTotal($targetAmount, $resourceProfile) as $type => $requiredAmount) {
            if ($requiredAmount > 0.0 && (float) ($availableAmounts[$type] ?? 0.0) + 0.00001 < $requiredAmount) {
                throw new MannyActionException(422, 'insufficient_target_resources', 'The asteroid does not contain enough of the requested material.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function miningTargetArray(UniverseObject $object): array
    {
        $data = $object->toArray();
        $resources = $data['estimatedResources'] ?? $data['resourceHints'] ?? [];
        $composition = $this->resourceComposition($object);

        $target = [
            'id' => $object->getId(),
            'type' => $object->getType()->value,
            'name' => $object->getName(),
            'mass' => $object->getMass(),
            'radius' => $object->getRadius(),
            'resources' => $resources,
            'resourceTypes' => ResourceComposition::availableTypes($composition),
            'resourceComposition' => $composition,
        ];

        if ($object instanceof Asteroid) {
            $target['composition'] = $data['composition'] ?? null;
            $target['sizeCategory'] = $data['sizeCategory'] ?? null;
            $target['resourceAmounts'] = $object->getResourceAmounts();
        }
        if ($object instanceof Planet) {
            $target['category'] = $object->getCategory();
        }

        return $target;
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

    /**
     * @param array<string, float> $profile
     */
    private function depleteMiningTarget(Manny $manny, array $profile, float $amount): float
    {
        $amount = round(max(0.0, $amount), 4);
        $objectId = (string) ($manny->taskPayload['objectId'] ?? '');
        if ($amount <= 0.0 || $objectId === '' || $manny->sector === null) {
            return $amount;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $object = $sector->findObjectById($objectId);
        if (!$object instanceof Asteroid) {
            return $amount;
        }

        $currentAmounts = $object->getResourceAmounts();
        $requestedAmounts = $this->resourceAmountsForTotal($amount, $profile);
        $actualAmount = $this->extractableTotalAmount($currentAmounts, $requestedAmounts, $amount);
        if ($actualAmount <= 0.0) {
            return 0.0;
        }

        $extractedAmounts = $this->resourceAmountsForTotal($actualAmount, $profile);
        foreach ($extractedAmounts as $type => $extractedAmount) {
            $currentAmounts[$type] = round(max(0.0, (float) ($currentAmounts[$type] ?? 0.0) - $extractedAmount), 4);
        }

        if ($sector->replaceObject($object->withResourceAmounts($currentAmounts))) {
            $this->sectors->saveSector($sector);
        }

        return $actualAmount;
    }

    /**
     * @param array<string, float> $availableAmounts
     * @param array<string, float> $requestedAmounts
     */
    private function extractableTotalAmount(array $availableAmounts, array $requestedAmounts, float $requestedTotal): float
    {
        $ratio = 1.0;
        $hasRequestedResource = false;
        foreach ($requestedAmounts as $type => $requestedAmount) {
            if ($requestedAmount <= 0.0) {
                continue;
            }

            $hasRequestedResource = true;
            $ratio = min($ratio, max(0.0, (float) ($availableAmounts[$type] ?? 0.0)) / $requestedAmount);
        }

        if (!$hasRequestedResource) {
            return round(max(0.0, $requestedTotal), 4);
        }

        return round(max(0.0, min(1.0, $ratio)) * max(0.0, $requestedTotal), 4);
    }

    private function canAcceptMannyDocking(NeumannProbe $probe, Manny $manny): bool
    {
        $requiredStorage = round(self::MANNY_CONTAINER_SPACE + $manny->cargoMetals + $manny->cargoOther, 4);

        return $this->freeCargoCapacity($probe) + 0.00001 >= $requiredStorage;
    }

    /**
     * @param array<string, float> $profile
     */
    private function canAcceptMiningDelivery(NeumannProbe $probe, array $profile, float $amount, bool $includeManny): bool
    {
        $amounts = $this->resourceAmountsForTotal($amount, $profile);
        $requiredStorage = round(
            $this->cargoAmountForResources($amounts)
            + ($includeManny ? self::MANNY_CONTAINER_SPACE : 0.0),
            4,
        );

        return $this->freeCargoCapacity($probe) + 0.00001 >= $requiredStorage;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function waitForStorageSpace(Manny $manny, array $payload = []): void
    {
        if ($manny->sector === null) {
            return;
        }

        $manny->currentTask = Manny::TASK_WAITING_FOR_SPACE;
        $manny->taskStartedAt ??= gmdate('c');
        $manny->taskEndsAt = null;
        $manny->taskPayload = array_merge($manny->taskPayload, [
            'reason' => 'return_to_probe',
            'waitingFor' => 'storage_space',
        ], $payload);
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

    /**
     * @param array<string, float> $profile
     */
    private function transferMiningResourcesToProbe(NeumannProbe $probe, array $profile, float $amount): void
    {
        $amounts = $this->resourceAmountsForTotal($amount, $profile);
        foreach ($amounts as $type => $resourceAmount) {
            $this->transferResourceToProbe($probe, $type, $resourceAmount);
        }
    }

    private function freeCargoCapacity(NeumannProbe $probe): float
    {
        $used = ProbeInventory::defaultForProbe(
            $probe,
            $this->mannies->findByProbeId($probe->id),
            $this->items->findByProbeId($probe->id),
        )->usedCapacity();

        return max(0.0, round(1.0 - $used, 4));
    }

    /**
     * @param array<string, float> $profile
     */
    private function setMannyCargoProfile(Manny $manny, array $profile, float $amount): void
    {
        $this->clearMannyCargo($manny);
        $amounts = $this->resourceAmountsForTotal($amount, $profile);
        $manny->cargoDeuterium = $amounts[ResourceComposition::DEUTERIUM] ?? 0.0;
        $manny->cargoMetals = $amounts[ResourceComposition::METALS] ?? 0.0;
        $manny->cargoOther = round(
            ($amounts[ResourceComposition::ICE] ?? 0.0)
            + ($amounts[ResourceComposition::CARBON_COMPOUNDS] ?? 0.0),
            4,
        );
    }

    /**
     * @return array<string, float>
     */
    private function miningResourceProfile(Manny $manny): array
    {
        $profile = $manny->taskPayload['resourceProfile'] ?? null;
        if (is_array($profile)) {
            $normalized = array_fill_keys(ResourceComposition::TYPES, 0.0);
            foreach (ResourceComposition::TYPES as $type) {
                $normalized[$type] = round(max(0.0, (float) ($profile[$type] ?? 0.0)), 4);
            }
            $normalized[ResourceComposition::CARBON_COMPOUNDS] = round(
                $normalized[ResourceComposition::CARBON_COMPOUNDS] + max(0.0, (float) ($profile['other'] ?? 0.0)),
                4,
            );

            if (array_sum($normalized) > 0.0) {
                return $normalized;
            }
        }

        $resourceType = (string) ($manny->taskPayload['resourceType'] ?? 'metals');
        try {
            $resourceType = ResourceComposition::normalizeSelection($resourceType)[0];
        } catch (\InvalidArgumentException) {
            $resourceType = 'metals';
        }

        $legacyProfile = array_fill_keys(ResourceComposition::TYPES, 0.0);
        $legacyProfile[$resourceType] = 1.0;

        return $legacyProfile;
    }

    /**
     * @param array<string, float> $profile
     * @return array<string, float>
     */
    private function resourceAmountsForTotal(float $amount, array $profile): array
    {
        $amount = round(max(0.0, $amount), 4);
        $positiveTypes = array_values(array_filter(
            ResourceComposition::TYPES,
            static fn(string $type): bool => (float) ($profile[$type] ?? 0.0) > 0.0,
        ));
        if ($positiveTypes === []) {
            return array_fill_keys(ResourceComposition::TYPES, 0.0);
        }

        $amounts = array_fill_keys(ResourceComposition::TYPES, 0.0);
        $remaining = $amount;
        $lastIndex = count($positiveTypes) - 1;
        foreach ($positiveTypes as $index => $type) {
            if ($index === $lastIndex) {
                $amounts[$type] = round(max(0.0, $remaining), 4);
                break;
            }

            $resourceAmount = round($amount * (float) ($profile[$type] ?? 0.0), 4);
            $amounts[$type] = $resourceAmount;
            $remaining = round($remaining - $resourceAmount, 4);
        }

        return $amounts;
    }

    /**
     * @param array<string, float> $profile
     */
    private function cargoAmountForResourceProfile(float $amount, array $profile): float
    {
        return $this->cargoAmountForResources($this->resourceAmountsForTotal($amount, $profile));
    }

    /**
     * @param array<string, float> $amounts
     */
    private function cargoAmountForResources(array $amounts): float
    {
        $total = 0.0;
        foreach (ResourceComposition::TYPES as $type) {
            if ($type === ResourceComposition::DEUTERIUM) {
                continue;
            }

            $total += max(0.0, (float) ($amounts[$type] ?? 0.0));
        }

        return round($total, 4);
    }

    private function clearMannyCargo(Manny $manny): void
    {
        $manny->cargoDeuterium = 0.0;
        $manny->cargoMetals = 0.0;
        $manny->cargoOther = 0.0;
    }

    private function registerMannyInSector(Manny $manny, string $state): void
    {
        if ($manny->sector === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $object = new SectorManny(
            SectorManny::objectIdForUid($manny->uid),
            $manny->name,
            $manny->uid,
            $state,
            $manny->cargoArray(),
            $state === SectorManny::STATE_FORGOTTEN
                ? 'Manny left behind by its probe.'
                : 'Manny abandoned in open space.',
        );

        if (!$sector->replaceObject($object)) {
            $sector->addObject($object);
        }
        $this->sectors->saveSector($sector);
    }

    private function removeMannyFromSector(Manny $manny): void
    {
        if ($manny->sector === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        if ($sector->removeObjectById(SectorManny::objectIdForUid($manny->uid))) {
            $this->sectors->saveSector($sector);
        }
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
