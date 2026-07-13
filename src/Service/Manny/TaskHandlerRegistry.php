<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

final class TaskHandlerRegistry
{
    /**
     * @return list<TaskHandlerInterface>
     */
    public static function defaultHandlers(
        RepairTaskHandler $repair,
        DetachStorageContainerTaskHandler $detachStorageContainer,
        DropStorageContainerTaskHandler $dropStorageContainer,
        DeuteriumTankRefillTaskHandler $deuteriumTankRefill,
        DeuteriumTransferTaskHandler $deuteriumTransfer,
    ): array
    {
        return [
            $repair,
            new MiningTaskHandler(),
            new CraftingTaskHandler(),
            new SalvageTaskHandler(),
            new WaypointBookmarkInstallationTaskHandler(),
            $detachStorageContainer,
            $dropStorageContainer,
            new InspectSectorObjectTaskHandler(),
            $deuteriumTankRefill,
            new ReturningTaskHandler(),
            new WaitingForSpaceTaskHandler(),
            new StorageMoveTaskHandler(),
            new ScutRelayTurnOnTaskHandler(),
            new ProbeImprovementTaskHandler(),
            new ProbeAssemblyTaskHandler(),
            $deuteriumTransfer,
        ];
    }
}
