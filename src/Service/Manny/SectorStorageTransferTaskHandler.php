<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Service\MannyStorageTransferService;

final class SectorStorageTransferTaskHandler implements TaskHandlerInterface
{
    public function __construct(private readonly ?MannyStorageTransferService $transfers,
        private readonly \Closure $placeOnProbe, private readonly \Closure $waitForSpace,
        private readonly \Closure $clearTask, private readonly \Closure $saveManny) {}

    public function supports(?string $task): bool { return $task === MannyStorageTransferService::TASK; }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if ($manny->taskEndsAt === null || $now < new \DateTimeImmutable($manny->taskEndsAt)) { return $manny; }
        $transfer = ($this->transfers ?? throw new \RuntimeException('Storage transfer service required.'))->complete($manny->taskPayload['transferId'], $now->format('c'));
        if ($transfer['status'] === 'queued') { return $manny; }
        $result = ['lastTask' => MannyStorageTransferService::TASK, 'transferId' => $transfer['id'], 'result' => $transfer['status']];
        if (!$manny->isInSameSectorAs($probe)) { ($this->clearTask)($manny, $result); }
        elseif (!(($this->placeOnProbe)($probe, $manny))) { ($this->waitForSpace)($manny, ['reason' => 'return_to_probe'] + $result); }
        else { $manny->locationType = Manny::LOCATION_PROBE; $manny->sector = null; ($this->clearTask)($manny, $result); }
        ($this->saveManny)($manny);
        return $manny;
    }
}
