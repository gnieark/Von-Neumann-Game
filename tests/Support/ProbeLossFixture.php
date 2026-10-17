<?php
declare(strict_types=1);
require_once __DIR__ . '/MiningAtomicFixture.php';

function probeLossFixture(PDO $db, string $directory): array
{
    $f = miningAtomicFixture($db, $directory);
    $f['operations'] = new \VonNeumannGame\Repository\ProbeOperationRepository($db);
    $f['lossRepository'] = new \VonNeumannGame\Repository\ProbeReinstantiationRepository($db);
    $f['losses'] = new \VonNeumannGame\Service\ProbeReinstantiationService(
        $f['lossRepository'], $f['players'], $f['probes'], $f['mannies'], new \VonNeumannGame\Repository\VisitedSectorRepository($db),
        $f['storage'], $f['sectors'], new \VonNeumannGame\Repository\ProbeDamageWarningRepository($db),
        gameplayConfig: ['player' => ['spawnCoordinateMin' => 800, 'spawnCoordinateMax' => 800]],
        sectorChanges: new \VonNeumannGame\Service\OthersSectorService($f['effects'], $f['outbox'], $f['sectors']), mannyService: $f['mining'],
    );
    return $f;
}
