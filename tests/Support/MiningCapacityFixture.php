<?php
declare(strict_types=1);

require_once __DIR__ . '/MiningAtomicFixture.php';

function miningCapacityService(array $f, int $limit): \VonNeumannGame\Service\MannyService
{
    return new \VonNeumannGame\Service\MannyService(
        $f['mannies'], $f['probes'], $f['sectors'], $f['items'], $f['storage'],
        ['manny' => ['maxMiningManniesPerObject' => $limit]], scheduledEvents: $f['events'],
    );
}

/** Seed existing assignments without launching thousands of orders or reserving source stock. */
function miningCapacitySeed(\VonNeumannGame\Repository\MannyRepository $mannies, \VonNeumannGame\Domain\NeumannProbe $probe, string $objectId, int $count): array
{
    $ids = [];
    $prefix = 'Existing ' . bin2hex(random_bytes(4)) . ' ';
    for ($i = 0; $i < $count; $i++) {
        $manny = $mannies->createForProbe($probe->id, $prefix . $i);
        $manny->currentTask = 'mining';
        $manny->locationType = 'sector';
        $manny->sector = $probe->currentSector;
        $manny->taskStartedAt = gmdate('c');
        $manny->taskEndsAt = gmdate('c', time() + 86400);
        $manny->taskPayload = ['objectId' => $objectId, 'resourceType' => 'metals', 'resourceTypes' => ['metals'], 'targetAmount' => 0.01];
        $mannies->save($manny);
        $ids[] = $manny->id;
    }
    return $ids;
}
