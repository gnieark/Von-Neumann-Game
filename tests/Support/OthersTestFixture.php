<?php

declare(strict_types=1);

function othersTestFixture(PDO $db, string $directory): array
{
    $others = new \VonNeumannGame\Repository\OthersRepository($db);
    $events = new \VonNeumannGame\Repository\ScheduledEventRepository($db);
    $players = new \VonNeumannGame\Repository\PlayerRepository($db);
    $probes = new \VonNeumannGame\Repository\NeumannProbeRepository($db);
    $mannies = new \VonNeumannGame\Repository\MannyRepository($db, scheduledEvents: $events);
    $items = new \VonNeumannGame\Repository\ProbeItemRepository($db);
    $visited = new \VonNeumannGame\Repository\VisitedSectorRepository($db);
    $storage = new \VonNeumannGame\Service\ProbeStorageService(new \VonNeumannGame\Repository\StorageContainerRepository($db), $items, $mannies, $probes);
    $effects = new \VonNeumannGame\Repository\Storage\SectorEffectRepository($db);
    $sectors = new \VonNeumannGame\Sector\SectorService(new \VonNeumannGame\Sector\SectorFileRepository($directory), new \VonNeumannGame\Sector\SectorContentGenerator(), 'others-fixture', effects: $effects);
    $outbox = new \VonNeumannGame\Service\SectorEffectService($effects, $events, $sectors);
    $reinstantiation = new \VonNeumannGame\Service\ProbeReinstantiationService(new \VonNeumannGame\Repository\ProbeReinstantiationRepository($db), $players, $probes, $mannies, $visited, $storage, $sectors, sectorChanges: new \VonNeumannGame\Service\OthersSectorService($effects, $outbox, $sectors));
    $service = new \VonNeumannGame\Service\OthersService($others, $events, $reinstantiation, new \VonNeumannGame\Repository\Others\OthersPersistence($db), sectors: $sectors, players: $players, probes: $probes, mannies: $mannies, items: $items);
    return [$others, $service, $events, $sectors, $outbox];
}
