<?php

declare(strict_types=1);

// Exercise the item deletion with the same foreign-key enforcement as production.
(static function () use ($pdo, $test, $players, $probes, $mannies, $items, $others, $othersService, $processScheduledMannyNow, $processOthersActionNow): void {
    $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
    $pdo->exec('PRAGMA foreign_keys=ON');
    try {
        $test->assertEquals(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'missile retry tests enforce foreign keys');
        $sector = new \VonNeumannGame\Sector\SectorCoordinates(83521, -83521, 0);
        $player = $players->createPlayer('missile-retry-owner', 'Missile Retry Owner', null, $sector);
        $othersLauncherShip = $others->createFleet($player->id, $sector->getX(), $sector->getY(), $sector->getZ())['ship'];
        $createProbe = static function (string $name) use ($probes, $player, $sector): \VonNeumannGame\Domain\NeumannProbe {
            $probe = $probes->createForPlayer($player->id, $name, $sector);
            $probe->excludeFromStats = true;
            $probes->save($probe);
            return $probe;
        };
        $carrier = $createProbe('Missile retry carrier');
        $operator = $mannies->createForProbe($carrier->id, 'Missile retry operator');
        $item = $items->create($carrier->id, \VonNeumannGame\Domain\ProbeItem::TYPE_MISSILE, 'Retry missile', 0.05);
        $nextTarget = $createProbe('Missile retry surviving target');
        $readLaunch = static function (string $id) use ($pdo): array {
            $stmt = $pdo->prepare('SELECT * FROM missile_launches WHERE public_id=?');
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('Retry launch not found.');
        };

        foreach (['probe', 'others'] as $launcherKind) {
            $target = $createProbe('Missile retry lost target ' . $launcherKind);
            if ($launcherKind === 'others') {
                $itemUid = \VonNeumannGame\Repository\OthersRepository::publicId('item');
                $pdo->prepare("INSERT INTO others_inventory_items (public_id,ship_id,type,container_space,created_at,updated_at) VALUES (?,?,'missile',2,?,?)")
                    ->execute([$itemUid, $othersLauncherShip['id'], gmdate('c'), gmdate('c')]);
            } else {
                $itemUid = $item->uid;
            }
            $prepare = static function (string $targetId) use ($launcherKind, $othersService, $carrier, $player, $operator, $itemUid, $othersLauncherShip): array {
                if ($launcherKind === 'probe') {
                    return $othersService->prepareProbeMissile($carrier, $player->id, ['actorMannyId' => $operator->uid, 'missileItemId' => $itemUid, 'targetId' => $targetId]);
                }
                return $othersService->launchOthersMissile($othersLauncherShip, ['missileItemId' => $itemUid, 'targetId' => $targetId]);
            };
            $complete = static function (array $launch) use ($launcherKind, $processScheduledMannyNow, $operator, $processOthersActionNow): void {
                if ($launcherKind === 'probe') {
                    $processScheduledMannyNow($operator->id);
                } else {
                    $processOthersActionNow($launch['action']);
                }
            };
            $launchId = static fn(array $launch): string => (string) ($launcherKind === 'probe' ? $launch['public_id'] : $launch['missile']['public_id']);
            $failed = $prepare((string) $target->id);
            $target->status = \VonNeumannGame\Domain\ProbeStatus::Dead;
            $probes->save($target);
            $complete($failed);
            $history = $readLaunch($launchId($failed));
            $test->assertEquals('failed', $history['status'], "$launcherKind missile preparation fails when its target disappears");
            $test->assertEquals('launch_preconditions_lost', $history['result'], "$launcherKind missile history retains the failure reason");
            $test->assertEquals((string) $target->id, $history['target_public_id'], "$launcherKind missile history retains the target identity");
            $itemColumn = $launcherKind === 'probe' ? 'probe_item_id' : 'others_item_id';
            $test->assertEquals(null, $history[$itemColumn], "$launcherKind failed preparation releases its inventory reference");
            $inventorySql = $launcherKind === 'probe' ? 'SELECT COUNT(*) FROM probe_items WHERE uid=?' : 'SELECT COUNT(*) FROM others_inventory_items WHERE public_id=?';
            $inventory = $pdo->prepare($inventorySql);
            $inventory->execute([$itemUid]);
            $test->assertEquals(1, (int) $inventory->fetchColumn(), "$launcherKind failed preparation preserves the available missile");
            $inventory->closeCursor();
            if ($launcherKind === 'probe') {
                $test->assertEquals(null, $mannies->findById($operator->id)?->currentTask, 'target loss frees the missile operator');
            }

            $retry = $prepare((string) $nextTarget->id);
            $complete($retry);
            $history = $readLaunch($launchId($retry));
            $test->assertEquals('launched', $history['status'], "$launcherKind can launch the same missile after a failed preparation");
            $inventory->execute([$itemUid]);
            $test->assertEquals(0, (int) $inventory->fetchColumn(), "$launcherKind retry consumes the missile");
            $inventory->closeCursor();
            $projectiles = $pdo->prepare('SELECT COUNT(*) FROM others_projectiles WHERE launch_id=?');
            $projectiles->execute([$history['id']]);
            $test->assertEquals(1, (int) $projectiles->fetchColumn(), "$launcherKind retry creates exactly one projectile");
            $projectiles->closeCursor();
            if ($launcherKind === 'probe') {
                $test->assertEquals(null, $mannies->findById($operator->id)?->currentTask, 'successful retry frees the missile operator');
            }
        }
    } finally {
        $pdo->exec('PRAGMA foreign_keys=' . $foreignKeys);
    }
})();
