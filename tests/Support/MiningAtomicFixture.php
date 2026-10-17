<?php

declare(strict_types=1);

require_once __DIR__ . '/MineableLifecycleFixture.php';

function miningAtomicFixture(PDO $db, string $directory): array
{
    $fixture = mineableLifecycleFixture($db, $directory);
    $fixture['mining'] = new \VonNeumannGame\Service\MannyService(
        $fixture['mannies'], $fixture['probes'], $fixture['sectors'], $fixture['items'], $fixture['storage'], scheduledEvents: $fixture['events'],
    );
    $fixture['detached'] = new \VonNeumannGame\Repository\DetachedStorageContainerRepository($db);
    return $fixture;
}

function miningAtomicActor(array $fixture, string $name, \VonNeumannGame\Sector\SectorCoordinates $sector): array
{
    $player = $fixture['players']->createPlayer($name, null);
    $probe = $fixture['probes']->createForPlayer($player->id, $name, $sector);
    $probe->deuteriumStock = 0;
    $fixture['probes']->save($probe);
    $fixture['storage']->initializeProbeStorage($probe);
    $manny = $fixture['mannies']->createForProbe($probe->id, $name);
    $fixture['storage']->repairProbeStorage($probe);
    return [$probe, $fixture['mannies']->findById($manny->id)];
}

function miningAtomicDue(array $fixture, \VonNeumannGame\Domain\Manny $manny): \VonNeumannGame\Domain\ScheduledEvent
{
    $manny = $fixture['mannies']->findById($manny->id);
    $manny->taskEndsAt = gmdate('c', time() - 1);
    $manny->taskPayload[\VonNeumannGame\Domain\Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] = $manny->taskEndsAt;
    $fixture['mannies']->save($manny);
    return $fixture['events']->claim($fixture['events']->findById($manny->taskScheduledEventId));
}
