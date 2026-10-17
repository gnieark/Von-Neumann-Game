<?php

declare(strict_types=1);

use VonNeumannGame\Database\Migration\MineableObjectStockAudit;
use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\AsteroidTrajectoryRepository;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\Storage\SectorEffectRepository;
use VonNeumannGame\Sector\SectorCoordinates;

(static function () use ($test): void {
    $directory = sys_get_temp_dir() . '/vng-mineable-audit-' . bin2hex(random_bytes(6));
    mkdir($directory . '/sectors', 0700, true);
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    (new SchemaInitializer('sqlite'))->initialize($pdo);
    $zero = array_fill_keys(ResourceComposition::TYPES, 0.0);
    $asteroid = ['id' => 'stock-a', 'type' => 'asteroid', 'resourceAmounts' => array_replace($zero, ['metals' => 12.3456])];
    $wreck = ['id' => 'stock-w', 'type' => 'dormant_construct', 'subtype' => 'others_mothership_wreck', 'resourceAmounts' => $zero];
    $objects = [$asteroid, ['id' => 'system-a', 'type' => 'solar_system', 'orbitalBodies' => [['object' => $wreck]]],
        ['id' => 'aux', 'type' => 'dormant_construct', 'subtype' => 'others_auxiliary', 'resourceAmounts' => array_replace($zero, ['metals' => 5.0])],
        ['id' => 'planet', 'type' => 'planet', 'resourceAmounts' => array_replace($zero, ['metals' => 10000.0])]];
    $write = static function (array $objects, string $name = 'sector.json', array $sector = ['x' => 0, 'y' => 0, 'z' => 0]) use ($directory): void {
        file_put_contents($directory . '/sectors/' . $name, json_encode(['coordinates' => $sector, 'objects' => $objects], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    };
    $audit = static function (int $maxFiles = 100, int $maxRows = 1000, bool $missingResourcesAreExhausted = false) use ($pdo, $directory): array {
        $pdo->exec('PRAGMA query_only=ON');
        $pdo->beginTransaction();
        try { return (new MineableObjectStockAudit($maxFiles, $maxRows, $missingResourcesAreExhausted))->inspect($pdo, $directory); }
        finally { $pdo->rollBack(); $pdo->exec('PRAGMA query_only=OFF'); }
    };
    $codes = static fn(array $report): array => array_column($report['anomalies'], 'code');
    try {
        $write($objects);
        $before = hash_file('sha256', $directory . '/sectors/sector.json');
        $report = $audit();
        $test->assert($report['inventoryValid'], 'mineable audit accepts explicit four-resource stocks');
        $test->assertEquals(2, $report['uniqueSourceIds'], 'mineable audit includes nested exhausted wrecks and excludes planets and auxiliary constructs');
        $test->assertEquals(1, $report['counts']['mothershipWrecksExhausted'], 'exhausted mineable categories remain in the inventory');
        $test->assertEquals(12.3456, $report['totals']['metals'], 'mineable audit totals count authoritative sources only');
        $test->assertEquals($before, hash_file('sha256', $directory . '/sectors/sector.json'), 'audit does not rewrite sector files');
        $test->assertEquals(0, (int) $pdo->query('SELECT COUNT(*) FROM sector_effects')->fetchColumn(), 'audit does not enqueue effects');

        $trajectories = new AsteroidTrajectoryRepository($pdo);
        $createTrajectory = static function (array $snapshot, string $status) use ($trajectories, $pdo): void {
            $trajectory = $trajectories->create(['asteroidId' => $snapshot['id'], 'asteroidSnapshot' => $snapshot, 'mode' => 'sector_transfer', 'status' => $status,
                'originSector' => new SectorCoordinates(0, 0, 0), 'asteroidMassEarth' => 0.01, 'nextTransitionAt' => gmdate('c'), 'maximumSectorCrossings' => 20]);
            // Audit fixture deliberately represents the pre-migration storage format.
            $pdo->prepare('UPDATE asteroid_trajectories SET asteroid_snapshot_json=? WHERE id=?')->execute([json_encode($snapshot, JSON_THROW_ON_ERROR), $trajectory->id]);
        };
        $createTrajectory(array_replace($asteroid, ['resourceAmounts' => array_replace($zero, ['metals' => 900.0])]), 'completed');
        $transit = array_replace($asteroid, ['id' => 'in-transit', 'resourceAmounts' => array_replace($zero, ['ice' => 0.1])]);
        $createTrajectory($transit, 'crossing_sector');
        $report = $audit();
        $test->assert($report['inventoryValid'], 'historical trajectory stock divergence is not a current stock conflict');
        $test->assertEquals(12.3456, $report['totals']['metals'], 'historical snapshots do not replenish current stocks');
        $test->assertEquals(0.1, $report['totals']['ice'], 'active transit snapshot contributes its stock exactly once');
        $test->assertEquals(1, count($report['snapshots']), 'historical trajectory is listed separately from current sources');
        $test->assert(in_array('row_limit', $codes($audit(maxRows: 1)), true), 'bounded SQL audit cannot silently report a truncated inventory as valid');

        $write([...$objects, $transit]);
        $test->assert(in_array('transit_also_in_sector', $codes($audit()), true), 'simultaneous transit and file presence blocks automatic authority selection');
        $write($objects);
        $write([$asteroid], 'duplicate.json', ['x' => 1, 'y' => 1, 'z' => 0]);
        $report = $audit();
        $test->assert(in_array('duplicate_identity', $codes($report), true), 'duplicate identities across sectors are inventoried per occurrence');
        $test->assertEquals(24.6912, $report['totals']['metals'], 'duplicate real sources retain separate stocks for a future renaming manifest');
        $test->assert(in_array('file_limit', $codes($audit(maxFiles: 1)), true), 'bounded file audit reports incomplete coverage');
        unlink($directory . '/sectors/duplicate.json');

        $invalid = $asteroid;
        unset($invalid['resourceAmounts']);
        $invalid['mass'] = 999.0;
        $write([$invalid]);
        $report = $audit();
        $test->assert(in_array('invalid_stock_shape', $codes($report), true), 'missing JSON stock is not inferred from asteroid mass during migration audit');
        $test->assertEquals(null, $report['sources'][0]['amounts'], 'missing stock is not replaced by an invented zero during import audit');
        $report = $audit(missingResourcesAreExhausted: true);
        $test->assertEquals($zero, $report['sources'][0]['amounts'], 'explicit exhausted-resource migration policy supplies four zeros without consulting mass');
        $test->assertEquals('exhausted_missing_stock', $report['normalizations'][0]['reason'], 'explicit zero import remains listed in the migration inventory');
        $partial = $asteroid;
        unset($partial['resourceAmounts']['ice']);
        $write([$partial]);
        $report = $audit(missingResourcesAreExhausted: true);
        $test->assertEquals(12.3456, $report['sources'][0]['amounts']['metals'], 'missing-resource zero policy preserves existing positive stocks');
        $test->assertEquals(['ice'], $report['normalizations'][0]['resources'], 'partial exhausted-resource normalization lists only the missing resource');
        foreach ([-0.0001, '10', true] as $value) {
            $invalid['resourceAmounts'] = array_replace($zero, ['metals' => $value]);
            $write([$invalid]);
            $test->assert(in_array('invalid_stock_value', $codes($audit()), true), 'audit rejects negative or nonnumeric canonical resource types');
        }
        $invalid['resourceAmounts'] = array_replace($zero, ['metals' => 549755813888.0]);
        $write([$invalid]);
        $test->assert(in_array('four_decimal_resolution_lost', $codes($audit()), true), 'audit flags floats beyond four-decimal resolution');
        $test->assertEquals('0.0001220703', sprintf('%.10f', (549755813888.0 + 0.0001) - 549755813888.0), 'float precision limit has a reproducible 0.0001 counterexample');
        file_put_contents($directory . '/sectors/sector.json', '{"coordinates":{"x":0,"y":0,"z":0},"objects":[{"id":"overflow","type":"asteroid","resourceAmounts":{"deuterium":0,"metals":1e400,"ice":0,"carbon_compounds":0}}]}');
        $test->assert(in_array('invalid_stock_value', $codes($audit()), true), 'audit detects nonfinite numbers decoded from JSON exponents');

        $write($objects);
        $effects = new SectorEffectRepository($pdo);
        $effects->create('audit-pending-wreck', new SectorCoordinates(0, 0, 0), 'add_object', 'pending-wreck', array_replace($wreck, ['id' => 'pending-wreck', 'resourceAmounts' => array_replace($zero, ['metals' => 77.0])]), gmdate('c'));
        $report = $audit();
        $test->assert(in_array('pending_sector_effect', $codes($report), true), 'pending intentions require explicit drain or migration');
        $test->assertEquals(12.3456, $report['totals']['metals'], 'unpublished intention does not establish authoritative sector presence');

        $player = (new PlayerRepository($pdo))->createPlayer('audit-player', null);
        $probe = (new NeumannProbeRepository($pdo))->createForPlayer($player->id, 'Audit probe');
        $manny = (new MannyRepository($pdo))->createForProbe($probe->id, 'Audit Manny');
        $pdo->prepare("UPDATE mannies SET location_type='sector', sector_x=0, sector_y=0, sector_z=0 WHERE id=?")->execute([$manny->id]);
        $forgotten = ['id' => 'manny-' . $manny->uid, 'type' => 'manny', 'mannyUid' => $manny->uid, 'state' => 'forgotten', 'cargo' => ['deuterium' => 0.0, 'metals' => 0.0, 'ice' => 0.0, 'organicCompounds' => 0.0]];
        $write([...$objects, $forgotten, array_replace($forgotten, ['id' => 'abandoned', 'mannyUid' => 'abandoned', 'state' => 'abandoned'])]);
        $report = $audit();
        $test->assertEquals(1, count($report['forgottenMannies']), 'forgotten inventory excludes abandoned Mannies');
        $test->assertEquals([], $report['forgottenMannies'][0]['differences'], 'forgotten JSON is compared with its existing SQL Manny');
        $pdo->prepare('UPDATE mannies SET cargo_metals=0.1 WHERE id=?')->execute([$manny->id]);
        $report = $audit();
        $test->assert(in_array('forgotten_cargo_requires_review', $codes($report), true), 'stale cargo divergence requires a documented decision instead of copying or adding');
        $test->assertEquals(0.1, (float) $pdo->query('SELECT cargo_metals FROM mannies')->fetchColumn(), 'audit never changes authoritative Manny cargo');
        $pdo->prepare('UPDATE mannies SET probe_id=NULL WHERE id=?')->execute([$manny->id]);
        $test->assert(in_array('forgotten_manny_owner_lost', $codes($audit()), true), 'left joins preserve forgotten Mannies whose owner disappeared');
        $write([...$objects, $forgotten, $forgotten]);
        $test->assert(in_array('duplicate_forgotten_manny', $codes($audit()), true), 'duplicate forgotten presences block migration');
    } finally {
        foreach (glob($directory . '/sectors/*') as $path) { unlink($path); }
        rmdir($directory . '/sectors');
        rmdir($directory);
    }
})();
