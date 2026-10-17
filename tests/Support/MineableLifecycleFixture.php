<?php

declare(strict_types=1);

/** Real repositories/services; callers provide an isolated database and universe. */
function mineableLifecycleFixture(PDO $db, string $directory): array
{
    $events = new \VonNeumannGame\Repository\ScheduledEventRepository($db);
    $players = new \VonNeumannGame\Repository\PlayerRepository($db);
    $probes = new \VonNeumannGame\Repository\NeumannProbeRepository($db);
    $mannies = new \VonNeumannGame\Repository\MannyRepository($db, scheduledEvents: $events);
    $items = new \VonNeumannGame\Repository\ProbeItemRepository($db);
    $storage = new \VonNeumannGame\Service\ProbeStorageService(new \VonNeumannGame\Repository\StorageContainerRepository($db), $items, $mannies, $probes);
    $effects = new \VonNeumannGame\Repository\Storage\SectorEffectRepository($db);
    $files = new \VonNeumannGame\Sector\SectorFileRepository($directory);
    $generator = new \VonNeumannGame\Sector\SectorContentGenerator(['categoryWeights' => ['empty' => 100, 'stellar_simple' => 0, 'stellar_binary' => 0, 'asteroids' => 0, 'dust_cloud' => 0, 'dead_star' => 0, 'black_hole' => 0]]);
    $sectors = new \VonNeumannGame\Sector\SectorService($files, $generator, 'lifecycle-fixture', $db, detachedContainers: new \VonNeumannGame\Repository\DetachedStorageContainerRepository($db));
    $outbox = new \VonNeumannGame\Service\SectorEffectService($effects, $events, $sectors);
    $reinstantiation = new \VonNeumannGame\Service\ProbeReinstantiationService(new \VonNeumannGame\Repository\ProbeReinstantiationRepository($db), $players, $probes, $mannies, new \VonNeumannGame\Repository\VisitedSectorRepository($db), $storage, $sectors);
    $trajectories = new \VonNeumannGame\Repository\AsteroidTrajectoryRepository($db);
    $processor = new \VonNeumannGame\Service\AsteroidTrajectory\AsteroidTrajectoryPhaseProcessor($trajectories, new \VonNeumannGame\Service\AsteroidTrajectory\PhaseHandlerRegistry([
        new \VonNeumannGame\Service\AsteroidTrajectory\SystemImpactPhaseHandler($trajectories, $sectors, $probes, new \VonNeumannGame\Repository\ProbeMovementRepository($db), new \VonNeumannGame\Service\AsteroidTrajectory\ImpactDamageResolver(), $reinstantiation),
        new \VonNeumannGame\Service\AsteroidTrajectory\SectorTransferPhaseHandler($trajectories, $events, $sectors, new \VonNeumannGame\Service\AsteroidTrajectory\CaptureCalculator()),
        new \VonNeumannGame\Service\AsteroidTrajectory\BlackHoleOrbitPhaseHandler($trajectories, $sectors),
    ]));
    $launches = new \VonNeumannGame\Service\AsteroidTrajectory\AsteroidTrajectoryService($trajectories, $probes, $events, $sectors, [], []);
    $stocks = $sectors->mineableStocks();
    return compact('sectors', 'files', 'stocks', 'effects', 'outbox', 'trajectories', 'processor', 'launches', 'probes', 'players', 'mannies', 'items', 'storage', 'events');
}
