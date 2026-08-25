<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Sector\SectorCoordinates;

final class AutonomousUnitObservationService
{
    public function __construct(private readonly MannyRepository $mannies, private readonly OthersRepository $others) {}

    public function page(SectorCoordinates $sector, ?string $cursor, int $limit): array
    {
        $units = [];
        foreach ($this->mannies->findDeployedBySector($sector) as $manny) {
            $units[] = [
                'id' => (string) $manny['uid'], 'kind' => 'manny',
                'carrier' => ['id' => (string) $manny['carrier_public_id'], 'kind' => 'probe'],
                'spatialState' => $this->mannySpatialState((string) ($manny['current_task'] ?? ''), $manny['reserved_storage_container_id'] ?? null),
            ];
        }
        foreach ($this->others->deployedAuxiliariesBySector($sector->getX(), $sector->getY(), $sector->getZ()) as $auxiliary) {
            $state = (string) $auxiliary['spatial_state'];
            if (!in_array($state, ['moving_to_sector_object', 'returning_to_carrier', 'drifting', 'landed_on_sector_object'], true)) { $state = 'drifting'; }
            $units[] = [
                'id' => (string) $auxiliary['public_id'], 'kind' => 'others_auxiliary',
                'carrier' => ['id' => (string) $auxiliary['carrier_public_id'], 'kind' => 'others_ship'],
                'spatialState' => $state,
            ];
        }
        usort($units, static fn(array $a, array $b): int => [$a['kind'], $a['id']] <=> [$b['kind'], $b['id']]);
        if ($cursor !== null) {
            $units = array_values(array_filter($units, static fn(array $unit): bool => $unit['kind'] . "\0" . $unit['id'] > $cursor));
        }
        $page = array_slice($units, 0, $limit + 1);
        $next = count($page) > $limit ? $page[$limit - 1]['kind'] . "\0" . $page[$limit - 1]['id'] : null;
        if ($next !== null) { array_pop($page); }
        return ['units' => $page, 'nextCursor' => $next];
    }

    private function mannySpatialState(string $task, mixed $objectMarker): string
    {
        if ($task === 'returning') { return 'returning_to_carrier'; }
        if ($objectMarker !== null || in_array($task, ['mining', 'inspecting_sector_object'], true)) { return 'landed_on_sector_object'; }
        if ($task !== '') { return 'moving_to_sector_object'; }
        return 'drifting';
    }
}
