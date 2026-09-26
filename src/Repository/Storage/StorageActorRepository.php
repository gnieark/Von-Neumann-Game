<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Storage;

use PDO;

final class StorageActorRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function reserveConstructionMetals(int $shipId, string $now): bool
    {
        $query = $this->pdo->prepare("UPDATE others_inventory_resources SET reserved_amount=ROUND(reserved_amount+2,4),updated_at=? WHERE ship_id=? AND resource_type='metals' AND ROUND(amount-reserved_amount,4)>=2");
        $query->execute([$now, $shipId]);
        return $query->rowCount() === 1;
    }

    public function reserveAuxiliary(array $ship, array $auxiliary, int $actionId, ?string $objectId, string $now): bool
    {
        $query = $this->pdo->prepare("UPDATE others_auxiliaries SET status='busy',location_type='deployed',spatial_state='moving_to_sector_object',sector_x=?,sector_y=?,sector_z=?,current_action_id=?,object_id=?,updated_at=? WHERE id=? AND current_action_id IS NULL AND location_type='embarked' AND destroyed_at IS NULL");
        $query->execute([$ship['sector_x'], $ship['sector_y'], $ship['sector_z'], $actionId, $objectId, $now, $auxiliary['id']]);
        return $query->rowCount() === 1;
    }

    public function attachEvent(int $actionId, int $eventId, string $now): void
    {
        $this->pdo->prepare('UPDATE others_actions SET scheduled_event_id=?,created_at=?,updated_at=? WHERE id=?')->execute([$eventId, $now, $now, $actionId]);
    }

    public function actionRoots(int $actionId): ?array
    {
        $query = $this->pdo->prepare('SELECT ship_id,auxiliary_id FROM others_actions WHERE id=?');
        $query->execute([$actionId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function consumeConstructionMetals(int $shipId, string $now): bool
    {
        $query = $this->pdo->prepare("UPDATE others_inventory_resources SET amount=ROUND(amount-2,4),reserved_amount=ROUND(reserved_amount-2,4),updated_at=? WHERE ship_id=? AND resource_type='metals' AND amount>=2 AND reserved_amount>=2");
        $query->execute([$now, $shipId]);
        return $query->rowCount() === 1;
    }

    public function finishAction(int $actionId, string $status, array $result, string $now): void
    {
        $query = $this->pdo->prepare("UPDATE others_actions SET status=?,result_json=?,completed_at=?,updated_at=? WHERE id=? AND status NOT IN ('succeeded','failed','canceled')");
        $query->execute([$status, json_encode($result, JSON_THROW_ON_ERROR), $now, $now, $actionId]);
        if ($query->rowCount() !== 1) { throw new \RuntimeException('Action terminal transition invariant violated.'); }
    }

    public function releaseAuxiliary(int $actionId, int $actorId, string $now): bool
    {
        $query = $this->pdo->prepare("UPDATE others_auxiliaries SET status='inactive',location_type='embarked',spatial_state='drifting',sector_x=NULL,sector_y=NULL,sector_z=NULL,object_id=NULL,current_action_id=NULL,updated_at=? WHERE id=? AND current_action_id=? AND destroyed_at IS NULL");
        $query->execute([$now, $actorId, $actionId]);
        return $query->rowCount() === 1;
    }

    public function deleteAuxiliary(int $actorId): void
    {
        $this->pdo->prepare('UPDATE others_actions SET auxiliary_id=NULL WHERE auxiliary_id=?')->execute([$actorId]);
        $this->pdo->prepare('DELETE FROM others_swarm_participants WHERE auxiliary_id=?')->execute([$actorId]);
        $this->pdo->prepare('DELETE FROM others_auxiliaries WHERE id=?')->execute([$actorId]);
    }
}
