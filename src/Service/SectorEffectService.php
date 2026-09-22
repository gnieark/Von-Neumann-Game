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

    public function enqueue(string $operation, SectorCoordinates $sector, string $type, string $objectId, array $payload, string $now): void
    {
        if (!$this->effects->inTransaction()) { throw new \LogicException('Sector intentions must join the business transaction.'); }
        $id = $this->effects->create($operation, $sector, $type, $objectId, $payload, $now);
        $this->events->schedule('sector.effect', 'sector_effect', $id, $now);
    }

    public function apply(int $id): void
    {
        if ($this->effects->inTransaction()) { throw new \LogicException('Project only committed sector intentions.'); }
        $effect = $this->effects->find($id);
        if (!$effect || $effect['status'] === 'applied') { return; }
        $this->effects->recordAttempt($id);
        try {
            $this->sectors->applySectorEffect(new SectorCoordinates((int) $effect['sector_x'], (int) $effect['sector_y'], (int) $effect['sector_z']), $effect['operation_id'], $effect['effect_type'], $effect['object_id'], json_decode($effect['payload_json'], true, 512, JSON_THROW_ON_ERROR));
            $this->effects->markApplied($id, gmdate('c'));
        } catch (\Throwable $error) {
            $this->effects->recordFailure($id, $error->getMessage(), gmdate('c'));
            throw $error;
        }
    }
}
