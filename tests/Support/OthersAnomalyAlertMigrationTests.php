<?php

declare(strict_types=1);

(static function ($test, string $root, string $tmp): void {
    $path = $tmp . '/anomaly-alert-migration.sqlite';
    $config = $tmp . '/anomaly-alert-migration.json';
    file_put_contents($config, json_encode(['driver' => 'sqlite', 'path' => $path], JSON_THROW_ON_ERROR));
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
    $db->exec("INSERT INTO players(username,created_at,updated_at) VALUES('anomaly-migration','2026-10-05','2026-10-05')");
    $others = new \VonNeumannGame\Repository\OthersRepository($db);
    $fleet = $others->createFleet(1, 0, 0, 0);
    $foreignFleet = $others->createFleet(1, 2, 0, 0);
    $fixtures = [];
    foreach (['unread', 'read', 'canonical', 'foreign'] as $index => $kind) {
        $ship = ($kind === 'foreign' ? $foreignFleet : $fleet)['ship']['public_id'];
        $alert = $others->createAlert(1, $ship, 'anomaly_detected', 'detection', 'migration-' . $kind, 'Wave ' . $kind);
        if ($kind === 'read') { $alert = $others->markAlertRead($alert); }
        if ($kind !== 'canonical') {
            $id = 'oalert_' . sprintf('%020x', $index + 1) . 'abcd';
            $db->prepare('UPDATE others_alerts SET public_id=? WHERE id=?')->execute([$id, $alert['id']]);
            $alert['public_id'] = $id;
        }
        $fixtures[$kind] = $alert;
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/one-shot-scripts/migrate-others-anomaly-alert-ids.php')
        . ' --database-config=' . escapeshellarg($config) . ' --fleet-id=' . escapeshellarg($fleet['public_id']);
    $run = static function (string $options) use ($command): array {
        exec($command . ' ' . $options . ' 2>&1', $output, $status);
        return [$status, implode("\n", $output)];
    };
    $snapshot = static fn(): array => $db->query('SELECT * FROM others_alerts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $before = $snapshot();
    [$status, $output] = $run('');
    $test->assertEquals(0, $status, 'anomaly identifier migration defaults to a successful dry run');
    $test->assert(str_contains($output, '"alerts":2'), 'migration selects only noncanonical alerts in the requested fleet');
    $test->assertEquals($before, $snapshot(), 'dry run leaves every alert field unchanged');
    $test->assertEquals(0, $run('--dry-run')[0], 'explicit dry run is accepted');
    $test->assert($run('--apply')[0] !== 0, 'applying identifier migration requires a backup');
    $backup = $tmp . '/anomaly-alert-backup.json';
    file_put_contents($backup, 'existing backup');
    $test->assert($run('--apply --backup=' . escapeshellarg($backup))[0] !== 0, 'migration refuses to overwrite a backup');
    $test->assertEquals($before, $snapshot(), 'backup failure leaves every alert unchanged');
    unlink($backup);
    $canonical = $fixtures['canonical'];
    $collisionId = substr($fixtures['unread']['public_id'], 0, 27);
    $db->prepare('UPDATE others_alerts SET public_id=? WHERE id=?')->execute([$collisionId, $canonical['id']]);
    $collisionSnapshot = $snapshot();
    $test->assert($run('--apply --backup=' . escapeshellarg($backup))[0] !== 0, 'migration rejects a collision with an existing canonical identifier');
    $test->assertEquals($collisionSnapshot, $snapshot(), 'collision aborts all changes');
    $db->prepare('UPDATE others_alerts SET public_id=? WHERE id=?')->execute([$canonical['public_id'], $canonical['id']]);
    [$status, $output] = $run('--apply --backup=' . escapeshellarg($backup));
    $test->assertEquals(0, $status, 'anomaly identifier migration applies successfully');
    $saved = json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
    $test->assertEquals([$fixtures['unread'], $fixtures['read']], array_column($saved['repairs'], 'before'), 'backup contains complete original alerts');
    $expected = $before;
    foreach ($expected as &$row) {
        if (in_array($row['id'], [$fixtures['unread']['id'], $fixtures['read']['id']], true)) {
            $row['public_id'] = substr($row['public_id'], 0, 27);
        }
    }
    unset($row);
    $test->assertEquals($expected, $snapshot(), 'migration preserves read status, timestamps, messages and unrelated alerts');
    [$status, $output] = $run('--apply --backup=' . escapeshellarg($backup));
    $test->assertEquals(0, $status, 'migration can be replayed without rewriting its backup');
    $test->assert(str_contains($output, '"alerts":0'), 'replayed migration has no remaining work');
    $test->assertEquals($expected, $snapshot(), 'replay leaves migrated alerts unchanged');
})($test, $root, $tmp);
