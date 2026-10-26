<?php
declare(strict_types=1);

require_once __DIR__ . '/CraftingBatchFixture.php';
require_once __DIR__ . '/MiningRetryFixture.php';

final class CraftingBatchContainerSignal extends PDOStatement
{
    protected function __construct(private string $table, private string $marker) {}
    public function execute(?array $params = null): bool
    {
        if (str_contains($this->queryString, 'SELECT * FROM ' . $this->table . ' WHERE id=? FOR UPDATE')) {
            file_put_contents($this->marker, 'locking output container');
        }
        return parent::execute($params);
    }
}

(static function () use ($connect, $race, $assert, $directory, $mysqlConfig, $tables): void {
    $db = $connect();
    $f = craftingBatchFixture($db, $directory, 'competing-craft-batches', 4, 0);
    (new \VonNeumannGame\Repository\StorageContainerRepository($db))->setResourceAmount($f['core']->id, 'metals', 0.06);
    $probeId = $f['probe']->id;
    $uids = array_column($f['actors'], 'uid');
    $f = $db = null;
    $operations = [];
    foreach (array_chunk($uids, 2) as $actors) {
        $operations[] = static function (PDO $db) use ($directory, $probeId, $actors): bool {
            $f = miningAtomicFixture($db, $directory);
            $probe = $f['probes']->findById($probeId);
            $f['mining']->withPreparedBatch($probe, static function ($probe) use ($f, $actors): void {
                foreach ($actors as $uid) { $f['mining']->startCrafting($probe, $uid, 'steel_plate'); }
            }, [], false, array_reverse($actors));
            return true;
        };
    }
    $results = $race($operations);
    $assert(count(array_filter($results, fn($r) => $r['ok'])) === 1, 'only one competing batch can consume the shared stock: ' . json_encode($results));
    $rejected = array_values(array_filter($results, fn($r) => !$r['ok']))[0];
    $assert($rejected['code'] === 'insufficient_metals', 'losing craft batch reports insufficient stock, without a deadlock');
    $db = $connect(); $f = miningAtomicFixture($db, $directory);
    $assert($f['storage']->resourceStock($f['probes']->findById($probeId), 'metals') === 0.02, 'losing batch rolls back all of its debits');
    $active = array_filter($f['mannies']->findByProbeId($probeId), fn($m) => $m->currentTask === 'crafting');
    $assert(count($active) === 2 && count(array_unique(array_column($active, 'taskScheduledEventId'))) === 2, 'only the winning batch leaves individual tasks and reservations');
    $f = $db = null;
    if ($mysqlConfig === null) { return; }

    // Hold the shared inventory while a scheduler completion on another actor waits for its
    // output container. An independent probe must still be able to accept a batch.
    $db = $connect();
    $f = craftingBatchFixture($db, $directory, 'craft-completion-race', 3, 0);
    $probeId = $f['probe']->id;
    $completing = $f['mining']->startCrafting($f['probe'], $f['actors'][2]->uid, 'steel_bar');
    $eventId = miningRetryDue($db, $f, $completing)->id;
    $actors = array_column(array_slice($f['actors'], 0, 2), 'uid');
    $other = craftingBatchFixture($db, $directory, 'independent-craft-probe', 1, 0);
    $otherProbeId = $other['probe']->id; $otherUid = $other['actors'][0]->uid;
    $held = $directory . '/craft-batch-held';
    $attempted = $directory . '/craft-output-attempted';
    $independent = $directory . '/craft-independent-done';
    $wait = static function (string $path): void {
        $until = microtime(true) + 10;
        while (!is_file($path)) {
            if (microtime(true) > $until) { throw new RuntimeException('Timed out waiting for craft batch worker'); }
            usleep(10000); clearstatcache(true, $path);
        }
    };
    $f = $other = $db = null;
    $results = $race([
        static function (PDO $db) use ($directory, $probeId, $actors, $held, $attempted, $independent, $wait): bool {
            $f = miningAtomicFixture($db, $directory);
            $f['mining']->withPreparedBatch($f['probes']->findById($probeId), static function ($probe) use ($f, $actors, $held, $attempted, $independent, $wait): void {
                file_put_contents($held, 'locked');
                $wait($attempted); $wait($independent);
                foreach ($actors as $uid) { $f['mining']->startCrafting($probe, $uid, 'electric_motor'); }
            }, [], false, $actors);
            return true;
        },
        static function (PDO $db) use ($directory, $eventId, $held, $attempted, $wait, $tables): bool {
            $wait($held);
            $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CraftingBatchContainerSignal::class, [$tables['storage_containers'], $attempted]]);
            $f = miningAtomicFixture($db, $directory);
            $event = $f['events']->claim($f['events']->findById($eventId));
            $f['mining']->refreshScheduledMannyTask($event);
            return true;
        },
        static function (PDO $db) use ($directory, $otherProbeId, $otherUid, $held, $independent, $wait): bool {
            $wait($held); $f = miningAtomicFixture($db, $directory);
            $f['mining']->withPreparedBatch($f['probes']->findById($otherProbeId), static function ($probe) use ($f, $otherUid): void {
                $f['mining']->startCrafting($probe, $otherUid, 'steel_plate');
            }, [], false, [$otherUid]);
            file_put_contents($independent, 'done');
            return true;
        },
    ]);
    $assert(count(array_filter($results, fn($r) => $r['ok'])) === 3, 'craft batch, concurrent completion and unrelated probe settle: ' . json_encode($results));
    $db = $connect(); $f = miningAtomicFixture($db, $directory);
    $query = $db->prepare("SELECT COUNT(*) FROM probe_items WHERE probe_id=? AND type='steel_bar'"); $query->execute([$probeId]);
    $assert((int) $query->fetchColumn() === 1, 'scheduler output commits once after batch inventory is released');
    foreach ($actors as $uid) {
        $manny = $f['mannies']->findByUidForProbe($probeId, $uid);
        $assert($manny->taskPayload['durationSeconds'] === 1800, 'batch did not consume a component produced after its snapshot');
    }
    $query = $f = $db = null;
})();
