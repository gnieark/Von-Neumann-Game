<?php
declare(strict_types=1);

require_once __DIR__ . '/MiningRetryFixture.php';

function craftingRetryScheduler(PDO $db, array $f, ?Closure $observer = null): \VonNeumannGame\Service\SchedulerService
{
    $movements = new \VonNeumannGame\Repository\ProbeMovementRepository($db);
    $movement = new \VonNeumannGame\Service\ProbeMovementService($f['probes'], $movements, new \VonNeumannGame\Repository\VisitedSectorRepository($db), $f['events'], $f['sectors']);
    return new \VonNeumannGame\Service\SchedulerService($f['events'], $f['probes'], $movements, $movement, $f['mining'], craftingObserver: $observer);
}

function craftingRetryOrder(array $f, string $name, bool $printer = false): array
{
    [$probe, $manny] = miningAtomicActor($f, $name, new \VonNeumannGame\Sector\SectorCoordinates(837, 837, 0));
    $probe->deuteriumStock = 1;
    $f['probes']->save($probe);
    $f['storage']->addResource($probe, 'metals', 0.2);
    $manny = $printer
        ? $f['mining']->startAtomicPrinterCrafting($probe, 'micro_conductor', $manny->uid)
        : $f['mining']->startCrafting($probe, $manny->uid, 'steel_plate');
    return [$probe, $manny];
}

function craftingRetryOutputCount(PDO $db, int $probeId): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM probe_items WHERE probe_id=? AND type IN ('steel_plate','micro_conductor')");
    $stmt->execute([$probeId]);
    return (int) $stmt->fetchColumn();
}
