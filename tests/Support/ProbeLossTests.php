<?php
declare(strict_types=1);

use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\ProbeAdmissionClosedException;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;

require_once __DIR__ . '/ProbeLossFixture.php';
(static function () use ($test): void {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
    (new SchemaInitializer('sqlite'))->initialize($db);
    $directory = sys_get_temp_dir() . '/vng-probe-loss-' . bin2hex(random_bytes(6));
    $f = probeLossFixture($db, $directory);
    $sector = new SectorCoordinates(840, 840, 0);
    $amounts = array_replace(\VonNeumannGame\Domain\MineableResourceAmounts::zero(), ['metals' => 1.0]);
    $rock = new Asteroid('loss-rock', 'Rock', 'iron', ['metals'], 'small', 1, 1, resourceAmounts: $amounts);
    $f['files']->save(new SectorContent($sector, [$rock]));
    (new StorageTransaction($db))->run(fn() => $f['stocks']->create('loss-rock-create', $rock->getId(), $amounts));
    [$probe, $manny] = miningAtomicActor($f, 'loss-admitted', $sector);
    $survivor = $f['probes']->createForPlayer($probe->playerId, 'Survivor', $sector);
    $manny = $f['mining']->startMining($probe, $manny->uid, $rock->getId(), 'metals', 0.1);
    $event = miningAtomicDue($f, $manny);
    $token = $f['operations']->admit([$probe->id]);
    $probe->status = ProbeStatus::Dead; $f['probes']->save($probe);
    $f['losses']->handleTerminalProbeLoss($probe, 'missile_impact');
    $test->assertEquals('draining', $f['lossRepository']->loss($probe->id)['state'], 'loss closes admission and defers while a ticket is active');
    $test->assert($f['probes']->findById($probe->id) !== null, 'drain retains the inventory used by an admitted operation');
    try { $f['operations']->admit([$probe->id]); $test->assert(false, 'closed admission rejects API work'); }
    catch (ProbeAdmissionClosedException) { $test->assert(true, 'closed admission rejects API work'); }
    $f['operations']->execute($token, [$probe->id], fn() => $f['mining']->processScheduledMiningTask($event));
    $test->assertEquals(0.9, $f['stocks']->find($rock->getId())['amounts']['metals'], 'admitted delivery finishes exactly once after closure');
    $test->assert($f['mannies']->findById($manny->id)->isOnProbe(), 'admitted miner is aboard in the resulting inventory');
    $f['losses']->resumeLoss($probe->id);
    $test->assertEquals(null, $f['mannies']->findById($manny->id), 'finalization deletes the returned Manny instead of publishing stale abandonment');
    $test->assertEquals(null, $f['probes']->findById($probe->id), 'drained loss deletes the probe');
    $test->assertEquals($survivor->id, $f['players']->findById($probe->playerId)->defaultProbeId, 'loss transfers the default instance');
    $alertCount = (int) $db->query('SELECT COUNT(*) FROM probe_damage_warnings')->fetchColumn();
    $f['losses']->handleTerminalProbeLoss($probe, 'laser_damage');
    $f['losses']->resumeLoss($probe->id);
    $test->assertEquals('missile_impact', $f['lossRepository']->loss($probe->id)['reason'], 'two loss causes preserve the first durable identity and cause');
    $test->assertEquals($alertCount, (int) $db->query('SELECT COUNT(*) FROM probe_damage_warnings')->fetchColumn(), 'replayed finalization cannot duplicate alerts');
    $test->assertEquals(0.9, $f['stocks']->find($rock->getId())['amounts']['metals'], 'destruction does not refund a committed harvest');
    try { $f['operations']->execute($token, [$probe->id], fn() => $db->exec('DELETE FROM probe_losses')); $test->assert(false, 'old ticket rejected'); }
    catch (ProbeAdmissionClosedException) { $test->assert(true, 'old ticket cannot execute after cleanup'); }

    [$second, $deployed] = miningAtomicActor($f, 'loss-before-admission', $sector);
    $f['probes']->createForPlayer($second->playerId, 'Survivor 2', $sector);
    $f['mining']->startMining($second, $deployed->uid, $rock->getId(), 'metals', 0.1);
    $pendingId = $f['mannies']->findById($deployed->id)->taskScheduledEventId;
    $second->status = ProbeStatus::Dead; $f['probes']->save($second);
    $f['losses']->handleTerminalProbeLoss($second, 'laser_damage');
    $test->assertEquals('completed', $f['lossRepository']->loss($second->id)['state'], 'pending mission is cancelled without waiting for its due date');
    $test->assertEquals(null, $f['events']->findById($pendingId), 'future Manny event is removed through cancellation and detach');
    $test->assertEquals(null, $f['mannies']->findById($deployed->id)->probeId, 'still deployed Manny is abandoned from its current state');
    $test->assertEquals(false, $f['mannies']->findById($deployed->id)->isForgotten, 'abandonment clears forgotten state');
    $test->assertEquals(0.9, $f['stocks']->find($rock->getId())['amounts']['metals'], 'closure before admission leaves source unchanged');

    [$last, $idle] = miningAtomicActor($f, 'loss-crashed-admission', $sector);
    $abandonedToken = $f['operations']->admit([$last->id]);
    $last->status = ProbeStatus::Dead; $f['probes']->save($last);
    $f['losses']->handleTerminalProbeLoss($last, 'movement_collision');
    $db->prepare('UPDATE probe_operations SET expires_at=0 WHERE token=?')->execute([$abandonedToken]);
    $test->assert($f['losses']->resumeLoss($last->id), 'crash between admission and transaction is recovered after the ticket expires');
    $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM probe_operations')->fetchColumn(), 'resolved tickets are purged');
    $test->assert($f['probes']->findById($last->id) !== null, 'last terminal probe remains a mind-reassignment anchor');
    $reassigned = $f['losses']->reassignMindSnapshot($f['players']->findById($last->playerId), $last);
    $test->assert($reassigned['probe']->id !== $last->id, 'last probe reassigns only after cleanup completed');
    $test->assertEquals(null, $f['probes']->findById($last->id), 'reassignment removes the terminal anchor');

    [$nested, $nestedManny] = miningAtomicActor($f, 'loss-nested', $sector);
    $db->beginTransaction();
    try { $f['mining']->startMining($nested, $nestedManny->uid, $rock->getId(), 'metals', 0.1); $test->assert(false, 'nested admission refused'); }
    catch (LogicException) { $test->assert($db->inTransaction(), 'nested admission refuses without committing the caller transaction'); }
    finally { $db->rollBack(); }
    $f['mining']->withPreparedBatch($nested, fn() => $f['mining']->startMining($nested, $nestedManny->uid, $rock->getId(), 'metals', 0.1), [$rock->getId()], true);
    $test->assertEquals(Manny::TASK_MINING, $f['mannies']->findById($nestedManny->id)->currentTask, 'batch owner admits before the transaction and reuses its ticket');
    [$batchProbe, $batchManny] = miningAtomicActor($f, 'loss-batch-rollback', $sector);
    $db->exec('CREATE TRIGGER fail_batch_manny BEFORE UPDATE ON mannies WHEN OLD.id=' . $batchManny->id . " AND NEW.current_task='mining' BEGIN SELECT RAISE(ABORT,'injected batch member failure'); END");
    $beforeEvents = (int) $db->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn();
    $f['mining']->withPreparedBatch($batchProbe, function () use ($f, $batchProbe, $batchManny, $rock): void {
        try { $f['mining']->startMining($batchProbe, $batchManny->uid, $rock->getId(), 'metals', 0.1); }
        catch (PDOException) { /* An API batch reports the refused member and continues. */ }
    }, [$rock->getId()], true);
    $db->exec('DROP TRIGGER fail_batch_manny');
    $test->assertEquals($beforeEvents, (int) $db->query('SELECT COUNT(*) FROM scheduled_events')->fetchColumn(), 'caught batch-member failure rolls back its event instead of committing orphan work');
    $test->assertEquals(null, $f['mannies']->findById($batchManny->id)->currentTask, 'caught batch-member failure preserves the actor');

    [$source, $courier] = miningAtomicActor($f, 'loss-transfer-source', $sector);
    [$target, $targetManny] = miningAtomicActor($f, 'loss-transfer-target', $sector);
    $source->deuteriumStock = 20; $f['probes']->save($source);
    $f['mining']->startDeuteriumTransferToProbe($source, $courier->uid, $target->id, 5);
    $target->status = ProbeStatus::Dead; $f['probes']->save($target);
    $f['losses']->handleTerminalProbeLoss($target, 'missile_impact');
    $test->assertEquals(20.0, $f['probes']->findById($source->id)->deuteriumStock, 'incoming pending fuel transfer restores its source commitment');
    $test->assertEquals(null, $f['mannies']->findById($courier->id)->currentTask, 'incoming transfer is cancelled at the source');
    $f['losses']->resumeLoss($target->id);
    $test->assertEquals(20.0, $f['probes']->findById($source->id)->deuteriumStock, 'incoming cancellation replay cannot refund twice');

    // Raw caller transaction: crash after durable request, before any after-commit callback.
    [$crashed, $crashedManny] = miningAtomicActor($f, 'loss-request-crash', $sector);
    $ticket = $f['operations']->admit([$crashed->id]);
    $db->beginTransaction();
    $crashed->status = ProbeStatus::Dead; $f['probes']->save($crashed);
    $f['losses']->handleTerminalProbeLoss($crashed, 'missile_impact');
    $db->commit();
    $test->assertEquals('requested', $f['lossRepository']->loss($crashed->id)['state'], 'raw transaction leaves a durable request without relying on callbacks');
    $movementRepo = new \VonNeumannGame\Repository\ProbeMovementRepository($db);
    $movement = new \VonNeumannGame\Service\ProbeMovementService($f['probes'], $movementRepo, new \VonNeumannGame\Repository\VisitedSectorRepository($db), $f['events'], $f['sectors'], reinstantiation: $f['losses']);
    $scheduler = new \VonNeumannGame\Service\SchedulerService($f['events'], $f['probes'], $movementRepo, $movement, $f['mining']);
    $unrelated = $f['events']->schedule('probe.black_hole.trap', 'probe', 999999, gmdate('c'));
    $stats = $scheduler->processDueEvents();
    $test->assert($stats['deferred'] >= 1 && $stats['processed'] >= 1, 'single worker defers destruction and continues unrelated events');
    $test->assertEquals('done', $f['events']->findById($unrelated->id)->status, 'draining does not starve unrelated probes');
    $db->prepare('UPDATE probe_operations SET expires_at=0 WHERE token=?')->execute([$ticket]);
    $scheduler->recoverExpiredRunningEvents(60);
    $lossEvent = $f['lossRepository']->loss($crashed->id)['scheduled_event_id'];
    $db->prepare('UPDATE scheduled_events SET run_at=? WHERE id=?')->execute([gmdate('c', time() - 1), $lossEvent]);
    $scheduler->processDueEvents();
    $test->assertEquals('completed', $f['lossRepository']->loss($crashed->id)['state'], 'scheduler resumes a crashed loss after orphan-ticket recovery');

    [$faulted] = miningAtomicActor($f, 'loss-finalize-crash', $sector);
    $f['probes']->createForPlayer($faulted->playerId, 'Fault survivor', $sector);
    $faulted->status = ProbeStatus::Dead; $f['probes']->save($faulted);
    $db->exec('CREATE TRIGGER fail_loss_delete BEFORE DELETE ON neumann_probes WHEN OLD.id=' . $faulted->id . " BEGIN SELECT RAISE(ABORT,'injected finalization failure'); END");
    $beforeAlerts = (int) $db->query('SELECT COUNT(*) FROM probe_damage_warnings')->fetchColumn();
    $f['losses']->handleTerminalProbeLoss($faulted, 'missile_impact');
    $test->assert($f['probes']->findById($faulted->id) !== null && !$f['lossRepository']->completed($faulted->id), 'failure during finalization rolls back physical deletion and completion');
    $test->assertEquals($beforeAlerts, (int) $db->query('SELECT COUNT(*) FROM probe_damage_warnings')->fetchColumn(), 'failed finalization rolls back its notification');
    $db->exec('DROP TRIGGER fail_loss_delete');
    $f['losses']->resumeLoss($faulted->id);
    $test->assertEquals($beforeAlerts + 1, (int) $db->query('SELECT COUNT(*) FROM probe_damage_warnings')->fetchColumn(), 'recovery publishes exactly one finalization notification');

    [$account] = miningAtomicActor($f, 'loss-account', $sector);
    $ticket = $f['operations']->admit([$account->id]);
    $deletion = new \VonNeumannGame\Auth\AccountDeletionService($db, $f['probes'], $f['mannies'], $f['sectors'], $f['mining']);
    try { $deletion->deletePlayer($f['players']->findById($account->playerId)); $test->assert(false, 'account deletion waits for admitted work'); }
    catch (RuntimeException) { $test->assert($f['probes']->findById($account->id) !== null, 'account deletion leaves inventory intact when an operation is admitted'); }
    $f['operations']->execute($ticket, [$account->id], static fn() => null);
    $deletion->deletePlayer($f['players']->findById($account->playerId));
    $test->assertEquals(null, $f['players']->findById($account->playerId), 'account deletion succeeds after the operation resolves');

    // Migration is explicit and repeatable, preserving closed tombstones.
    $db->prepare('DELETE FROM probe_operation_gates WHERE probe_id=?')->execute([$survivor->id]);
    try { $f['operations']->admit([$survivor->id]); $test->assert(false, 'missing canonical guard must not be recreated by a writer'); }
    catch (ProbeAdmissionClosedException) { $test->assert(true, 'missing canonical guard is rejected without a runtime fallback'); }

    $migrationDb = new PDO('sqlite:' . $directory . '/migration.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new SchemaInitializer('sqlite'))->initialize($migrationDb);
    $migrationPlayers = new \VonNeumannGame\Repository\PlayerRepository($migrationDb);
    $migrationProbes = new \VonNeumannGame\Repository\NeumannProbeRepository($migrationDb);
    $migrationPlayer = $migrationPlayers->createPlayer('loss-migration', null);
    $oldProbe = $migrationProbes->createForPlayer($migrationPlayer->id, 'Existing probe', $sector);
    $oldDead = $migrationProbes->createForPlayer($migrationPlayer->id, 'Existing dead probe', $sector);
    $oldDead->status = ProbeStatus::Dead; $migrationProbes->save($oldDead);
    $migrationDb->exec('DELETE FROM probe_operation_gates');
    $configPath = $directory . '/migration-config.json';
    file_put_contents($configPath, json_encode(['driver' => 'sqlite', 'path' => $directory . '/migration.sqlite'], JSON_THROW_ON_ERROR));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/one-shot-scripts/migrate-probe-operations.php') . ' --database-config=' . escapeshellarg($configPath);
    exec($command . ' --dry-run 2>&1', $output, $status);
    $test->assertEquals(0, $status, 'probe coordination migration dry run succeeds');
    $test->assertEquals(0, (int) $migrationDb->query('SELECT COUNT(*) FROM probe_operation_gates')->fetchColumn(), 'migration dry run does not seed guards');
    exec($command . ' --apply 2>&1', $output, $status);
    $test->assertEquals(0, $status, 'probe coordination migration applies to existing data');
    exec($command . ' --apply 2>&1', $output, $status);
    $test->assertEquals(0, $status, 'probe coordination migration is repeatable');
    $test->assertEquals(2, (int) $migrationDb->query('SELECT COUNT(*) FROM probe_operation_gates')->fetchColumn(), 'migration seeds one guard per existing probe');
    $test->assertEquals(1, (int) $migrationDb->query("SELECT COUNT(*) FROM scheduled_events WHERE type='probe.loss'")->fetchColumn(), 'migration queues existing terminal cleanup only once');
    $test->assertEquals(1, (int) $migrationDb->query('SELECT closed FROM probe_operation_gates WHERE probe_id=' . $oldDead->id)->fetchColumn(), 'migration never reopens the terminal guard');
})();
