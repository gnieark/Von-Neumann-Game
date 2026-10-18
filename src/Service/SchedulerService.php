<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Database\MiningRetryPolicy;
use VonNeumannGame\Domain\ScheduledEvent;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeMovementRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\ScheduledEventLeaseLostException;
use VonNeumannGame\Service\AsteroidTrajectory\AsteroidTrajectoryPhaseProcessor;

final class SchedulerService
{
    public const PROBE_MOVEMENT_PHASE = 'probe.movement.phase';
    public const PROBE_BLACK_HOLE_TRAP = 'probe.black_hole.trap';
    public const PROBE_STORAGE_CONTAINER_BREAK = 'probe.storage_container.break';
    public const MANNY_TASK = 'manny.task';
    public const ASTEROID_TRAJECTORY_PHASE = 'asteroid.trajectory.phase';
    public const OTHERS_ACTION = 'others.action';
    public const MISSILE_PROJECTILE = 'missile.projectile';

    public function __construct(
        private readonly ScheduledEventRepository $events,
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeMovementRepository $movements,
        private readonly ProbeMovementService $movementService,
        private readonly ?MannyService $mannyService = null,
        private readonly ?AsteroidTrajectoryPhaseProcessor $asteroidTrajectoryProcessor = null,
        private readonly ?OthersService $othersService = null,
        private readonly ?SectorEffectService $sectorEffects = null,
        private readonly ?AnomalyBroadcastService $anomalyBroadcasts = null,
        private readonly MiningRetryPolicy $miningRetries = new MiningRetryPolicy(),
        private readonly ?\Closure $miningObserver = null,
    ) {}

    /**
     * @return array{due:int, processed:int, deferred:int, failed:int}
     */
    public function processDueEvents(int $limit = 100): array
    {
        $due = $this->events->findDuePending(gmdate('c'), $limit);
        $stats = [
            'due' => count($due),
            'processed' => 0,
            'deferred' => 0,
            'failed' => 0,
        ];

        foreach ($due as $event) {
            $claimed = null;
            $before = clone $event;
            $context = null;
            $retrySafe = false;
            $started = hrtime(true);
            $timings = [];
            try {
                $context = $this->events->miningContext($event);
                // Before processing, only admission and claim can have run (both SQL-only).
                $retrySafe = $context !== null;
                $result = $this->events->withAdmission($event, function () use ($event, &$claimed, &$retrySafe): ?bool {
                    $claimed = $this->events->claim($event);
                    if ($claimed === null) { return null; }
                    $retrySafe = false;
                    $done = $this->process($claimed, $retrySafe);
                    if ($done) { $this->events->markDone($claimed); }
                    return $done;
                }, $timings);
                if ($result !== null) { $stats[$result ? 'processed' : 'deferred']++; }
                if ($context !== null && $retrySafe && $result !== null) {
                    $this->observeMining('success', ['event_id' => $event->id, 'manny' => $context['manny'], 'object_id' => $context['object_id'],
                        'recovered_conflicts' => $before->conflictCount, 'completed' => $result] + $timings, $started);
                }
            } catch (\VonNeumannGame\Repository\ProbeAdmissionClosedException) {
                // The loss event owns cancellation and reservation release.
                $stats['deferred']++;
            } catch (ScheduledEventLeaseLostException $error) {
                throw $error;
            } catch (\Throwable $error) {
                if ($retrySafe && $context !== null) {
                    $conflict = MiningRetryPolicy::conflict($error, $this->events->databaseDriver());
                    if ($conflict !== null) {
                        $diagnostic = $this->events->deferMiningConflict($before, $context, $conflict, $this->miningRetries);
                        if ($diagnostic !== null) { $this->observeMining('sql_conflict', $diagnostic + $timings, $started); }
                        $stats['deferred']++;
                    } else {
                        if ($this->events->failRolledBackMining($before, $error)) {
                            $this->observeMining('permanent_failure', ['event_id' => $event->id, 'manny' => $context['manny'],
                                'object_id' => $context['object_id'], 'error_class' => get_class($error)] + $timings, $started);
                            $stats['failed']++;
                        }
                    }
                    continue;
                }
                if ($event->type === 'probe.loss') {
                    $this->events->release($event, gmdate('c', time() + 30), $event->payload);
                    error_log('Probe loss ' . $event->entityId . ' deferred: ' . $error->getMessage());
                    $stats['deferred']++;
                    continue;
                }
                // A rolled-back claim is still pending. Reclaim only to record its failure.
                $current = $this->events->findById($event->id);
                if ($current?->status === 'pending') { $claimed = $this->events->claim($current); }
                if ($claimed !== null && $current !== null) { $this->events->markFailed($claimed, $error); }
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function observeMining(string $outcome, array $data, int $started): void
    {
        $record = ['metric' => 'scheduler_mining', 'outcome' => $outcome,
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 3)] + $data;
        // Observation must never turn a committed delivery into a failed/replayed operation.
        try {
            if ($this->miningObserver !== null) { ($this->miningObserver)($record); }
            else { error_log(json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
        } catch (\Throwable) { error_log('scheduler_mining observer failed'); }
    }

    public function recoverExpiredRunningEvents(int $leaseSeconds): int
    {
        $this->events->recoverExpiredAdmissions();
        $leaseSeconds = max(60, $leaseSeconds);
        $expiredBefore = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . $leaseSeconds . ' seconds')
            ->format('c');

        return $this->events->recoverExpiredLeases($expiredBefore);
    }

    private function process(ScheduledEvent $event, bool &$retrySafe): bool
    {
        return match ($event->type) {
            'probe.loss' => $this->processProbeLoss($event),
            self::PROBE_MOVEMENT_PHASE => $this->processProbeMovementPhase($event),
            self::PROBE_BLACK_HOLE_TRAP => $this->processProbeBlackHoleTrap($event),
            self::PROBE_STORAGE_CONTAINER_BREAK => $this->processProbeStorageContainerBreak($event),
            self::MANNY_TASK => $this->processMannyTask($event, $retrySafe),
            self::ASTEROID_TRAJECTORY_PHASE => $this->processAsteroidTrajectoryPhase($event),
            'sector.effect' => $this->processSectorEffect($event),
            'anomaly.broadcast' => $this->processAnomalyBroadcast($event),
            self::OTHERS_ACTION => $this->processOthersAction($event),
            self::MISSILE_PROJECTILE => $this->processMissileProjectile($event),
            default => throw new \RuntimeException('Unsupported scheduled event type: ' . $event->type),
        };
    }

    private function processProbeLoss(ScheduledEvent $event): bool
    {
        $done = $this->movementService->resumeProbeLoss($event->entityId);
        if ($done) { return true; }
        $this->events->release($event, gmdate('c', time() + 5), $event->payload);
        return false;
    }

    private function processSectorEffect(ScheduledEvent $event): bool
    {
        if ($this->sectorEffects === null || $event->entityType !== 'sector_effect') { throw new \RuntimeException('Sector effect service unavailable or invalid event.'); }
        return $this->sectorEffects->applyScheduled($event);
    }

    private function processAnomalyBroadcast(ScheduledEvent $event): bool
    {
        if ($this->anomalyBroadcasts === null || $event->entityType !== 'anomaly_broadcast') { throw new \RuntimeException('Anomaly service unavailable or invalid event.'); }
        $this->anomalyBroadcasts->deliverPage($event->entityId);
        return true;
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

        $this->movementService->breakStorageContainerFromScheduledWarning($event->payload, $this->mannyService, $this->othersService);

        return true;
    }

    private function processMannyTask(ScheduledEvent $event, bool &$retrySafe): bool
    {
        if ($this->othersService?->processScheduledMannyMissile($event) === true) {
            return true;
        }
        if ($this->mannyService === null) {
            throw new \RuntimeException('Manny scheduler is unavailable.');
        }

        $miningResult = $this->mannyService->processScheduledMiningTask($event, $retrySafe);
        if ($miningResult !== null) { return $miningResult; }

        $manny = $this->mannyService->refreshScheduledMannyTask($event);
        if ($manny === null || $manny->currentTask === null || $manny->taskScheduledEventId !== $event->id) {
            return true;
        }

        $runAt = is_string($manny->taskPayload[Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] ?? null)
            ? $manny->taskPayload[Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY]
            : ($manny->taskEndsAt ?? ScheduledEventRepository::UNSCHEDULED_RUN_AT);
        if ($runAt <= gmdate('c')) {
            $runAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+60 seconds')
                ->format('c');
        }
        $this->events->release($event, $runAt, $manny->taskPayload);

        return false;
    }

    private function processAsteroidTrajectoryPhase(ScheduledEvent $event): bool
    {
        if ($event->entityType !== 'asteroid_trajectory' || $this->asteroidTrajectoryProcessor === null) {
            throw new \RuntimeException('Asteroid trajectory scheduler is unavailable.');
        }
        $expectedStatus = $event->payload['expectedStatus'] ?? null;
        if (!is_string($expectedStatus) || $expectedStatus === '') {
            throw new \RuntimeException('Asteroid trajectory event is missing expectedStatus.');
        }
        $expectedSectorsCrossed = $event->payload['expectedSectorsCrossed'] ?? null;
        if ($expectedSectorsCrossed !== null && !is_int($expectedSectorsCrossed)) {
            throw new \RuntimeException('Asteroid trajectory event has an invalid expectedSectorsCrossed value.');
        }
        $this->asteroidTrajectoryProcessor->process($event->entityId, $expectedStatus, $expectedSectorsCrossed);

        return true;
    }

    private function processOthersAction(ScheduledEvent $event): bool
    {
        if ($event->entityType !== 'others_action' || $this->othersService === null) {
            throw new \RuntimeException('Others action scheduler is unavailable.');
        }
        $this->othersService->processScheduledAction($event);
        return true;
    }

    private function processMissileProjectile(ScheduledEvent $event): bool
    {
        if ($event->entityType !== 'missile_projectile' || $this->othersService === null) {
            throw new \RuntimeException('Missile projectile scheduler is unavailable.');
        }
        $this->othersService->processScheduledProjectile($event);
        return true;
    }
}
