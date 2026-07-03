<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;

abstract class DelegatingTaskHandler implements TaskHandlerInterface
{
    /**
     * @return list<string>
     */
    abstract protected function taskNames(): array;

    abstract protected function runtimeMethod(): string;

    public function supports(?string $task): bool
    {
        return $task !== null && in_array($task, $this->taskNames(), true);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        $method = $this->runtimeMethod();

        return $runtime->{$method}($manny, $probe, $now);
    }
}
