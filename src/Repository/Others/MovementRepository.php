<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Others;

use PDO;

final class MovementRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string|int, mixed> $parameters */
    public function createMovement(array $parameters): int
    {
        $statement = $this->pdo->prepare("INSERT INTO others_movements (action_id, ship_id, source_x, source_y, source_z, target_x, target_y, target_z, fuel_cost, leave_auxiliaries_behind, phase, depart_at, arrive_at, created_at, updated_at)
                 VALUES (:action_id, :ship_id, :source_x, :source_y, :source_z, :target_x, :target_y, :target_z, :fuel_cost, :leave_behind, 'waiting_to_depart', :depart_at, :arrive_at, :created_at, :updated_at)");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function engageDeparture(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_ships SET deuterium_stock = deuterium_stock - :fuel, status = 'preparing', current_action_id = :action_id, departure_engaged = 1, updated_at = :updated_at WHERE id = :ship_id AND current_action_id IS NULL AND deuterium_stock >= :fuel");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function recallDeployedAuxiliaries(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET status = 'returning', spatial_state = 'returning_to_carrier', current_action_id = NULL, updated_at = :now WHERE ship_id = :ship_id AND location_type = 'deployed'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findCancelableMovement(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT a.*, m.phase FROM others_actions a JOIN others_movements m ON m.action_id = a.id WHERE a.id = :id AND a.ship_id = :ship_id');
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findActionWithMovement(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT a.*, m.id AS movement_id, m.ship_id AS movement_ship_id, m.target_x, m.target_y, m.target_z, m.fuel_cost, m.phase, m.arrive_at, m.leave_auxiliaries_behind FROM others_actions a LEFT JOIN others_movements m ON m.action_id = a.id WHERE a.id = :id');
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function cancelMovement(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_movements SET phase = 'canceled', updated_at = :now WHERE action_id = :id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function refundDeparture(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_ships SET status = 'inactive', current_action_id = NULL, departure_engaged = 0, deuterium_stock = deuterium_stock + :fuel, updated_at = :now WHERE id = :ship_id AND current_action_id = :action_id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function embarkReturningAuxiliaries(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', location_type = 'embarked', spatial_state = 'drifting', sector_x = NULL, sector_y = NULL, sector_z = NULL, object_id = NULL, updated_at = :now WHERE ship_id = :ship_id AND status = 'returning'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function startMovement(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_movements SET phase = 'transit', updated_at = :now WHERE action_id = :id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function departShip(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_ships SET status = 'transit', updated_at = :now WHERE id = :ship_id AND current_action_id = :action_id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function arriveMovement(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_movements SET phase = 'arrived', updated_at = :now WHERE action_id = :id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function arriveShip(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_ships SET status = 'inactive', sector_x = :x, sector_y = :y, sector_z = :z, current_action_id = NULL, departure_engaged = 0, entered_sector_at = :now, updated_at = :now WHERE id = :ship_id AND current_action_id = :action_id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }
}
