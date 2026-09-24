<?php

declare(strict_types=1);

use VonNeumannGame\Database\SchemaInitializer;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Service\AnomalyBroadcastService;
use VonNeumannGame\Service\GerminationDepotService;
use VonNeumannGame\Service\OthersService;
use VonNeumannGame\Service\SchedulerService;
use VonNeumannGame\Service\SectorEffectService;

(static function () use ($test, $reinstantiation, $probes, $movements, $movementService): void {
    foreach (['mothership', 'standard', 'construction'] as $case) {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA foreign_keys=ON');
        (new SchemaInitializer('sqlite'))->initialize($db);
        $others = new OthersRepository($db);
        $players = new PlayerRepository($db);
        $events = new ScheduledEventRepository($db);
        $coordinates = new SectorCoordinates(3, 4, 5);
        $owner = $players->createPlayer('destruction-owner', 'Destruction owner', null, $coordinates);
        $attacker = $players->createPlayer('destruction-attacker', 'Destruction attacker', null, $coordinates);
        $fleet = $others->createFleet($owner->id, 3, 4, 5);
        $mother = $fleet['ship'];
        $escort = $others->createStandardShip($mother);
        $target = $case === 'standard' ? $escort : $mother;
        $directory = sys_get_temp_dir() . '/vng-destruction-' . bin2hex(random_bytes(6));
        $files = new SectorFileRepository($directory);
        try {
            $files->save(new SectorContent($coordinates, [new Planet('harvest-planet', 'Harvest planet', 'rocky', 1.0, 1.0, true, 0.0, ['metals'], resourceAmounts: ['deuterium' => 0.0, 'metals' => 100.0, 'ice' => 0.0, 'carbon_compounds' => 0.0])]));
            $sectors = new SectorService($files, new SectorContentGenerator(), 'destruction-test', effects: new \VonNeumannGame\Repository\Storage\SectorEffectRepository($db));
            $transaction = new \VonNeumannGame\Database\StorageTransaction($db);
            $locks = new \VonNeumannGame\Repository\Storage\StorageLockRepository($db);
            $depotRepository = new \VonNeumannGame\Repository\GerminationDepotRepository($db);
            $detached = new \VonNeumannGame\Repository\DetachedStorageContainerRepository($db);
            $effects = new SectorEffectService(new \VonNeumannGame\Repository\Storage\SectorEffectRepository($db), $events, $sectors);
            $waves = new AnomalyBroadcastService(new \VonNeumannGame\Repository\Storage\AnomalyBroadcastRepository($db), $transaction, $locks, $events, new \VonNeumannGame\Repository\OthersAuditRepository($db));
            $depots = new GerminationDepotService($others, $events, $sectors, $effects, $waves, $transaction, $locks, new \VonNeumannGame\Repository\Storage\StorageActorRepository($db), $depotRepository, $detached);
            $service = new OthersService($others, $events, $reinstantiation, new \VonNeumannGame\Repository\Others\OthersPersistence($db), sectors: $sectors, players: $players, germinationDepots: $depots);
            $scheduler = new SchedulerService($events, $probes, $movements, $movementService, othersService: $service, sectorEffects: $effects);
            $harvests = [];
            foreach ([$mother, $escort] as $ship) {
                $harvest = $service->startHarvest($ship, ['targetObjectId' => 'harvest-planet', 'auxiliaryCount' => 1]);
                $service->processScheduledAction($events->findById((int) $harvest['scheduled_event_id']));
                $eventsRow = $db->prepare("UPDATE scheduled_events SET status='done' WHERE id=?");
                $eventsRow->execute([$harvest['scheduled_event_id']]);
                $harvests[] = $others->findActionByPublicId($harvest['public_id']);
            }
            $test->assertEquals(['succeeded', 'succeeded'], array_column($harvests, 'status'), $case . ': completed harvests leave historical swarm participants');
            $test->assertEquals(2, (int) $db->query('SELECT COUNT(*) FROM others_swarm_participants')->fetchColumn(), $case . ': both carriers retain their harvest participants before destruction');

            $deployedAuxiliary = $db->query('SELECT * FROM others_auxiliaries WHERE ship_id=' . (int) $escort['id'])->fetch();
            $activeHarvest = $service->startHarvest($others->findShipByPublicId($escort['public_id']), ['targetObjectId' => 'harvest-planet', 'auxiliaryCount' => 1]);
            $construction = null;
            if ($case === 'construction') {
                $db->prepare("UPDATE others_inventory_resources SET amount=10 WHERE ship_id=? AND resource_type='metals'")->execute([$mother['id']]);
                $builder = $db->query('SELECT * FROM others_auxiliaries WHERE ship_id=' . (int) $mother['id'])->fetch();
                $construction = $depots->build($others->findShipByPublicId($mother['public_id']), $builder, []);
            }

            // A probe missile already in flight, with a deterministic successful impact.
            $missile = 'missile_destruction_' . $case;
            for ($offset = 0; ; ++$offset) {
                $impactAt = gmdate('c', time() - 60 - $offset);
                $roll = hexdec(substr(hash('sha256', $missile . '|' . $target['public_id'] . '|' . $impactAt . '|hit'), 0, 8)) / 4294967296;
                if ($roll < 0.95) { break; }
            }
            $db->prepare('UPDATE others_ships SET integrity=10 WHERE id=?')->execute([$target['id']]);
            $db->prepare("INSERT INTO missile_launches (public_id,launcher_kind,launcher_public_id,player_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,projectile_public_id,launch_at,impact_at,created_at,updated_at) VALUES (?,'probe','1',?,?,'others_ship',3,4,5,'launched',?,?,?,?,?)")
                ->execute([$missile, $attacker->id, $target['public_id'], $missile, $impactAt, $impactAt, $impactAt, $impactAt]);
            $launchId = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO others_projectiles (public_id,launch_id,launcher_kind,launcher_public_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,launched_at,impact_at,created_at,updated_at) VALUES (?,?,'probe','1',?,'others_ship',3,4,5,'moving',?,?,?,?)")
                ->execute([$missile, $launchId, $target['public_id'], $impactAt, $impactAt, $impactAt, $impactAt]);
            $event = $events->schedule(SchedulerService::MISSILE_PROJECTILE, 'missile_projectile', (int) $db->lastInsertId(), $impactAt, ['projectileId' => $missile]);
            $db->prepare('UPDATE missile_launches SET scheduled_event_id=? WHERE id=?')->execute([$event->id, $launchId]);

            $stats = $scheduler->processDueEvents();
            $test->assertEquals(0, $stats['failed'], $case . ': scheduler resolves fatal missile with foreign keys enabled');
            $test->assertEquals('done', $events->findById($event->id)->status, $case . ': impact event completes');
            $destroyed = $others->findShipByPublicId($target['public_id']);
            $test->assertEquals('destroyed', $destroyed['status'], $case . ': target is destroyed');
            $test->assertEquals(0, (int) $destroyed['integrity'], $case . ': fatal damage persists');
            $test->assertEquals($impactAt, $destroyed['destroyed_at'], $case . ': destruction retains the causal impact time');
            $history = $db->query('SELECT * FROM others_projectile_history')->fetch();
            $test->assertEquals(true, json_decode($history['details_json'] ?? '{}', true)['destroyed'] ?? null, $case . ': missile history records destruction');
            $test->assertEquals('resolved', $db->query('SELECT status FROM missile_launches')->fetchColumn(), $case . ': missile launch is resolved');
            $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM others_projectiles')->fetchColumn(), $case . ': resolved projectile is removed');
            $test->assertEquals('failed', $others->findActionByPublicId($activeHarvest['public_id'])['status'], $case . ': active harvest stops with its carrier');
            $test->assertEquals('succeeded', $others->findActionByPublicId($harvests[1]['public_id'])['status'], $case . ': historical harvest result is preserved');
            $test->assert($files->load($coordinates)->findObjectById(DormantConstruct::fromOthersAuxiliary($deployedAuxiliary['public_id'])->getId()) instanceof DormantConstruct, $case . ': deployed auxiliary becomes dormant');
            $test->assertEquals([], $db->query('PRAGMA foreign_key_check')->fetchAll(), $case . ': destruction leaves no broken foreign keys');
            if ($case === 'standard') {
                $test->assertEquals('active', $db->query('SELECT status FROM others_fleets')->fetchColumn(), 'standard: its fleet survives');
                $test->assertEquals(1, (int) $db->query('SELECT COUNT(*) FROM others_swarm_participants')->fetchColumn(), 'standard: surviving mothership harvest participants are preserved');
            } else {
                $test->assertEquals('dissolved', $db->query('SELECT status FROM others_fleets')->fetchColumn(), $case . ': mothership loss dissolves the fleet');
                $test->assertEquals('removed', $others->findShipByPublicId($escort['public_id'])['status'], $case . ': escort is removed with its mothership');
                $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM others_auxiliaries')->fetchColumn(), $case . ': fleet auxiliaries are removed');
                $test->assertEquals(0, (int) $db->query('SELECT COUNT(*) FROM others_swarm_participants')->fetchColumn(), $case . ': deleted auxiliaries leave no swarm participants');
                $test->assert($files->load($coordinates)->findObjectById(DormantConstruct::fromOthersMothership($mother['public_id'])->getId()) instanceof DormantConstruct, $case . ': mothership wreck persists');
            }
            if ($construction !== null) {
                $test->assertEquals('canceled', $others->findActionByPublicId($construction['public_id'])['status'], 'construction: destruction interrupts the depot and removes its former harvester');
                $test->assertEquals(1, (int) $db->query("SELECT COUNT(*) FROM sector_effects WHERE effect_type='add_object'")->fetchColumn(), 'construction: builder becomes dormant through a durable sector effect');
            }

            // Requeue the same event to exercise scheduler retry without applying the kill twice.
            $db->prepare("UPDATE scheduled_events SET status='pending' WHERE id=?")->execute([$event->id]);
            $test->assertEquals(0, $scheduler->processDueEvents()['failed'], $case . ': replay succeeds');
            $test->assertEquals(1, (int) $db->query('SELECT COUNT(*) FROM others_projectile_history')->fetchColumn(), $case . ': replay does not duplicate missile history');
            $test->assertEquals(1, (int) $db->query('SELECT COUNT(*) FROM others_damage_events')->fetchColumn(), $case . ': replay does not duplicate damage');
            $test->assertEquals(1, $players->findById($attacker->id)->othersShipsDestroyed, $case . ': player kill counter increments exactly once');
            $test->assertEquals($case === 'standard' ? 0 : 1, $players->findById($attacker->id)->othersMothershipsDestroyed, $case . ': mothership counter increments only for its destruction');
        } finally {
            removeDirectory($directory);
        }
    }
})();
