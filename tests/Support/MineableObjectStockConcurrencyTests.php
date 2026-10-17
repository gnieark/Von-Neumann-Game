<?php

declare(strict_types=1);

use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Repository\MineableObjectStockRepository;

// Included by StorageConcurrencyTests: real independent connections, isolated MariaDB tables.
(static function () use ($connect, $race, $assert, $directory, $mysqlConfig): void {
    $sectorCoordinates = new \VonNeumannGame\Sector\SectorCoordinates(600, 600, 0);
    $sectorLock = static function (PDO $db) use ($sectorCoordinates): bool {
        $effects = new \VonNeumannGame\Repository\Storage\SectorEffectRepository($db);
        for ($attempt = 0; $attempt < 25; ++$attempt) { $effects->withSectorLock($sectorCoordinates, static fn(): bool => true); }
        return true;
    };
    $results = $race([$sectorLock, $sectorLock]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'repeated concurrent sector locks avoid shared duplicate-key lock upgrades: ' . json_encode($results));
    $createSector = static function (PDO $db) use ($directory, $sectorCoordinates): array {
        $sectors = new \VonNeumannGame\Sector\SectorService(
            new \VonNeumannGame\Sector\SectorFileRepository($directory),
            new \VonNeumannGame\Sector\SectorContentGenerator(['categoryWeights' => ['empty' => 0, 'stellar_simple' => 0, 'stellar_binary' => 0, 'asteroids' => 100, 'dust_cloud' => 0, 'dead_star' => 0, 'black_hole' => 0]]),
            'mineable-sector-race', $db,
        );
        return $sectors->getOrCreateSector($sectorCoordinates)->mineableIds();
    };
    $results = $race([$createSector, $createSector]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'two independent workers can request the same new sector: ' . json_encode($results));
    $assert($results[0]['result'] !== [] && $results[0]['result'] === $results[1]['result'], 'concurrent sector creation publishes the same final source IDs');
    $db = $connect();
    $assert((int) $db->query("SELECT COUNT(*) FROM sector_effects WHERE sector_x=600 AND sector_y=600 AND sector_z=0 AND effect_type='create_sector'")->fetchColumn() === 1, 'concurrent generation commits one creation intention per sector');
    $sourceIds = $results[0]['result'];
    $stocks = (new MineableObjectStockRepository($db))->findMany($sourceIds);
    $assert(count(array_filter($stocks, static fn(array $stock): bool => $stock['version'] === 1)) === count($sourceIds), 'concurrent sector publication creates each source stock exactly once');
    $db = null;
    $zero = MineableResourceAmounts::zero();
    $amounts = array_replace($zero, ['metals' => 100.0]);
    $db = $connect();
    $stocks = new MineableObjectStockRepository($db);
    (new StorageTransaction($db))->run(function () use ($stocks, $amounts): void {
        $stocks->create('race-source-a', 'stock-race-a', $amounts);
        $stocks->create('race-source-b', 'stock-race-b', $amounts);
    });
    $stocks = $db = null;
    $debit = static function (PDO $db) use ($zero): array {
        return (new StorageTransaction($db))->run(function () use ($db, $zero): array {
            $stocks = new MineableObjectStockRepository($db);
            $current = $stocks->lockMany(['stock-race-a'])['stock-race-a'];
            $take = min(80.0, $current['amounts']['metals']);
            return $stocks->debit('stock-race-a', array_replace($zero, ['metals' => $take]));
        });
    };
    $results = $race([$debit, $debit]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'concurrent mineable debits both settle under source locks');
    $assert(array_sum(array_map(static fn(array $result): float => $result['result']['metals'], $results)) === 100.0, 'concurrent mineable debits cannot extract more than the source stock');
    $db = $connect();
    $assert((new MineableObjectStockRepository($db))->find('stock-race-a')['amounts'] === $zero, 'concurrent exhaustion preserves a zero stock row');
    $db = null;

    $create = static fn(string $operation, string $candidate = 'forced-collision'): Closure => static function (PDO $db) use ($operation, $amounts, $candidate): string {
        return (new StorageTransaction($db, maxAttempts: 5))->run(fn(): string => (new MineableObjectStockRepository($db))->create($operation, $candidate, $amounts));
    };
    $results = $race([$create('race-create-1'), $create('race-create-2')]);
    $assert($results[0]['ok'] && $results[1]['ok'] && $results[0]['result'] !== $results[1]['result'], 'concurrent distinct creations resolve a forced SQL identity collision');
    $results = $race([$create('race-create-replay'), $create('race-create-replay')]);
    $assert($results[0]['ok'] && $results[1]['ok'] && $results[0]['result'] === $results[1]['result'], 'concurrent creation replay reuses a single durable attribution');
    $replayId = $results[0]['result'];
    $db = $connect();
    $stocks = new MineableObjectStockRepository($db);
    (new StorageTransaction($db))->run(fn() => $stocks->delete($replayId));
    $stocks = $db = null;
    $results = $race([$create('race-create-replay'), $create('race-create-after-delete', $replayId)]);
    $assert($results[0]['ok'] && $results[1]['ok'] && $results[0]['result'] === $replayId && $results[1]['result'] !== $replayId, 'deleted stock identities remain reserved against concurrent replay and creation');
    $db = $connect();
    $assert((new MineableObjectStockRepository($db))->find($replayId)['version'] === null, 'concurrent creation replay never resurrects deleted resources');
    $db = null;

    if ($mysqlConfig === null) { return; } // SQLite serializes writers; it cannot prove row independence.
    $heldPath = $directory . '/mineable-a-held';
    $donePath = $directory . '/mineable-b-done';
    $results = $race([
        static function (PDO $db) use ($heldPath, $donePath): bool {
            return (new StorageTransaction($db))->run(function () use ($db, $heldPath, $donePath): bool {
                (new MineableObjectStockRepository($db))->lockMany(['stock-race-a']);
                file_put_contents($heldPath, 'held');
                $deadline = microtime(true) + 5.0;
                do {
                    clearstatcache(true, $donePath);
                    if (is_file($donePath)) { return true; }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                throw new RuntimeException('Source B could not finish while source A remained locked.');
            });
        },
        static function (PDO $db) use ($heldPath, $donePath, $zero): bool {
            $deadline = microtime(true) + 5.0;
            do {
                clearstatcache(true, $heldPath);
                if (is_file($heldPath)) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if (!is_file($heldPath)) { throw new RuntimeException('Source A never reached its lock barrier.'); }
            (new StorageTransaction($db))->run(fn() => (new MineableObjectStockRepository($db))->debit('stock-race-b', array_replace($zero, ['metals' => 1.0])));
            file_put_contents($donePath, 'committed');
            return true;
        },
    ]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'MariaDB independent mineable sources commit while another source transaction remains open');
})();
