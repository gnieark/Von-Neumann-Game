<?php

declare(strict_types=1);

use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Repository\MineableObjectStockRepository;

(static function () use ($test): void {
    require_once __DIR__ . '/StorageTestPdo.php';
    $pdo = new StorageTestPdo(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    $pdo->exec('PRAGMA foreign_keys=ON');
    (new SchemaInitializer('sqlite'))->initialize($pdo);
    $stocks = new MineableObjectStockRepository($pdo);
    $transaction = new StorageTransaction($pdo);
    $zero = MineableResourceAmounts::zero();
    $metals = static fn(float $amount): array => array_replace($zero, ['metals' => $amount]);
    $test->assertThrows(fn() => $stocks->create('op-1', 'asteroid-a', $metals(10)), LogicException::class, 'stock creation requires the caller transaction');
    $test->assertThrows(fn() => $stocks->debit('asteroid-a', $metals(1)), LogicException::class, 'stock debit requires the caller transaction');
    $test->assertEquals(['amounts' => $zero, 'version' => null], $stocks->find('absent'), 'missing stock returns four zeros and an absent version');
    $test->assertEquals(0, (int) $pdo->query('SELECT COUNT(*) FROM mineable_object_identities')->fetchColumn(), 'missing stock reads reserve no identity');
    $test->assertEquals(0, (int) $pdo->query('SELECT COUNT(*) FROM mineable_object_stocks')->fetchColumn(), 'missing stock reads insert no stock');
    $test->assertThrows(fn() => $pdo->exec('INSERT INTO mineable_object_identities(object_id) VALUES(NULL)'), PDOException::class, 'SQL schema rejects null source identities on SQLite too');
    $id = $transaction->run(fn() => $stocks->create('op-1', 'asteroid-a', $metals(10.12345)));
    $test->assertEquals('asteroid-a', $id, 'first creation retains its proposed ID');
    $test->assertThrows(fn() => $pdo->exec("UPDATE mineable_object_stocks SET metals=-1 WHERE object_id='asteroid-a'"), PDOException::class, 'SQL schema rejects negative stocks independently of the repository');
    $test->assertEquals(10.1235, $stocks->find($id)['amounts']['metals'], 'SQL persists four-decimal normalized quantities');
    $transaction->run(fn() => $stocks->debit($id, $metals(0.1235)));
    $test->assertEquals(10.0, $stocks->find($id)['amounts']['metals'], 'debit uses the exact normalized vector');
    $test->assertEquals(2, $stocks->find($id)['version'], 'positive debit advances stock version');
    $test->assertEquals($id, $transaction->run(fn() => $stocks->create('op-1', 'different-candidate', $metals(100))), 'replay reuses the original durable allocation');
    $test->assertEquals(10.0, $stocks->find($id)['amounts']['metals'], 'creation replay does not replenish a mined stock');
    $test->assertThrows(fn() => $transaction->run(fn() => $stocks->debit($id, $metals(10.0001))), UnderflowException::class, 'debit refuses overdraft without clamping');
    $test->assertThrows(function () use ($transaction, $stocks, $id, $metals): void {
        $transaction->run(function () use ($stocks, $id, $metals): void { $stocks->debit($id, $metals(2)); throw new RuntimeException('failure after debit'); });
    }, RuntimeException::class, 'injected failure rolls back the entire caller transaction');
    $test->assertEquals(10.0, $stocks->find($id)['amounts']['metals'], 'rollback restores stock');
    $test->assertEquals(2, $stocks->find($id)['version'], 'rollback restores version');
    $other = $transaction->run(fn() => $stocks->create('op-2', 'asteroid-a', $metals(7)));
    $test->assert($other !== $id, 'distinct creations resolve a forced identity collision');
    $test->assertEquals(10.0, $stocks->find($id)['amounts']['metals'], 'identity collision cannot overwrite the first stock');
    $transaction->run(fn() => $stocks->delete($id));
    $test->assertEquals($id, $transaction->run(fn() => $stocks->create('op-1', 'asteroid-a', $metals(100))), 'replay after deletion retains the attributed ID');
    $test->assertEquals(null, $stocks->find($id)['version'], 'replay never recreates a deleted stock');
    $replacement = $transaction->run(fn() => $stocks->create('op-3', 'asteroid-a', $metals(4)));
    $test->assert($replacement !== $id && $replacement !== $other, 'a reserved ID remains unavailable after stock deletion');
    $transaction->run(fn() => $stocks->reserveHistoricalIdentity('old-asteroid'));
    $transaction->run(fn() => $stocks->reserveHistoricalIdentity('old-asteroid'));
    $historicalCollision = $transaction->run(fn() => $stocks->create('new-op', 'old-asteroid', $zero));
    $test->assert($historicalCollision !== 'old-asteroid', 'historical identities cannot be reused for new objects');
    $merged = $transaction->run(fn() => $stocks->merge('merge-1', [$id, $other], 'ast_merge_01234567890123456789'));
    $test->assertEquals(7.0, $stocks->find($merged)['amounts']['metals'], 'merge treats a missing source as a zero contribution');
    $test->assertEquals(null, $stocks->find($other)['version'], 'merge consumes the previous stock');
    $test->assertEquals($merged, $transaction->run(fn() => $stocks->merge('merge-1', [$id, $other], 'ignored')), 'merge replay returns its durable result without a second consumption');
    $motorized = $transaction->run(fn() => $stocks->replaceIdentity('motor-1', $merged, 'mtr_012345678901234567890123'));
    $test->assertEquals(7.0, $stocks->find($motorized)['amounts']['metals'], 'identity replacement conserves the stock');
    $test->assertEquals(null, $stocks->find($merged)['version'], 'old identity no longer supplies resources');
    $empty = $transaction->run(fn() => $stocks->replaceIdentity('motor-empty', 'old-asteroid', 'mtr_012345678901234567890123'));
    $test->assertEquals(28, strlen($empty), 'collision candidate preserves the motorized ID format');
    $test->assertEquals(['amounts' => $zero, 'version' => 1], $stocks->find($empty), 'explicit identity transformation of missing stock creates an exhausted result');
    $test->assertThrows(fn() => $transaction->run(fn() => $stocks->debit('absent', $metals(0.0001))), UnderflowException::class, 'missing stock cannot debit a positive amount');
    $transaction->run(fn() => $stocks->debit('absent', $zero));
    $test->assertEquals(null, $stocks->find('absent')['version'], 'a zero debit does not repair an absent row');

    foreach ([NAN, INF, -0.0001, '1', true] as $invalid) {
        $test->assertThrows(fn() => MineableResourceAmounts::normalize(array_replace($zero, ['ice' => $invalid])), InvalidArgumentException::class, 'resource normalization rejects nonfinite, negative or nonnumeric values');
    }
    $test->assertThrows(fn() => MineableResourceAmounts::normalize(['metals' => 1.0]), InvalidArgumentException::class, 'resource mutations require all canonical quantities');
    $test->assertThrows(fn() => MineableResourceAmounts::subtract($metals(549755813888.0), $metals(0.0001)), OverflowException::class, 'arithmetic reports insufficient float resolution instead of masking a cumulative loss');
    $test->assertThrows(fn() => $transaction->run(fn() => $stocks->create('bad-id', 'x ', $zero)), InvalidArgumentException::class, 'SQL identities reject whitespace aliases');
    $test->assertThrows(fn() => $transaction->run(fn() => $stocks->create('long-id', str_repeat('x', 256), $zero)), InvalidArgumentException::class, 'SQL identities enforce their declared byte bound');
    $upper = $transaction->run(fn() => $stocks->create('case-upper', 'CASE', $zero));
    $lower = $transaction->run(fn() => $stocks->create('case-lower', 'case', $zero));
    $test->assertEquals(['CASE', 'case'], [$upper, $lower], 'identity uniqueness is case-sensitive');

    $balance = $transaction->run(fn() => $stocks->create('repeated', 'repeated', $metals(1.0)));
    $credited = $zero;
    $transaction->run(function () use ($stocks, $balance, $metals, &$credited): void {
        for ($i = 0; $i < 1000; ++$i) { $credited = MineableResourceAmounts::add($credited, $stocks->debit($balance, $metals(0.0001))); }
        for ($i = 0; $i < 9; ++$i) { $credited = MineableResourceAmounts::add($credited, $stocks->debit($balance, $metals(0.1))); }
    });
    $test->assertEquals(1.0, $credited['metals'], 'repeated 0.0001 and 0.1 transfers conserve normalized quantities without cumulative drift');
    $test->assertEquals($zero, $stocks->find($balance)['amounts'], 'exhaustion retains an explicit SQL zero stock');
    $large = $transaction->run(fn() => $stocks->create('large', 'large', $metals(10000000000.1234)));
    $test->assertEquals('10000000000.1234', number_format($stocks->find($large)['amounts']['metals'], 4, '.', ''), 'PDO binding does not truncate quantities using PHP display precision');
    $pdo->metrics->reset();
    $many = $stocks->findMany(array_map(static fn(int $i): string => 'missing-' . $i, range(1, 801)));
    $test->assertEquals(801, count($many), 'batched reads return a zero projection for every missing ID');
    $test->assertEquals(3, $pdo->metrics->queries, '801 stock projections require three bounded queries instead of one per object');
    $test->assertThrows(function () use ($transaction, $stocks, $zero): void {
        $transaction->run(function () use ($stocks, $zero): void { $stocks->create('rollback-create', 'rolled-back', $zero); throw new RuntimeException('creation crash'); });
    }, RuntimeException::class, 'creation failure rolls back identity and stock together');
    $test->assertEquals(0, (int) $pdo->query("SELECT COUNT(*) FROM mineable_object_identities WHERE object_id='rolled-back'")->fetchColumn(), 'rolled back creation leaves no orphan identity');
    $transaction->run(fn() => $stocks->importExisting('manifest:source-1', 'imported', $metals(0.5)));
    $transaction->run(fn() => $stocks->importExisting('manifest:source-1', 'imported', $metals(0.5)));
    $test->assertEquals(1, $stocks->find('imported')['version'], 'migration resume verifies rather than rewrites an imported stock');
    $test->assertThrows(fn() => $transaction->run(fn() => $stocks->importExisting('manifest:source-1', 'imported', $metals(0.6))), RuntimeException::class, 'migration resume rejects divergent values');
    $transaction->run(fn() => $stocks->delete('imported'));
    $test->assertThrows(fn() => $transaction->run(fn() => $stocks->importExisting('manifest:source-1', 'imported', $metals(0.5))), RuntimeException::class, 'migration resume cannot resurrect a stock deleted after import');
})();
