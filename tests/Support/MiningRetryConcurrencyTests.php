<?php
declare(strict_types=1);

require_once __DIR__ . '/MiningRetryFixture.php';

/** Signals the source lock request without changing the actual server-side locking. */
final class MiningRetrySignalStatement extends PDOStatement
{
    protected function __construct(private string $source, private string $marker) {}
    public function execute(?array $params = null): bool
    {
        if ($params === [$this->source] && str_contains($this->queryString, 'FOR UPDATE')) { file_put_contents($this->marker, 'locking'); }
        return parent::execute($params);
    }
}

(static function () use ($connect, $race, $assert, $directory, $mysqlConfig): void {
    if ($mysqlConfig === null) { return; }
    $wait = static function (string $path): void {
        $until = microtime(true) + 15;
        while (!is_file($path)) {
            if (microtime(true) > $until) { throw new RuntimeException('Timed out waiting for SQL contention fixture.'); }
            usleep(10000); clearstatcache(true, $path);
        }
    };
    foreach ([1205, 1213] as $code) {
        $db = $connect(); $f = miningAtomicFixture($db, $directory);
        // Previous race fixtures deliberately leave live tasks. This isolated run selects only ours.
        $db->exec("UPDATE scheduled_events SET run_at='9999-12-31T00:00:00+00:00' WHERE status='pending'");
        $sector = new \VonNeumannGame\Sector\SectorCoordinates(1200 + 2 * $code, 1200, 0);
        $source = 'retry-real-' . $code;
        $amounts = array_replace(\VonNeumannGame\Domain\MineableResourceAmounts::zero(), ['metals' => 1.0]);
        $rock = new \VonNeumannGame\Sector\Asteroid($source, 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: $amounts);
        $f['files']->save(new \VonNeumannGame\Sector\SectorContent($sector, [$rock]));
        (new \VonNeumannGame\Database\StorageTransaction($db))->run(fn() => $f['stocks']->create($source . '-create', $source, $amounts));
        [$probe, $manny] = miningAtomicActor($f, 'retry-real-' . $code, $sector);
        $manny = $f['mining']->startMining($probe, $manny->uid, $source, 'metals', 0.1);
        $event = miningRetryDue($db, $f, $manny); $eventId = $event->id; $probeId = $probe->id;
        $held = $directory . '/retry-held-' . $code; $requested = $directory . '/retry-requested-' . $code; $finished = $directory . '/retry-finished-' . $code;
        $f = $db = null;
        $results = $race([
            static function (PDO $db) use ($code, $source, $eventId, $held, $requested, $finished, $wait): bool {
                $db->beginTransaction();
                try {
                    $db->prepare('SELECT object_id FROM mineable_object_stocks WHERE object_id=? FOR UPDATE')->execute([$source]);
                    if ($code === 1213) {
                        // Make this transaction heavier so InnoDB chooses the mining transaction as victim.
                        $insert = $db->prepare('INSERT INTO sector_effect_locks(sector_x,sector_y,sector_z) VALUES(?,?,?)');
                        for ($i = 0; $i < 100; $i++) { $insert->execute([991213, $i, 0]); }
                    }
                    file_put_contents($held, 'held');
                    if ($code === 1205) { $wait($finished); }
                    else {
                        $wait($requested); usleep(100000);
                        $db->prepare('UPDATE scheduled_events SET updated_at=updated_at WHERE id=?')->execute([$eventId]);
                    }
                    $db->commit();
                } finally { if ($db->inTransaction()) { $db->rollBack(); } }
                return true;
            },
            static function (PDO $db) use ($directory, $source, $code, $held, $requested, $finished, $wait): array {
                $wait($held);
                $db->exec('SET SESSION innodb_lock_wait_timeout=' . ($code === 1205 ? 1 : 10));
                $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MiningRetrySignalStatement::class, [$source, $requested]]);
                $records = []; $f = miningAtomicFixture($db, $directory);
                $scheduler = miningRetryScheduler($db, $f, static function ($row) use (&$records): void { $records[] = $row; });
                try { $stats = $scheduler->processDueEvents(1); }
                finally { file_put_contents($finished, 'done'); }
                return ['stats' => $stats, 'records' => $records];
            },
        ]);
        $assert($results[0]['ok'] && $results[1]['ok'], 'real SQL conflict workers settle ' . $code . ': ' . json_encode($results));
        $result = $results[1]['result'];
        $assert($result['stats']['deferred'] === 1 && $result['records'][0]['sql_code'] === $code, 'scheduler reports actual MariaDB ' . $code);
        $db = $connect(); $f = miningAtomicFixture($db, $directory);
        $event = $f['events']->findById($eventId);
        $assert($event->status === 'pending' && $event->conflictCount === 1, 'conflict survives full rollback ' . $code);
        $assert($f['stocks']->find($source)['amounts']['metals'] === 1.0, 'SQL victim never consumes stock ' . $code);
        $stmt = $db->prepare('SELECT COUNT(*) FROM probe_operations WHERE probe_id=?'); $stmt->execute([$probeId]);
        $assert((int) $stmt->fetchColumn() === 0, 'SQL victim leaves no live admission ' . $code);
        $db->prepare('UPDATE scheduled_events SET run_at=? WHERE id=?')->execute([gmdate('c', time()-1), $eventId]);
        $stats = miningRetryScheduler($db, $f, static function (): void {})->processDueEvents(1);
        $assert($stats['processed'] === 1 && $f['events']->findById($eventId)->conflictCount === 0, 'fresh admission completes the SQL retry ' . $code);
        $assert($f['stocks']->find($source)['amounts']['metals'] === 0.9 && $f['storage']->resourceStock($f['probes']->findById($probeId), 'metals') === 0.1, 'exact conservation after real SQL retry ' . $code);
        $stmt = $f = $db = null;
    }
})();
