<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;

interface MannyTaskRuntime
{
    public function refreshCrafting(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshWaitingForSpace(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

}
