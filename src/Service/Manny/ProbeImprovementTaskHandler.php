<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeImprovement;
use VonNeumannGame\Service\MannyActionException;

final class ProbeImprovementTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(): void $ensureProbeImprovementStorageAvailable
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(NeumannProbe, Manny): void $refreshOtherMannyStates
     * @param \Closure(string): string $normalizeProbeImprovement
     * @param \Closure(string): ?array<string, mixed> $probeImprovementDefinition
     * @param \Closure(NeumannProbe, string): ?ProbeImprovement $probeImprovementState
     * @param \Closure(NeumannProbe, array<string, mixed>): array<string, mixed> $probeImprovementPlan
     * @param \Closure(NeumannProbe, array<string, mixed>): void $consumeProbeImprovementPlan
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(NeumannProbe, string): void $markProbeImprovementDone
     * @param \Closure(Manny, array<string, mixed>): void $clearTask
     * @param \Closure(int): ?Manny $findMannyById
     */
    public function __construct(
        private readonly \Closure $ensureProbeImprovementStorageAvailable,
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $refreshOtherMannyStates,
        private readonly \Closure $normalizeProbeImprovement,
        private readonly \Closure $probeImprovementDefinition,
        private readonly \Closure $probeImprovementState,
        private readonly \Closure $probeImprovementPlan,
        private readonly \Closure $consumeProbeImprovementPlan,
        private readonly \Closure $saveManny,
        private readonly \Closure $markProbeImprovementDone,
        private readonly \Closure $clearTask,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_IMPROVING_PROBE;
    }

    public function start(NeumannProbe $probe, string $uid, string $improvement): Manny
    {
        ($this->ensureProbeImprovementStorageAvailable)();
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        ($this->refreshOtherMannyStates)($probe, $manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to improve it.');
        }

        $improvement = ($this->normalizeProbeImprovement)($improvement);
        $definition = ($this->probeImprovementDefinition)($improvement);
        if ($definition === null) {
            throw new MannyActionException(400, 'invalid_probe_improvement', 'Unknown probe improvement.');
        }

        $state = ($this->probeImprovementState)($probe, $improvement);
        if ($state === null || (!$state->available && !$state->done)) {
            throw new MannyActionException(422, 'probe_improvement_unavailable', 'This probe improvement is not available yet.');
        }
        if ($state->done) {
            throw new MannyActionException(409, 'probe_improvement_already_done', 'This probe improvement has already been completed.');
        }

        $plan = ($this->probeImprovementPlan)($probe, $definition);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        ($this->consumeProbeImprovementPlan)($probe, $plan);

        $manny->currentTask = Manny::TASK_IMPROVING_PROBE;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . (int) $plan['durationSeconds'] . ' seconds')->format('c');
        $manny->taskPayload = [
            'improvement' => $improvement,
            'improvementName' => (string) ($definition['name'] ?? $improvement),
            'durationSeconds' => (int) $plan['durationSeconds'],
            'ingredients' => is_array($definition['ingredients'] ?? null) ? $definition['ingredients'] : [],
            'resourceCosts' => is_array($plan['resourceCosts'] ?? null) ? $plan['resourceCosts'] : [],
            'consumedItems' => is_array($plan['consumedItems'] ?? null) ? $plan['consumedItems'] : [],
            'effects' => is_array($definition['effects'] ?? null) ? $definition['effects'] : [],
        ];
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }
        ($this->ensureProbeImprovementStorageAvailable)();

        $improvement = ($this->normalizeProbeImprovement)((string) ($manny->taskPayload['improvement'] ?? ''));
        $result = [
            'lastTask' => Manny::TASK_IMPROVING_PROBE,
            'improvement' => $improvement,
        ];

        if (($this->probeImprovementDefinition)($improvement) === null) {
            $result['result'] = 'failed';
            $result['failureReason'] = 'invalid_probe_improvement';
        } else {
            ($this->markProbeImprovementDone)($probe, $improvement);
            $result['result'] = 'success';
            $result['effects'] = is_array($manny->taskPayload['effects'] ?? null) ? $manny->taskPayload['effects'] : [];
        }

        ($this->clearTask)($manny, $result);
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
