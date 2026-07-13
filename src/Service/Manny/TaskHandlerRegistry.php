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
        InspectSectorObjectTaskHandler $inspectSectorObject,
        ProbeImprovementTaskHandler $probeImprovement,
        ProbeAssemblyTaskHandler $probeAssembly,
        DeuteriumTankRefillTaskHandler $deuteriumTankRefill,
        DeuteriumTransferTaskHandler $deuteriumTransfer,
        ReturningTaskHandler $returning,
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
            $inspectSectorObject,
            $deuteriumTankRefill,
            $returning,
            new WaitingForSpaceTaskHandler(),
            new StorageMoveTaskHandler(),
            new ScutRelayTurnOnTaskHandler(),
            $probeImprovement,
            $probeAssembly,
            $deuteriumTransfer,
        ];
    }
}
