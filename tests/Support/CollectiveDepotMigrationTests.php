<?php

declare(strict_types=1);

(static function () use ($test): void {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
    $statements = (new ReflectionMethod(\VonNeumannGame\Database\SchemaInitializer::class, 'statements'))->invoke(new \VonNeumannGame\Database\SchemaInitializer('sqlite'));
    foreach ($statements as $sql) { $db->exec(str_replace("('others_auxiliary','others_ship','manny')", "('others_auxiliary','manny')", $sql)); }
    $db->exec("INSERT INTO sector_storage_transfers(id,public_id,player_id,actor_kind,actor_public_id,external_storage_kind,external_storage_id,direction,status,manifest_json,resources_json,items_json,started_at,ends_at,updated_at) VALUES(7,'migration-transfer',1,'others_auxiliary','migration-auxiliary','depot','1','to_storage','queued','{}','{}','[]','start','end','start')");
    $db->exec("INSERT INTO sector_storage_resource_reservations(transfer_id,inventory_kind,inventory_id,resource_type,amount) VALUES(7,'ship','1','metals',2)");
    $snapshot = $db->query('SELECT * FROM sector_storage_transfers')->fetchAll();
    $migration = new \VonNeumannGame\Database\Migration\CollectiveDepotSchemaMigration($db);
    $test->assertEquals(true, $migration->run(false)['changeRequired'], 'collective migration detects the old actor constraint without writing');
    $test->assertEquals(true, $migration->run(true)['changeRequired'], 'collective migration expands the actor constraint');
    $test->assertEquals($snapshot, $db->query('SELECT * FROM sector_storage_transfers')->fetchAll(), 'collective migration preserves existing transfer rows exactly');
    $test->assertEquals(1, (int) $db->query('SELECT COUNT(*) FROM sector_storage_resource_reservations')->fetchColumn(), 'collective migration preserves live reservation foreign keys');
    $test->assertEquals(1, (int) $db->query('PRAGMA foreign_keys')->fetchColumn(), 'collective migration restores foreign key enforcement');
    $test->assertEquals(false, $db->query('PRAGMA foreign_key_check')->fetch(), 'collective migration leaves valid foreign keys');
    $test->assertEquals(false, $migration->run(true)['changeRequired'], 'collective migration replay is a no-op');
    $db->exec("UPDATE sector_storage_transfers SET actor_kind='others_ship' WHERE id=7");
    try { $db->exec("UPDATE sector_storage_transfers SET actor_kind='invalid' WHERE id=7"); $test->assert(false, 'unknown storage actor must remain forbidden'); }
    catch (PDOException) { $test->assert(true, 'collective migration retains the actor constraint'); }
}) ();
