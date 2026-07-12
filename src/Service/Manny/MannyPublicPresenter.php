<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\ScutNetworkService;

final class MannyPublicPresenter
{
    private readonly \Closure $cargoArray;

    public function __construct(
        private readonly ?ScutNetworkService $scut,
        callable $cargoArray,
    ) {
        $this->cargoArray = \Closure::fromCallable($cargoArray);
    }

    /**
     * @param array{x:int,y:int,z:int}|null $relativeSector
     * @return array<string, mixed>
     */
    public function present(NeumannProbe $probe, Manny $manny, ?array $relativeSector = null): array
    {
        $taskVisibility = $this->taskVisibilityFor($probe, $manny);
        $currentTask = $manny->currentTask;
        $taskProgressPercent = $manny->taskProgressPercent();
        $taskEstimatedEndTime = $manny->taskEndsAt;
        $task = $this->publicTaskPayload($manny);
        if ($taskVisibility === MannyService::TASK_VISIBILITY_TOO_FAR) {
            if ($manny->currentTask !== null) {
                $currentTask = MannyService::PUBLIC_TASK_UNKNOWN_TOO_FAR;
            }
            $taskProgressPercent = 0.0;
            $taskEstimatedEndTime = null;
            $task = [];
        }

        return [
            'id' => $manny->uid,
            'name' => $manny->name,
            'location' => $manny->isOnProbe()
                ? ['type' => Manny::LOCATION_PROBE]
                : ['type' => Manny::LOCATION_SECTOR, 'sector' => ['relative' => $relativeSector]],
            'currentTask' => $currentTask,
            'taskProgressPercent' => $taskProgressPercent,
            'taskEstimatedEndTime' => $taskEstimatedEndTime,
            'task' => $task,
            'taskVisibility' => $taskVisibility,
            'cargo' => ($this->cargoArray)($manny),
            'canReceiveOrders' => $manny->probeId === $probe->id && $manny->isInSameSectorAs($probe) && $manny->currentTask === null,
        ];
    }

    private function taskVisibilityFor(NeumannProbe $probe, Manny $manny): string
    {
        if ($manny->isInSameSectorAs($probe)) {
            return MannyService::TASK_VISIBILITY_LOCAL;
        }
        if (
            $manny->sector !== null
            && $this->scut !== null
            && $this->scut->canSectorsCommunicate($probe->currentSector, $manny->sector)
        ) {
            return MannyService::TASK_VISIBILITY_SCUT_NETWORK;
        }

        return MannyService::TASK_VISIBILITY_TOO_FAR;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicTaskPayload(Manny $manny): array
    {
        $payload = $manny->taskPayload;
        unset($payload['snapshot'], $payload['consumedKit'], $payload['targetSector']);

        if (is_array($payload['reservedDetachedContainer'] ?? null)) {
            unset($payload['reservedDetachedContainer']['object']);
        }

        return $payload;
    }
}
