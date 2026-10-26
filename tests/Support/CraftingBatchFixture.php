<?php
declare(strict_types=1);

require_once __DIR__ . '/MiningAtomicFixture.php';

function craftingBatchFixture(PDO $db, string $directory, string $name, int $mannyCount = 100, int $itemCount = 1000): array
{
    $f = miningAtomicFixture($db, $directory);
    [$probe, $first] = miningAtomicActor($f, $name, new \VonNeumannGame\Sector\SectorCoordinates(1820, 1820, 0));
    $db->prepare('UPDATE storage_containers SET capacity=1000 WHERE probe_id=?')->execute([$probe->id]);
    $core = (new \VonNeumannGame\Repository\StorageContainerRepository($db))->findByProbeId($probe->id)[0];
    $mannies = [$first];
    $db->beginTransaction();
    try {
        for ($i = 1; $i < $mannyCount; $i++) { $mannies[] = $f['mannies']->createForProbe($probe->id, 'Crafter ' . $i); }
        $db->prepare('UPDATE mannies SET storage_container_id=? WHERE probe_id=?')->execute([$core->id, $probe->id]);
        for ($i = 0; $i < $itemCount; $i++) { $f['items']->create($probe->id, 'steel_bar', 'Bar', \VonNeumannGame\Domain\CraftingRecipeCatalog::STEEL_BAR_CONTAINER_SPACE, storageContainerId: $core->id); }
        $containers = new \VonNeumannGame\Repository\StorageContainerRepository($db);
        foreach (['metals', 'ice', 'carbon_compounds'] as $type) { $containers->setResourceAmount($core->id, $type, 100.0); }
        $probe->deuteriumStock = 10000.0; $f['probes']->save($probe);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $f + ['probe' => $probe, 'actors' => $mannies, 'core' => $core];
}
