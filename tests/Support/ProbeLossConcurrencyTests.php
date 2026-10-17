<?php
declare(strict_types=1);
require_once __DIR__ . '/ProbeLossFixture.php';

(static function () use ($connect, $race, $assert, $directory, $mysqlConfig): void {
    $db = $connect(); $f = probeLossFixture($db, $directory);
    [$probe] = miningAtomicActor($f, 'loss-admission-race', new \VonNeumannGame\Sector\SectorCoordinates(1100, 1100, 0));
    $id = $probe->id; $f = $db = null;
    $results = $race([
        static function (PDO $db) use ($id): string {
            try { return (new \VonNeumannGame\Repository\ProbeOperationRepository($db))->admit([$id]); }
            catch (\VonNeumannGame\Repository\ProbeAdmissionClosedException) { return 'closed'; }
        },
        static function (PDO $db) use ($id): bool { return (new \VonNeumannGame\Repository\ProbeOperationRepository($db))->close($id); },
    ]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'admission/closure race settles without a stale unlocked check: ' . json_encode($results));
    $db = $connect();
    $stmt = $db->prepare('SELECT COUNT(*) FROM probe_operations WHERE probe_id=?'); $stmt->execute([$id]);
    $assert((int) $stmt->fetchColumn() === ($results[0]['result'] === 'closed' ? 0 : 1), 'every admission accepted before closure remains durably enumerable');
    $stmt = null; $db = null;

    $db = $connect(); $f = probeLossFixture($db, $directory);
    [$victim] = miningAtomicActor($f, 'loss-two-causes', new \VonNeumannGame\Sector\SectorCoordinates(1106, 1100, 0));
    $survivor = $f['probes']->createForPlayer($victim->playerId, 'Two causes survivor', $victim->currentSector);
    $victim->status = \VonNeumannGame\Domain\ProbeStatus::Dead; $f['probes']->save($victim);
    $f = $db = null;
    $operations = [];
    foreach (['missile_impact', 'laser_damage'] as $reason) {
        $operations[] = static function (PDO $db) use ($directory, $victim, $reason): bool {
            probeLossFixture($db, $directory)['losses']->handleTerminalProbeLoss($victim, $reason);
            return true;
        };
    }
    $results = $race($operations);
    $assert($results[0]['ok'] && $results[1]['ok'], 'two concurrent causes join the same loss: ' . json_encode($results));
    $db = $connect(); $f = probeLossFixture($db, $directory);
    $f['losses']->resumeLoss($victim->id);
    $stmt = $db->prepare('SELECT COUNT(*) FROM probe_damage_warnings WHERE probe_id=?'); $stmt->execute([$survivor->id]);
    $assert($f['lossRepository']->completed($victim->id) && (int) $stmt->fetchColumn() === 1 && $f['players']->findById($victim->playerId)->defaultProbeId === $survivor->id, 'concurrent loss finalization selects one survivor and emits one alert');
    $stmt = $f = $db = null;
    if ($mysqlConfig === null) { return; } // SQLite intentionally serializes writers globally.

    $waitFor = static function (string $path): void {
        $deadline = microtime(true) + 10;
        while (!is_file($path)) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Loss coordination blocked the other worker.'); }
            usleep(10000); clearstatcache(true, $path);
        }
    };
    $db = $connect(); $f = probeLossFixture($db, $directory);
    [$probe, $manny] = miningAtomicActor($f, 'loss-active-expired', new \VonNeumannGame\Sector\SectorCoordinates(1102, 1100, 0));
    $id = $probe->id;
    $token = $f['operations']->admit([$id], 3);
    $held = $directory . '/loss-ticket-held'; $drained = $directory . '/loss-drain-returned'; $committed = $directory . '/loss-ticket-committed';
    $f = $db = null;
    $results = $race([
        static function (PDO $db) use ($id, $token, $held, $drained, $committed, $waitFor): bool {
            $ops = new \VonNeumannGame\Repository\ProbeOperationRepository($db);
            $ops->execute($token, [$id], function () use ($held, $drained, $waitFor): void {
                file_put_contents($held, 'held');
                $waitFor($drained);
            });
            file_put_contents($committed, 'committed');
            return true;
        },
        static function (PDO $db) use ($directory, $id, $held, $drained, $committed, $waitFor): array {
            $waitFor($held);
            // Expiry while the first worker still owns the ticket row.
            usleep(3200000);
            $f = probeLossFixture($db, $directory);
            $probe = $f['probes']->findById($id); $probe->status = \VonNeumannGame\Domain\ProbeStatus::Dead; $f['probes']->save($probe);
            $started = microtime(true);
            $f['losses']->handleTerminalProbeLoss($probe, 'missile_impact');
            $duration = microtime(true) - $started;
            $state = $f['lossRepository']->loss($id)['state'];
            file_put_contents($drained, 'returned');
            $waitFor($committed);
            return [$state, $duration, $f['losses']->resumeLoss($id)];
        },
    ]);
    $assert($results[0]['ok'] && $results[1]['ok'], 'expired but active admission is fenced by its SQL lock: ' . json_encode($results));
    $assert($results[1]['result'][0] === 'draining' && $results[1]['result'][1] < 1 && $results[1]['result'][2], 'loss yields immediately on an active expired ticket, then converges after commit');

    // A legacy API writer retains a shared guard through commit. Closing uses NOWAIT.
    $db = $connect(); $f = probeLossFixture($db, $directory);
    [$probe] = miningAtomicActor($f, 'loss-legacy-api', new \VonNeumannGame\Sector\SectorCoordinates(1104, 1100, 0));
    $id = $probe->id; $f = $db = null;
    $held = $directory . '/loss-api-held'; $released = $directory . '/loss-api-release';
    $results = $race([
        static function (PDO $db) use ($id, $held, $released, $waitFor): bool {
            (new \VonNeumannGame\Repository\NeumannProbeRepository($db))->withProbeGuards([$id], function () use ($held, $released, $waitFor): void {
                file_put_contents($held, 'held'); $waitFor($released);
            });
            return true;
        },
        static function (PDO $db) use ($id, $held, $released, $waitFor): bool {
            $waitFor($held);
            $closed = (new \VonNeumannGame\Repository\ProbeOperationRepository($db))->close($id);
            file_put_contents($released, 'returned');
            return $closed;
        },
    ]);
    $assert($results[0]['ok'] && $results[1]['ok'] && !$results[1]['result'], 'legacy API transaction delays closure without blocking the destruction worker');

    $db = $connect(); $f = probeLossFixture($db, $directory);
    $admissionMs = 0.0;
    for ($i = 0; $i < 25; ++$i) {
        $started = hrtime(true);
        $ticket = $f['operations']->admit([$id]);
        $admissionMs += (hrtime(true) - $started) / 1e6;
        $f['operations']->execute($ticket, [$id], static fn() => null);
    }
    $probe = $f['probes']->findById($id); $probe->status = \VonNeumannGame\Domain\ProbeStatus::Dead; $f['probes']->save($probe);
    $started = hrtime(true);
    $f['losses']->handleTerminalProbeLoss($probe, 'missile_impact');
    $lossMs = (hrtime(true) - $started) / 1e6;
    $assert($f['lossRepository']->completed($id), sprintf('coordination timings (local isolated MariaDB): admission mean %.3f ms / 25, idle loss %.3f ms', $admissionMs / 25, $lossMs));
})();
