<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Storage;

use PDO;

/** Business root locks. Callers acquire families in actor/action/inventory order and sort IDs. */
final class StorageLockRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function actionIdentity(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM others_actions WHERE id=?');
        $statement->execute([$id]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Carrier IDs are immutable while an action is active; lock roots before the action. */
    public function actionShipIds(array $action): array
    {
        $ids = $action['ship_id'] === null ? [] : [(int) $action['ship_id']];
        if ($action['type'] === 'inventory_transfer') {
            $statement = $this->pdo->prepare('SELECT source_ship_id, target_ship_id FROM others_inventory_transfers WHERE action_id=?');
            $statement->execute([(int) $action['id']]);
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) { foreach ($row as $id) { $ids[] = (int) $id; } }
        } elseif ($action['type'] === 'deuterium_transfer') {
            $payload = json_decode($action['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $statement = $this->pdo->prepare('SELECT id FROM others_ships WHERE public_id=?');
            $statement->execute([$payload['targetShipId']]);
            if (($id = $statement->fetchColumn()) !== false) { $ids[] = (int) $id; }
        }
        sort($ids, SORT_NUMERIC);
        return array_values(array_unique($ids));
    }

    public function lock(string $kind, int|string $id): ?array
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Storage locks require a transaction.'); }
        [$table, $key] = match ($kind) {
            'fleet' => ['others_fleets', 'id'], 'projectile' => ['others_projectiles', 'id'],
            'trajectory' => ['asteroid_trajectories', 'id'], 'launch' => ['missile_launches', 'id'], 'ship' => ['others_ships', 'id'], 'auxiliary' => ['others_auxiliaries', 'id'],
            'probe' => ['neumann_probes', 'id'], 'manny' => ['mannies', 'id'],
            'action' => ['others_actions', 'id'], 'transfer' => ['sector_storage_transfers', 'id'],
            'depot' => ['germination_depots', 'id'], 'container' => ['storage_containers', 'id'],
            'detached' => ['detached_storage_containers', 'object_id'],
            'broadcast' => ['anomaly_broadcasts', 'id'],
            default => throw new \InvalidArgumentException('Unknown storage lock root.'),
        };
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if (!$mysql) {
            $stmt = $this->pdo->prepare("UPDATE $table SET $key=$key WHERE $key=?");
            $stmt->execute([$id]);
        }
        $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE $key=?" . ($mysql ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
