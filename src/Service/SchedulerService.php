<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\ScheduledEvent;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;

final class SchedulerService
{
    public const PROBE_MOVEMENT_PHASE = 'probe.movement.phase';
    public const PROBE_BLACK_HOLE_TRAP = 'probe.black_hole.trap';

    public function __construct(
        private readonly ScheduledEventRepository $events,
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeMovementRepository $movements,
        private readonly ProbeMovementService $movementService,
    ) {}

    /**
     * @return array{due:int, processed:int, failed:int}
     */
    public function processDueEvents(int $limit = 100): array
    {
        $due = $this->events->findDuePending(gmdate('c'), $limit);
        $stats = [
            'due' => count($due),
            'processed' => 0,
            'failed' => 0,
        ];

        foreach ($due as $event) {
            $claimed = $this->events->claim($event);
            if ($claimed === null) {
                continue;
            }

            try {
                $this->process($claimed);
                $this->events->markDone($claimed);
                $stats['processed']++;
            } catch (\Throwable $error) {
                $this->events->markFailed($claimed, $error);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function process(ScheduledEvent $event): void
    {
        match ($event->type) {
            self::PROBE_MOVEMENT_PHASE => $this->processProbeMovementPhase($event),
            self::PROBE_BLACK_HOLE_TRAP => $this->processProbeBlackHoleTrap($event),
            default => throw new \RuntimeException('Unsupported scheduled event type: ' . $event->type),
        };
    }

    private function processProbeMovementPhase(ScheduledEvent $event): void
    {
        if ($event->entityType !== 'probe_movement') {
            throw new \RuntimeException('Invalid entity type for movement event: ' . $event->entityType);
        }

        $movement = $this->movements->findById($event->entityId);
        if ($movement === null) {
            return;
        }

        $probe = $this->probes->findById($movement->probeId);
        if ($probe === null) {
            return;
        }

        $this->movementService->refreshProbeMovementState($probe);
    }

    private function processProbeBlackHoleTrap(ScheduledEvent $event): void
    {
        if ($event->entityType !== 'probe') {
            throw new \RuntimeException('Invalid entity type for black hole trap event: ' . $event->entityType);
        }

        $probe = $this->probes->findById($event->entityId);
        if ($probe === null) {
            return;
        }

        $this->movementService->trapProbeByBlackHole($probe);
    }
}
