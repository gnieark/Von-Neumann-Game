<?php

declare(strict_types=1);

use VonNeumannGame\Database\Migration\ForgottenMannyMigration;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Repository\StorageContainerRepository;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\ProbeStorageService;

(static function () use ($connect, $race, $assert, $directory): void {
    $build = static function (PDO $db) use ($directory): array {
        $mannies = new MannyRepository($db);
        $probes = new NeumannProbeRepository($db);
        $items = new ProbeItemRepository($db);
        $storage = new ProbeStorageService(new StorageContainerRepository($db), $items, $mannies, $probes);
        $sectors = new SectorService(new SectorFileRepository($directory), new SectorContentGenerator(), 'forgotten-race', $db);
        return [new MannyService($mannies, $probes, $sectors, $items, $storage), $mannies, $probes, $storage];
    };
    $db = $connect();
    $migration = new ForgottenMannyMigration($db, $directory);
    $db->exec('ALTER TABLE mannies DROP COLUMN is_forgotten');
    $assert($migration->ensureSchema() && !$migration->ensureSchema(), 'forgotten column migration applies once on the actual database driver');
    [$service, $mannies, $probes, $storage] = $build($db);
    $sector = new SectorCoordinates(800, 800, 0);
    $probe = $probes->createForPlayer(1, 'Forgotten recovery race', $sector);
    $storage->initializeProbeStorage($probe);
    $db->prepare('UPDATE storage_containers SET capacity=0.35 WHERE probe_id=?')->execute([$probe->id]);
    $units = [];
    foreach (['first forgotten', 'second forgotten'] as $name) {
        $unit = $mannies->createForProbe($probe->id, $name);
        $unit->locationType = Manny::LOCATION_SECTOR; $unit->sector = $sector; $mannies->save($unit);
        (new StorageTransaction($db))->run(fn() => $mannies->setForgotten($unit, true));
        $units[] = $unit;
    }
    $service = $mannies = $probes = $storage = $migration = $db = null;
    $recover = static function (PDO $db) use ($build, $probe): bool {
        [$service] = $build($db);
        $service->recoverForgottenManniesForProbe($probe);
        return true;
    };
    $results = $race([$recover, $recover]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'concurrent inventory consultations can recover forgotten Mannies');
    $db = $connect();
    [$service, $mannies, $probes, $storage] = $build($db);
    $rows = $mannies->findByProbeId($probe->id);
    $assert(count(array_filter($rows, static fn(Manny $unit): bool => $unit->isOnProbe() && !$unit->isForgotten)) === 1, 'concurrent consultations board exactly one Manny into the last free space: ' . json_encode(array_map(static fn(Manny $unit): array => [$unit->locationType, $unit->storageContainerId, $unit->isForgotten], $rows)));
    $assert(count($mannies->findForgottenBySector($sector, $probe->id)) === 1, 'Manny without room stays forgotten after its carrier returns');
    $assert(!(new SectorFileRepository($directory))->exists($sector), 'recovery neither generates nor writes a sector file');

    $remaining = $mannies->findForgottenBySector($sector, $probe->id)[0];
    $db->prepare('UPDATE storage_containers SET capacity=0.40 WHERE probe_id=?')->execute([$probe->id]);
    $service = $mannies = $probes = $storage = $db = null;
    $order = static function (PDO $db) use ($build, $probe, $remaining): bool {
        [, $mannies, $probes] = $build($db);
        return $probes->withProbeLock($probe->id, fn() => $mannies->withMannyLock($remaining->id, function (Manny $unit) use ($mannies): bool {
            if ($unit->isOnProbe()) { return false; }
            $mannies->setForgotten($unit, false);
            $unit->currentTask = Manny::TASK_MINING;
            $mannies->save($unit);
            return true;
        }));
    };
    $results = $race([$recover, $order]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'recovery and a new order serialize under actor locks');
    $db = $connect();
    $unit = (new MannyRepository($db))->findById($remaining->id);
    $assert(!$unit->isForgotten && (($unit->isOnProbe() && $unit->currentTask === null) || (!$unit->isOnProbe() && $unit->currentTask === Manny::TASK_MINING)), 'order/recovery race leaves either a deployed task or one boarding, never both');
})();
