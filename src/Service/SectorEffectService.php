<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\Storage\SectorEffectRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorService;

final class SectorEffectService
{
    public function __construct(private readonly SectorEffectRepository $effects, private readonly ScheduledEventRepository $events, private readonly SectorService $sectors) {}

    public function enqueue(string $operation, SectorCoordinates $sector, string $type, string $objectId, array $payload, string $now): int
    {
        if (!$this->effects->inTransaction()) { throw new \LogicException('Sector intentions must join the business transaction.'); }
        $id = $this->effects->create($operation, $sector, $type, $objectId, $payload, $now);
        $this->events->schedule('sector.effect', 'sector_effect', $id, $now);
        return $id;
    }

    public function deliverAfterCommit(int $id): void
    {
        $this->apply($id);
        $this->events->cancelPending('sector.effect', 'sector_effect', $id);
    }

    public function apply(int $id): void
    {
        if ($this->effects->inTransaction()) { throw new \LogicException('Project only committed sector intentions.'); }
        $effect = $this->effects->find($id);
        if (!$effect || $effect['status'] === 'applied') { return; }
        $coordinates = new SectorCoordinates((int) $effect['sector_x'], (int) $effect['sector_y'], (int) $effect['sector_z']);
        $attemptedId = null;
        try {
            // Share the sector decision lock with command writers. Otherwise a writer
            // could read the old file just as delivery removes its pending projection.
            $this->effects->withSectorLock($coordinates, function () use ($coordinates, $id, &$attemptedId): void {
                // An event may arrive out of order: apply earlier intentions first.
                foreach ($this->effects->pendingInSector($coordinates, $id) as $effect) {
                    $attemptedId = (int) $effect['id'];
                    $this->effects->recordAttempt($attemptedId);
                    $this->sectors->applySectorEffect($coordinates, $effect['operation_id'], $effect['effect_type'], $effect['object_id'], json_decode($effect['payload_json'], true, 512, JSON_THROW_ON_ERROR));
                    $this->effects->markApplied($attemptedId, gmdate('c'));
                }
            });
        } catch (\Throwable $error) {
            // The delivery transaction rolled back; retain the failure for the scheduler.
            if ($attemptedId !== null) {
                $this->effects->recordAttempt($attemptedId);
                $this->effects->recordFailure($attemptedId, $error->getMessage(), gmdate('c'));
            }
            throw $error;
        }
    }
}
