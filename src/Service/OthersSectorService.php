<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Repository\Storage\SectorEffectRepository;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorDriftingItem;
use VonNeumannGame\Sector\SectorService;

/** SQL decisions use a locked projection; files are only changed by committed outbox workers. */
final class OthersSectorService
{
    private \WeakMap $snapshots;

    public function __construct(private readonly SectorEffectRepository $effects, private readonly SectorEffectService $outbox, private readonly SectorService $sectors)
    {
        $this->snapshots = new \WeakMap();
    }

    private function enqueue(string $operation, SectorCoordinates $sector, string $type, string $objectId, array $payload, string $now): void
    {
        $id = $this->outbox->enqueue($operation, $sector, $type, $objectId, $payload, $now);
        $this->effects->afterCommit(function () use ($id): void {
            try { $this->outbox->deliverAfterCommit($id); }
            catch (\Throwable) { /* apply recorded the failure; its durable scheduled event will retry. */ }
        });
    }

    public function getOrCreateSector(SectorCoordinates $coordinates): SectorContent
    {
        if ($this->effects->inTransaction()) { $this->effects->lockSector($coordinates); }
        $sector = $this->sectors->getOrCreateSector($coordinates);
        $this->effects->project($sector);
        $this->snapshots[$sector] = array_column($sector->toArray()['objects'], null, 'id');
        return $sector;
    }

    public function saveSector(SectorContent $sector): void
    {
        if (!$this->effects->inTransaction()) { throw new \LogicException('Others sector changes must join their SQL transaction.'); }
        $this->sectors->saveDetachedContainerChanges($sector);
        $before = $this->snapshots[$sector] ?? throw new \LogicException('Read the sector projection before changing it.');
        $after = array_column($sector->toArray()['objects'], null, 'id');
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $id) {
            if (($before[$id] ?? null) != ($after[$id] ?? null)) {
                $changes[] = ['id' => $id, 'before' => $before[$id] ?? null, 'after' => $after[$id] ?? null];
            }
        }
        if ($changes !== []) {
            $operation = 'others-sector-' . bin2hex(random_bytes(16));
            $this->enqueue($operation, $sector->getCoordinates(), 'patch_objects', '', ['changes' => $changes], gmdate('c'));
            $sector->markEffectApplied($operation);
        }
        $this->snapshots[$sector] = $after;
    }

    public function addDriftingItem(SectorCoordinates $coordinates, string $operationId, string $itemType, string $name, float $containerSpace): SectorDriftingItem
    {
        $sector = $this->getOrCreateSector($coordinates);
        $objectId = SectorDriftingItem::objectIdForItemType($itemType);
        $existing = $sector->findObjectById($objectId);
        if ($sector->hasAppliedEffect($operationId)) {
            return $existing instanceof SectorDriftingItem ? $existing : throw new \LogicException('Missing drifting item.');
        }
        if ($existing !== null && !$existing instanceof SectorDriftingItem) { throw new \LogicException('Drifting item identity is occupied.'); }
        $result = $existing instanceof SectorDriftingItem ? $existing->withQuantity($existing->getQuantity() + 1) : new SectorDriftingItem($objectId, $name, $itemType, 1, $containerSpace);
        $this->enqueue($operationId, $coordinates, 'patch_objects', $objectId, ['changes' => [['id' => $objectId, 'before' => $existing?->toArray(), 'after' => $result->toArray()]]], gmdate('c'));
        return $result;
    }
}
