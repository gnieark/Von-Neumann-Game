<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;

final class MannyTaskRefresher
{
    private bool $allowOutOfRangeTasks = false;

    /**
     * @param list<TaskHandlerInterface> $handlers
     * @param \Closure(NeumannProbe, callable): mixed $withProbeLock
     * @param callable(int): ?Manny $findMannyById
     * @param callable(NeumannProbe, Manny): bool $canRefreshFromProbe
     */
    public function __construct(
        private readonly array $handlers,
        private readonly MannyTaskRuntime $runtime,
        private readonly \Closure $withProbeLock,
        private readonly \Closure $findMannyById,
        private readonly \Closure $canRefreshFromProbe,
    ) {
    }

    public function refresh(Manny $manny, NeumannProbe $probe, bool $enforceVisibilityGate): Manny
    {
        if ($manny->currentTask === null) {
            return $manny;
        }
        if ($enforceVisibilityGate && !$this->canRefreshFromProbe($probe, $manny)) {
            return $manny;
        }

        $handler = $this->handlerFor($manny->currentTask);
        if ($handler === null) {
            return $manny;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return ($this->withProbeLock)($probe, function (NeumannProbe $lockedProbe) use ($manny, $handler, $now): Manny {
            $fresh = ($this->findMannyById)($manny->id) ?? $manny;
            if ($fresh->currentTask === null || !$handler->supports($fresh->currentTask)) {
                return $fresh;
            }
            if (!$this->allowOutOfRangeTasks && !$this->canRefreshFromProbe($lockedProbe, $fresh)) {
                return $fresh;
            }

            return $handler->refresh($this->runtime, $fresh, $lockedProbe, $now);
        });
    }

    public function refreshAllowingOutOfRange(Manny $manny, NeumannProbe $probe): Manny
    {
        $previous = $this->allowOutOfRangeTasks;
        $this->allowOutOfRangeTasks = true;
        try {
            return $this->refresh($manny, $probe, false);
        } finally {
            $this->allowOutOfRangeTasks = $previous;
        }
    }

    public function allowsOutOfRangeTasks(): bool
    {
        return $this->allowOutOfRangeTasks;
    }

    private function canRefreshFromProbe(NeumannProbe $probe, Manny $manny): bool
    {
        return ($this->canRefreshFromProbe)($probe, $manny);
    }

    private function handlerFor(?string $task): ?TaskHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($task)) {
                return $handler;
            }
        }

        return null;
    }
}
