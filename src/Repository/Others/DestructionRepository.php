<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Others;

use PDO;

final class DestructionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function activeTransfersTouchingShip(int $shipId, string $publicId): array
    {
        $target = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? "JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.targetShipId'))"
            : "JSON_EXTRACT(a.payload_json, '$.targetShipId')";
        $statement = $this->pdo->prepare("SELECT a.* FROM others_actions a
            LEFT JOIN others_inventory_transfers t ON t.action_id=a.id
            WHERE a.status IN ('queued','running','cancel_requested') AND a.type IN ('inventory_transfer','deuterium_transfer')
            AND (a.ship_id=? OR t.target_ship_id=? OR (a.type='deuterium_transfer' AND $target=?)) ORDER BY a.id");
        $statement->execute([$shipId, $shipId, $publicId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function terminateCarrierWork(int $shipId, string $now): void
    {
        $this->pdo->prepare("UPDATE others_crafts SET status='failed',updated_at=? WHERE ship_id=? AND status='queued'")->execute([$now, $shipId]);
        $this->pdo->prepare("UPDATE others_harvests SET phase='failed',updated_at=? WHERE ship_id=? AND phase NOT IN ('succeeded','failed','canceled')")->execute([$now, $shipId]);
        $this->pdo->prepare("UPDATE others_laser_locks SET status='stopped',updated_at=? WHERE ship_id=? AND status IN ('queued','active')")->execute([$now, $shipId]);
        $this->pdo->prepare("UPDATE scheduled_events SET status='cancelled',processed_at=?,updated_at=? WHERE entity_type='others_action' AND status='pending' AND entity_id IN (SELECT id FROM others_actions WHERE ship_id=?)")->execute([$now, $now, $shipId]);
    }

    /** @param array<string|int, mixed> $parameters @return list<array<string, mixed>> */
    public function findAuxiliaryStorageActions(array $parameters): array
    {
        $statement = $this->pdo->prepare("SELECT id,type FROM others_actions WHERE auxiliary_id=? AND type IN ('build_germination_depot','depot_deposit','depot_withdrawal') AND status IN ('queued','running')");
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @param array<string|int, mixed> $parameters */
    public function detachAuxiliaryActions(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET auxiliary_id=NULL,status=CASE WHEN status IN ('queued','running') THEN 'failed' ELSE status END,completed_at=CASE WHEN status IN ('queued','running') THEN :now ELSE completed_at END,updated_at=:now WHERE auxiliary_id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function destroyAuxiliary(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET status='destroyed',current_action_id=NULL,destroyed_at=:now,updated_at=:now WHERE id=:id AND destroyed_at IS NULL");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function damageAlreadyRecorded(array $parameters): mixed
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM others_damage_events WHERE event_key=:key');
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /** @param array<string|int, mixed> $parameters */
    public function recordShipDamage(array $parameters): int
    {
        $statement = $this->pdo->prepare('INSERT INTO others_damage_events (event_key,target_kind,target_public_id,damage,created_at) VALUES (:key,\'others_ship\',:target,:damage,:now)');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function updateShipIntegrity(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET integrity=:integrity,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function detachShipActions(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_actions SET auxiliary_id=NULL WHERE ship_id=:ship_id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function failShipLaunches(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE missile_launches SET status='failed',result='carrier_destroyed',others_item_id=NULL,updated_at=:now WHERE others_action_id IN (SELECT id FROM others_actions WHERE ship_id=:ship_id) AND status='queued'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function failShipActions(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status='failed',error_json=:error,completed_at=:now,updated_at=:now WHERE ship_id=:ship_id AND status IN ('queued','running','cancel_requested')");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteShipParticipants(array $parameters): int
    {
        $statement = $this->pdo->prepare('DELETE FROM others_swarm_participants WHERE auxiliary_id IN (SELECT id FROM others_auxiliaries WHERE ship_id=:ship_id)');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteShipAuxiliaries(array $parameters): int
    {
        $statement = $this->pdo->prepare('DELETE FROM others_auxiliaries WHERE ship_id=:ship_id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteShipItems(array $parameters): int
    {
        $statement = $this->pdo->prepare('DELETE FROM others_inventory_items WHERE ship_id=:ship_id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteShipResources(array $parameters): int
    {
        $statement = $this->pdo->prepare('DELETE FROM others_inventory_resources WHERE ship_id=:ship_id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function destroyShip(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET integrity=0,status=:status,current_action_id=NULL,inventory_reserved=0,deuterium_reserved=0,destroyed_at=:now,updated_at=:now WHERE id=:id AND destroyed_at IS NULL');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function dissolveFleet(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_fleets SET status='dissolved',dissolved_at=:now,updated_at=:now WHERE id=:id AND status='active'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters @return list<array<string, mixed>> */
    public function wreckResources(array $parameters): array
    {
        $statement = $this->pdo->prepare('SELECT resource_type,amount FROM others_inventory_resources WHERE ship_id=:ship_id AND amount>0');
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @param array<string|int, mixed> $parameters @return list<array<string, mixed>> */
    public function wreckItems(array $parameters): array
    {
        $statement = $this->pdo->prepare('SELECT type,COUNT(*) AS quantity FROM others_inventory_items WHERE ship_id=:ship_id GROUP BY type');
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @param array<string|int, mixed> $parameters @return list<array<string, mixed>> */
    public function findDeployedAuxiliaries(array $parameters): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed' AND destroyed_at IS NULL ORDER BY id");
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @param array<string|int, mixed> $parameters */
    public function detachDeployedActions(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET auxiliary_id = NULL WHERE auxiliary_id IN (SELECT id FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed')");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteDeployedParticipants(array $parameters): int
    {
        $statement = $this->pdo->prepare("DELETE FROM others_swarm_participants WHERE auxiliary_id IN (SELECT id FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed')");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteDeployedAuxiliaries(array $parameters): int
    {
        $statement = $this->pdo->prepare("DELETE FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }
}
