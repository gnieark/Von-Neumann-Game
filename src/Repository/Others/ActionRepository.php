<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Others;

use PDO;

final class ActionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string|int, mixed> $parameters */
    public function attachActionEvent(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_actions SET scheduled_event_id=:event_id WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }


    /** @param array<string|int, mixed> $parameters */
    public function cancelDeployedActions(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status = 'canceled', completed_at = :now, updated_at = :now, error_json = :error WHERE auxiliary_id IN (SELECT id FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed') AND status IN ('queued','running')");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function cancelDeployedEvents(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE scheduled_events SET status = 'cancelled', processed_at = :now, updated_at = :now WHERE entity_type = 'others_action' AND entity_id IN (SELECT id FROM others_actions WHERE auxiliary_id IN (SELECT id FROM others_auxiliaries WHERE ship_id = :ship_id AND location_type = 'deployed')) AND status = 'pending'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function requestMovementCancellation(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status = 'cancel_requested', ends_at = :ends_at, updated_at = :updated_at WHERE id = :id AND status = 'queued'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function reschedulePendingEvent(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE scheduled_events SET run_at = :run_at, payload_json = :payload, updated_at = :updated_at WHERE id = :id AND status = 'pending'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findCancelableHarvest(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT a.* FROM others_actions a JOIN others_harvests h ON h.action_id=a.id WHERE a.id=:id AND a.status IN ('queued','running')");
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function requestHarvestCancellation(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status='cancel_requested',updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function rescheduleHarvestCancellation(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE scheduled_events SET run_at=:now,payload_json=:payload,updated_at=:now WHERE id=:id AND status='pending'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findActionActors(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT ship_id, auxiliary_id FROM others_actions WHERE id = ?');
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishRepair(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status = 'succeeded', result_json = :result, completed_at = :now, updated_at = :now WHERE id = :id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findActionType(array $parameters): mixed
    {
        $statement = $this->pdo->prepare('SELECT type FROM others_actions WHERE id=?');
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /** @param array<string|int, mixed> $parameters */
    public function cancelAction(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status = 'canceled', completed_at = :now, updated_at = :now WHERE id = :id AND status = 'cancel_requested'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function startAction(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status = 'running', updated_at = :now WHERE id = :id AND status = 'queued'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishMovementAction(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status = 'succeeded', result_json = :result, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'running'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishTransferAction(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_actions SET status = :status, result_json = :result, error_json = :error, completed_at = :now, updated_at = :now WHERE id = :id AND status = :expected');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function failFuelTransfer(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status = 'failed', error_json = :error, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'queued'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishFuelTransfer(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status = 'succeeded', result_json = :result, completed_at = :now, updated_at = :now WHERE id = :id AND status = 'queued'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function updateHarvestDeadline(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status='running',ends_at=:ends,updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishHarvestAction(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status=:status,result_json=:result,error_json=:error,completed_at=:now,updated_at=:now WHERE id=:id AND status IN ('queued','running','cancel_requested')");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function rescheduleAction(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_actions SET scheduled_event_id=:event_id,ends_at=:ends_at,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function startLaserAction(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status='running',ends_at=:ends,updated_at=:now WHERE id=:id AND status='queued'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishLaserAction(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status='succeeded',result_json=:result,completed_at=:now,updated_at=:now WHERE id=:id AND status IN ('queued','running')");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function startMissileAction(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status='running',ends_at=:impact_at,scheduled_event_id=:event_id,updated_at=:now WHERE id=:id AND status='queued'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }


    /** @param array<string|int, mixed> $parameters */
    public function finishProjectileAction(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_actions SET status='succeeded',result_json=:details,completed_at=:now,updated_at=:now WHERE id=:id AND status='running'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }


    /** @param array<string|int, mixed> $parameters @return list<array<string, mixed>> */
    public function findShipStorageActions(array $parameters): array
    {
        $statement = $this->pdo->prepare("SELECT id,type FROM others_actions WHERE ship_id=? AND type IN ('build_germination_depot','depot_deposit','depot_withdrawal') AND status IN ('queued','running') ORDER BY id");
        $statement->execute($parameters);
        return $statement->fetchAll();
    }
}
