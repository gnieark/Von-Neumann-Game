<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Others;

use PDO;

final class CombatRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string|int, mixed> $parameters */
    public function findAvailableMissile(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT * FROM others_inventory_items WHERE ship_id=:ship_id AND public_id=:public_id AND type='missile' AND reserved_action_id IS NULL");
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function createOthersLaunch(array $parameters): int
    {
        $statement = $this->pdo->prepare("INSERT INTO missile_launches (public_id,launcher_kind,launcher_public_id,player_id,probe_id,manny_id,probe_item_id,others_action_id,others_item_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,projectile_public_id,launch_at,impact_at,result,scheduled_event_id,created_at,updated_at) VALUES (:public_id,'others_ship',:launcher,:player_id,NULL,NULL,NULL,:action_id,:item_id,:target,:kind,:x,:y,:z,'queued',NULL,:launch_at,NULL,NULL,NULL,:created_at,:updated_at)");
        $statement->execute($parameters);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string|int, mixed> $parameters */
    public function reserveItem(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_items SET reserved_action_id=:action_id,updated_at=:now WHERE id=:id AND reserved_action_id IS NULL');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function attachLaunchEvent(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE missile_launches SET scheduled_event_id=:event_id WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function probeMissileReserved(array $parameters): mixed
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM missile_launches WHERE probe_item_id=:item_id AND status IN ('preparing','queued') LIMIT 1");
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /** @param array<string|int, mixed> $parameters */
    public function createProbeLaunch(array $parameters): int
    {
        $statement = $this->pdo->prepare("INSERT INTO missile_launches (public_id,launcher_kind,launcher_public_id,player_id,probe_id,manny_id,probe_item_id,others_action_id,others_item_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,projectile_public_id,launch_at,impact_at,result,scheduled_event_id,created_at,updated_at) VALUES (:public_id,'probe',:launcher,:player_id,:probe_id,:manny_id,:item_id,NULL,NULL,:target,:kind,:x,:y,:z,'preparing',NULL,:launch_at,NULL,NULL,NULL,:created_at,:updated_at)");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function attachProbeLaunchEvent(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE missile_launches SET scheduled_event_id=:event_id WHERE public_id=:public_id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function countActiveLasers(array $parameters): mixed
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM others_laser_locks WHERE ship_id=:ship_id AND status IN ('queued','active')");
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /** @param array<string|int, mixed> $parameters */
    public function createLaser(array $parameters): int
    {
        $statement = $this->pdo->prepare("INSERT INTO others_laser_locks (action_id,ship_id,target_kind,target_public_id,sector_x,sector_y,sector_z,status,started_at,accounted_until,next_damage_at,exhausts_at,created_at,updated_at) VALUES (:action_id,:ship_id,:kind,:target,:x,:y,:z,'queued',NULL,NULL,NULL,NULL,:now,:now)");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function createCraftedMissile(array $parameters): int
    {
        $statement = $this->pdo->prepare("INSERT INTO others_inventory_items (public_id,ship_id,type,container_space,name,metadata_json,reserved_action_id,created_at,updated_at) VALUES (:public_id,:ship_id,'missile',2,'Missile Others',:metadata,NULL,:now,:now)");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findLaser(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT l.*,s.public_id AS ship_public_id,s.status AS ship_status,s.destroyed_at,s.deuterium_stock FROM others_laser_locks l JOIN others_ships s ON s.id=l.ship_id WHERE l.action_id=:action_id');
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function startLaser(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_laser_locks SET status='active',started_at=:now,accounted_until=:now,next_damage_at=:damage,exhausts_at=:exhausts,updated_at=:now WHERE id=:id AND status='queued'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function debitLaserFuel(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET deuterium_stock=CASE WHEN deuterium_stock > :stock_floor THEN deuterium_stock - :stock_decrease ELSE 0 END,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function accountLaserDamage(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_laser_locks SET accounted_until=:now,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function recordDamage(array $parameters): bool
    {
        $statement = $this->pdo->prepare('INSERT INTO others_damage_events (event_key,target_kind,target_public_id,damage,created_at) VALUES (:key,:kind,:target,:damage,:now)');
        try { $statement->execute($parameters); return true; }
        catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062 || str_contains(strtolower($error->getMessage()), 'unique')) { return false; }
            throw $error;
        }
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteSectorManny(array $parameters): int
    {
        $statement = $this->pdo->prepare('DELETE FROM mannies WHERE uid=:uid AND location_type=\'sector\'');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function scheduleLaserDamage(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_laser_locks SET next_damage_at=:next,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function stopLaser(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_laser_locks SET status='stopped',updated_at=:now WHERE id=:id AND status IN ('queued','active')");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function setLaserCooldown(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET laser_next_target_at=:next,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findPreparingProbeLaunch(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT * FROM missile_launches WHERE public_id=:public_id AND status='preparing'");
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function failPreparingProbeLaunch(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE missile_launches SET status='failed',result='launch_preconditions_lost',updated_at=:now WHERE id=:id AND status='preparing'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findProbeMissile(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT * FROM probe_items WHERE id=:id AND probe_id=:probe_id AND type='missile'");
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function failMissingMissile(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE missile_launches SET status='failed',result='missile_item_lost',updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function detachProbeMissile(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE missile_launches SET probe_item_id=NULL,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteProbeMissile(array $parameters): int
    {
        $statement = $this->pdo->prepare('DELETE FROM probe_items WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findQueuedOthersLaunch(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT * FROM missile_launches WHERE others_action_id=:action_id AND status='queued'");
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function releaseLaunchMissile(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_items SET reserved_action_id=NULL,updated_at=:now WHERE id=:id AND reserved_action_id=:action_id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function failOthersLaunch(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE missile_launches SET status='failed',result='launch_preconditions_lost',updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function detachOthersMissile(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE missile_launches SET others_item_id=NULL,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function consumeLaunchMissile(array $parameters): int
    {
        $statement = $this->pdo->prepare("DELETE FROM others_inventory_items WHERE id=:id AND reserved_action_id=:action_id AND type='missile'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function createProjectile(array $parameters): int
    {
        $statement = $this->pdo->prepare("INSERT INTO others_projectiles (public_id,launch_id,action_id,launcher_kind,launcher_public_id,target_public_id,target_kind,sector_x,sector_y,sector_z,status,launched_at,impact_at,created_at,updated_at) VALUES (:public_id,:launch_id,:action_id,:launcher_kind,:launcher_public_id,:target_public_id,:target_kind,:x,:y,:z,'moving',:launched_at,:impact_at,:created_at,:updated_at)");
        $statement->execute($parameters);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string|int, mixed> $parameters */
    public function launchMissile(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE missile_launches SET status='launched',projectile_public_id=:projectile,impact_at=:impact_at,scheduled_event_id=:event_id,updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function recordProjectileHistory(array $parameters): int
    {
        $statement = $this->pdo->prepare('INSERT INTO others_projectile_history (projectile_public_id,action_public_id,result,details_json,resolved_at) VALUES (:projectile,:action,:result,:details,:resolved_at)');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function resolveLaunch(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE missile_launches SET status='resolved',result=:result,updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteProjectile(array $parameters): int
    {
        $statement = $this->pdo->prepare('DELETE FROM others_projectiles WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteImpactedManny(array $parameters): int
    {
        $statement = $this->pdo->prepare("DELETE FROM mannies WHERE uid=:uid AND location_type='sector'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function incrementAsteroidHits(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE asteroid_trajectories SET missile_hits=missile_hits+1,updated_at=:now WHERE id=:id AND status IN ('accelerating','coasting','crossing_sector','orbiting_black_hole')");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findAsteroidHits(array $parameters): mixed
    {
        $statement = $this->pdo->prepare('SELECT missile_hits FROM asteroid_trajectories WHERE id=:id');
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /** @param array<string|int, mixed> $parameters */
    public function destroyAsteroid(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE asteroid_trajectories SET status='destroyed',result='destroyed_by_missiles',updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    public function findProbeMissileForUpdate(int $probeId, ?string $itemId): array|false
    {
        $sql = "SELECT pi.* FROM probe_items pi WHERE pi.probe_id=:probe_id AND pi.type='missile' AND pi.reserved_transfer_id IS NULL AND (pi.fabricator IS NULL OR pi.fabricator<>'others')";
        $parameters = ['probe_id' => $probeId];
        if ($itemId !== null) { $sql .= ' AND pi.uid=:item_uid'; $parameters['item_uid'] = $itemId; }
        else { $sql .= " AND NOT EXISTS (SELECT 1 FROM missile_launches ml WHERE ml.probe_item_id=pi.id AND ml.status IN ('preparing','queued')) ORDER BY pi.created_at ASC, pi.id ASC LIMIT 1"; }
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') { $sql .= ' FOR UPDATE'; }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findMissileForPlayer(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT l.*,a.public_id AS action_public_id,p.status AS projectile_status,p.launched_at,p.impact_at AS projectile_impact_at,h.result AS history_result,h.details_json,h.resolved_at FROM missile_launches l LEFT JOIN others_actions a ON a.id=l.others_action_id LEFT JOIN others_projectiles p ON p.launch_id=l.id LEFT JOIN others_projectile_history h ON h.projectile_public_id=l.public_id WHERE l.public_id=:public_id AND l.player_id=:player_id');
        $statement->execute($parameters);
        return $statement->fetch();
    }


    /** @param array<string|int, mixed> $parameters */
    public function findMovingProjectile(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT p.*,l.player_id,l.id AS launch_sql_id,a.public_id AS action_public_id FROM others_projectiles p JOIN missile_launches l ON l.id=p.launch_id LEFT JOIN others_actions a ON a.id=p.action_id WHERE p.id=:id AND p.status='moving'");
        $statement->execute($parameters);
        return $statement->fetch();
    }


    /** @param array<string|int, mixed> $parameters */
    public function findProjectileByPublicId(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT p.*,l.player_id,l.id AS launch_sql_id,a.public_id AS action_public_id FROM others_projectiles p JOIN missile_launches l ON l.id=p.launch_id LEFT JOIN others_actions a ON a.id=p.action_id WHERE p.public_id=:id AND p.status='moving'");
        $statement->execute($parameters);
        return $statement->fetch();
    }

}
