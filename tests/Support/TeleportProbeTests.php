<?php

declare(strict_types=1);

// Run the actual CLI with an isolated project root, database and universe.
(static function () use ($root, $tmp, $test): void {
    $fixture = $tmp . '/teleport-probe';
    mkdir($fixture . '/scripts', 0775, true);
    mkdir($fixture . '/config', 0775, true);
    copy($root . '/scripts/teleport-probe.php', $fixture . '/scripts/teleport-probe.php');
    mkdir($fixture . '/vendor', 0775, true);
    file_put_contents($fixture . '/vendor/autoload.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';');
    file_put_contents($fixture . '/config/database.json', json_encode(['driver' => 'sqlite', 'path' => 'database.sqlite'], JSON_THROW_ON_ERROR));
    file_put_contents($fixture . '/config/app.json', json_encode(['worldSeed' => 'teleport-regression', 'universePath' => 'universe'], JSON_THROW_ON_ERROR));
    $factory = new \VonNeumannGame\AppFactory($fixture);
    $db = $factory->pdo(initializeSchema: true);
    $players = new \VonNeumannGame\Repository\PlayerRepository($db);
    $probes = new \VonNeumannGame\Repository\NeumannProbeRepository($db);
    $improvements = new \VonNeumannGame\Repository\ProbeImprovementRepository($db);
    $movements = new \VonNeumannGame\Repository\ProbeMovementRepository($db);
    $sectors = new \VonNeumannGame\Sector\SectorFileRepository($fixture . '/universe');
    $origin = new \VonNeumannGame\Sector\SectorCoordinates(0, 0, 0);
    $destination = new \VonNeumannGame\Sector\SectorCoordinates(1390, 0, 0);
    $sectors->save(new \VonNeumannGame\Sector\SectorContent($origin, []));
    $sectors->save(new \VonNeumannGame\Sector\SectorContent($destination, []));

    foreach ([true, false] as $installed) {
        $player = $players->createPlayer('teleport-' . (int) $installed, 'Teleport test', null, $origin);
        $probe = $probes->createForPlayer($player->id, 'Teleport test probe', $origin);
        $probe->integrityPercent = 0.01;
        $probes->save($probe);
        $improvements->markAvailable($probe->id, \VonNeumannGame\Domain\ProbeImprovementCatalog::RELATIVISTIC_PATH_CLEARING);
        if ($installed) {
            $improvements->markDone($probe->id, \VonNeumannGame\Domain\ProbeImprovementCatalog::RELATIVISTIC_PATH_CLEARING);
        }
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture . '/scripts/teleport-probe.php')
            . ' ' . $probe->id . ' --relative=1390,0,0';
        $output = [];
        exec($command . ' 2>&1', $output, $status);
        $test->assertEquals(0, $status, 'teleport CLI completes with installed upgrade=' . (int) $installed . ': ' . implode("\n", $output));
        $after = $probes->findById($probe->id);
        $test->assertEquals($destination->toKey(), $after?->currentSector->toKey(), 'teleport CLI reaches the destination');
        $test->assertEquals($installed ? 'idle' : 'dead', $after?->status->value, 'teleport CLI honors installed dust immunity, not blueprint availability alone');
        $test->assertEquals($installed ? 0.01 : 0.0, $after?->integrityPercent, 'teleport CLI preserves hull integrity only with installed path clearing');
        $test->assertEquals($installed ? 'arrived' : 'destroyed', $movements->findLatestByProbeId($probe->id)?->status, 'teleport CLI records the correct movement outcome');
    }
})();
