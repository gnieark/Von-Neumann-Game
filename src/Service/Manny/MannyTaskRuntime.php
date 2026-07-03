<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;

interface MannyTaskRuntime
{
    public function refreshRepair(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshMining(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshCrafting(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshSalvage(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshWaypointBookmarkInstallation(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshDetachStorageContainer(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshDropStorageContainer(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshInspectSectorObject(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshDeuteriumTankRefill(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshReturning(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshWaitingForSpace(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshStorageMove(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshScutRelayTurnOn(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;

    public function refreshProbeImprovement(Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny;
}
