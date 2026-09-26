<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Others;

use PDO;

final class InventoryRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function reserveItems(int $actionId, array $ids, string $now): void
    {
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare("UPDATE others_inventory_items SET reserved_action_id=?,updated_at=? WHERE reserved_action_id IS NULL AND id IN ($placeholders)");
            $statement->execute([$actionId, $now, ...$chunk]);
            if ($statement->rowCount() !== count($chunk)) { throw new \RuntimeException('Inventory item reservation changed concurrently.'); }
        }
    }

    /** @param array<string|int, mixed> $parameters */
    public function jettisonResource(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_resources SET amount = amount - CAST(:amount AS DECIMAL(20,4)), updated_at = :now WHERE ship_id = :ship_id AND resource_type = :resource_type AND amount - reserved_amount >= CAST(:available AS DECIMAL(20,4))');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function deleteAvailableItem(array $parameters): int
    {
        $statement = $this->pdo->prepare('DELETE FROM others_inventory_items WHERE id = :id AND ship_id = :ship_id AND reserved_action_id IS NULL');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function reserveResource(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_resources SET reserved_amount = reserved_amount + CAST(:amount AS DECIMAL(20,4)), updated_at = :now WHERE ship_id = :ship_id AND resource_type = :resource_type AND amount - reserved_amount >= CAST(:amount AS DECIMAL(20,4))');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function reserveItem(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_items SET reserved_action_id = :action_id, updated_at = :now WHERE id = :id AND reserved_action_id IS NULL');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function createTransfer(array $parameters): int
    {
        $statement = $this->pdo->prepare("INSERT INTO others_inventory_transfers (public_id, action_id, source_ship_id, target_ship_id, auxiliary_id, kind, resource_type, amount, item_ids_json, status, created_at, updated_at) VALUES (:public_id,:action_id,:source,:target,:aux,:kind,:resource_type,:amount,:items,'queued',:now,:now)");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function reserveCapacity(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET inventory_reserved = inventory_reserved + :space, updated_at = :now WHERE id = :id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function claimAuxiliary(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET status = 'busy', current_action_id = :action_id, updated_at = :now WHERE id = :id AND current_action_id IS NULL");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function reserveFuelTanks(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET deuterium_reserved = deuterium_reserved + :amount, updated_at = :now WHERE id IN (:source, :target)');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findTransfer(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM others_inventory_transfers WHERE action_id = :action_id');
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findActiveTargetShip(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT * FROM others_ships WHERE id = :id AND destroyed_at IS NULL AND status <> 'removed'");
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function releaseResourceReservation(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_resources SET reserved_amount = CASE WHEN reserved_amount > :reserved_floor THEN reserved_amount - :reserved_decrease ELSE 0 END, updated_at = :now WHERE ship_id = :ship_id AND resource_type = :resource_type');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function releaseItemReservations(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_items SET reserved_action_id = NULL, updated_at = :now WHERE reserved_action_id = :action_id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function debitReservedResource(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_resources SET amount = amount - CAST(:amount AS DECIMAL(20,4)), reserved_amount = reserved_amount - CAST(:amount AS DECIMAL(20,4)), updated_at = :now WHERE ship_id = :ship_id AND resource_type = :resource_type AND amount >= CAST(:amount AS DECIMAL(20,4)) AND reserved_amount >= CAST(:amount AS DECIMAL(20,4))');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function creditTransferredResource(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_resources SET amount = amount + :amount, updated_at = :now WHERE ship_id = :ship_id AND resource_type = :resource_type');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function moveReservedItems(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_items SET ship_id = :target_ship_id, reserved_action_id = NULL, updated_at = :now WHERE reserved_action_id = :action_id AND ship_id = :source_ship_id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishTransfer(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_transfers SET status = :status, updated_at = :now WHERE id = :id AND status = :expected');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function releaseCapacity(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET inventory_reserved = CASE WHEN inventory_reserved > :reserved_floor THEN inventory_reserved - :reserved_decrease ELSE 0 END, updated_at = :now WHERE id = :id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function releaseTransferActor(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', current_action_id = NULL, updated_at = :now WHERE id = :id AND current_action_id = :action_id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function releaseFuelReservation(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET deuterium_reserved = CASE WHEN deuterium_reserved > :reserved_floor THEN deuterium_reserved - :reserved_decrease ELSE 0 END, updated_at = :now WHERE id = :id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function debitReservedFuel(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET deuterium_stock = deuterium_stock - :amount, deuterium_reserved = deuterium_reserved - :amount, updated_at = :now WHERE id = :id AND deuterium_stock >= :amount AND deuterium_reserved >= :amount');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function creditReservedFuel(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET deuterium_stock = deuterium_stock + :stock_increase, deuterium_reserved = CASE WHEN deuterium_reserved > :reserved_floor THEN deuterium_reserved - :reserved_decrease ELSE 0 END, updated_at = :now WHERE id = :id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }
}
