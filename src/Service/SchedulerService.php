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
    public const PROBE_STORAGE_CONTAINER_BREAK = 'probe.storage_container.break';
    public const MANNY_TASK = 'manny.task';

    public function __construct(
        private readonly ScheduledEventRepository $events,
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeMovementRepository $movements,
        private readonly ProbeMovementService $movementService,
        private readonly ?MannyService $mannyService = null,
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
                if ($this->process($claimed)) {
                    $this->events->markDone($claimed);
                    $stats['processed']++;
                }
            } catch (\Throwable $error) {
                $this->events->markFailed($claimed, $error);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function process(ScheduledEvent $event): bool
    {
        return match ($event->type) {
            self::PROBE_MOVEMENT_PHASE => $this->processProbeMovementPhase($event),
            self::PROBE_BLACK_HOLE_TRAP => $this->processProbeBlackHoleTrap($event),
            self::PROBE_STORAGE_CONTAINER_BREAK => $this->processProbeStorageContainerBreak($event),
            self::MANNY_TASK => $this->processMannyTask($event),
            default => throw new \RuntimeException('Unsupported scheduled event type: ' . $event->type),
        };
    }

    private function processProbeMovementPhase(ScheduledEvent $event): bool
    {
        if ($event->entityType !== 'probe_movement') {
            throw new \RuntimeException('Invalid entity type for movement event: ' . $event->entityType);
        }

        $movement = $this->movements->findById($event->entityId);
        if ($movement === null) {
            return true;
        }

        $probe = $this->probes->findById($movement->probeId);
        if ($probe === null) {
            return true;
        }

        $this->movementService->refreshProbeMovementState($probe, persistIntermediatePhase: true);

        return true;
    }

    private function processProbeBlackHoleTrap(ScheduledEvent $event): bool
    {
        if ($event->entityType !== 'probe') {
            throw new \RuntimeException('Invalid entity type for black hole trap event: ' . $event->entityType);
        }

        $probe = $this->probes->findById($event->entityId);
        if ($probe === null) {
            return true;
        }

        $this->movementService->trapProbeByBlackHole($probe);

        return true;
    }

    private function processProbeStorageContainerBreak(ScheduledEvent $event): bool
    {
        if ($event->entityType !== 'probe_damage_warning') {
            throw new \RuntimeException('Invalid entity type for storage break event: ' . $event->entityType);
        }

        $this->movementService->breakStorageContainerFromScheduledWarning($event->payload);

        return true;
    }

    private function processMannyTask(ScheduledEvent $event): bool
    {
        if ($this->mannyService === null) {
            throw new \RuntimeException('Manny scheduler is unavailable.');
        }

        $manny = $this->mannyService->refreshScheduledMannyTask($event);
        if ($manny === null || $manny->currentTask === null || $manny->taskScheduledEventId !== $event->id) {
            return true;
        }

        $runAt = $manny->taskEndsAt ?? ScheduledEventRepository::UNSCHEDULED_RUN_AT;
        if ($runAt <= gmdate('c')) {
            $runAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+60 seconds')
                ->format('c');
        }
        $this->events->release($event, $runAt, $manny->taskPayload);

        return false;
    }
}
