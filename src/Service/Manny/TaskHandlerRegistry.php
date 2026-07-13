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
        MiningTaskHandler $mining,
        DetachStorageContainerTaskHandler $detachStorageContainer,
        DropStorageContainerTaskHandler $dropStorageContainer,
        InspectSectorObjectTaskHandler $inspectSectorObject,
        ProbeImprovementTaskHandler $probeImprovement,
        ProbeAssemblyTaskHandler $probeAssembly,
        DeuteriumTankRefillTaskHandler $deuteriumTankRefill,
        DeuteriumTransferTaskHandler $deuteriumTransfer,
        ReturningTaskHandler $returning,
        SalvageTaskHandler $salvage,
        ScutRelayTurnOnTaskHandler $scutRelayTurnOn,
        StorageMoveTaskHandler $storageMove,
        WaypointBookmarkInstallationTaskHandler $waypointBookmarkInstallation,
    ): array
    {
        return [
            $repair,
            $mining,
            new CraftingTaskHandler(),
            $salvage,
            $waypointBookmarkInstallation,
            $detachStorageContainer,
            $dropStorageContainer,
            $inspectSectorObject,
            $deuteriumTankRefill,
            $returning,
            new WaitingForSpaceTaskHandler(),
            $storageMove,
            $scutRelayTurnOn,
            $probeImprovement,
            $probeAssembly,
            $deuteriumTransfer,
        ];
    }
}
