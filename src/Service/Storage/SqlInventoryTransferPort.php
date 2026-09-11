<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Storage;

use PDO;
use VonNeumannGame\Service\OthersActionException;

/** Closed SQL inventory adapters. The caller holds their root locks. */
final class SqlInventoryTransferPort implements InventoryTransferPort
{
    private readonly string $resourcesTable;
    private readonly string $itemsTable;
    private readonly string $ownerColumn;
    private readonly string $reservationColumn;
    private readonly string $identityColumn;
    private readonly string $resourceOwnerColumn;

    public function __construct(private readonly PDO $pdo, public readonly string $kind, public readonly int|string $id, private readonly ?float $availableCapacity = null, private readonly ?int $probeId = null)
    {
        [$this->resourcesTable, $this->itemsTable, $this->ownerColumn, $this->reservationColumn] = match ($kind) {
            'ship' => ['others_inventory_resources', 'others_inventory_items', 'ship_id', 'reserved_action_id'],
            'depot' => ['germination_depot_resources', 'germination_depot_items', 'depot_id', 'reserved_transfer_id'],
            'container' => ['storage_container_resources', 'probe_items', 'storage_container_id', 'reserved_transfer_id'],
            'detached' => ['detached_storage_container_resources', 'detached_storage_container_items', 'container_object_id', 'reserved_transfer_id'],
            default => throw new \InvalidArgumentException('Unsupported inventory kind.'),
        };
        $this->resourceOwnerColumn = $kind === 'container' ? 'container_id' : $this->ownerColumn;
        $this->identityColumn = in_array($kind, ['container','detached'], true) ? 'uid' : 'public_id';
    }

    public function items(array $ids): array
    {
        $result = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $query = $this->pdo->prepare("SELECT * FROM {$this->itemsTable} WHERE {$this->ownerColumn}=? AND {$this->identityColumn} IN ($marks) ORDER BY {$this->identityColumn}");
            $query->execute([$this->id, ...$chunk]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row[$this->reservationColumn] !== null) { throw new OthersActionException(422, 'insufficient_resources', 'An item is reserved.'); }
                if ($this->kind === 'container' && $row['type'] === 'additional_container') { throw new OthersActionException(422, 'item_not_transportable', 'An attached container is not removable content.'); }
                if ($this->kind === 'detached' && (int) $row['is_backing_item'] === 1) { throw new OthersActionException(422, 'item_not_transportable', 'The container shell is not removable content.'); }
                $result[$row[$this->identityColumn]] = ['id' => $row[$this->identityColumn], 'type' => $row['type'], 'name' => $row['name'],
                    'containerSpace' => (float) $row['container_space'], 'metadata' => in_array($this->kind, ['container','detached'], true) ? \VonNeumannGame\Repository\ItemMetadataColumns::metadata($row) : json_decode($row['metadata_json'], true, 512, JSON_THROW_ON_ERROR), 'createdAt' => $row['created_at']];
            }
        }
        if (count($result) !== count($ids)) { throw new OthersActionException(422, 'insufficient_resources', 'An item is unavailable.'); }
        // Refuse cross-inventory identity collisions before accepting any reservation.
        foreach (array_chunk($ids,100) as $chunk) {
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $queries=[
                ["SELECT public_id FROM others_inventory_items WHERE public_id IN ($marks)",'ship'],
                ["SELECT public_id FROM germination_depot_items WHERE public_id IN ($marks)",'depot'],
                ["SELECT uid FROM probe_items WHERE uid IN ($marks)",'container'],
                ["SELECT uid FROM detached_storage_container_items WHERE uid IN ($marks) AND is_backing_item=0",'detached'],
            ];
            $counts=[];
            foreach($queries as [$sql,$kind]){
                $query=$this->pdo->prepare($sql);$query->execute($chunk);
                foreach($query->fetchAll(PDO::FETCH_COLUMN) as $uid){$counts[$uid]=($counts[$uid]??0)+1;}
            }
            foreach($counts as $count){if($count!==1){throw new OthersActionException(409,'inventory_identity_conflict','An item identity exists in several inventories.');}}
        }
        ksort($result, SORT_STRING);
        return array_values($result);
    }

    public function reserve(int $transferId, int $actionId, array $resources, array $items, string $now): void
    {
        foreach ($resources as $type => $amount) {
            $stmt = $this->pdo->prepare("UPDATE {$this->resourcesTable} SET reserved_amount=ROUND(reserved_amount+CAST(? AS DECIMAL(20,4)),4) WHERE {$this->resourceOwnerColumn}=? AND resource_type=? AND ROUND(amount-reserved_amount,4)>=CAST(? AS DECIMAL(20,4))");
            $stmt->execute([$amount, $this->id, $type, $amount]);
            if ($stmt->rowCount() !== 1) { throw new OthersActionException(422, 'insufficient_resources', 'Requested resources are unavailable.'); }
            $this->pdo->prepare('INSERT INTO sector_storage_resource_reservations(transfer_id,inventory_kind,inventory_id,resource_type,amount) VALUES(?,?,?,?,?)')->execute([$transferId, $this->kind, (string) $this->id, $type, $amount]);
        }
        foreach (array_chunk(array_column($items, 'id'), 100) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("UPDATE {$this->itemsTable} SET {$this->reservationColumn}=? WHERE {$this->ownerColumn}=? AND {$this->reservationColumn} IS NULL AND {$this->identityColumn} IN ($marks)");
            $stmt->execute([$this->kind === 'ship' ? $actionId : $transferId, $this->id, ...$chunk]);
            if ($stmt->rowCount() !== count($chunk)) { throw new OthersActionException(409, 'action_conflict', 'An item was reserved concurrently.'); }
            $params = [];
            foreach ($chunk as $id) { array_push($params, $this->kind, (string) $this->id, $id, $transferId); }
            $this->pdo->prepare('INSERT INTO sector_storage_item_claims(inventory_kind,inventory_id,item_public_id,transfer_id) VALUES ' . implode(',', array_fill(0, count($chunk), '(?,?,?,?)')))->execute($params);
        }
        $this->touch();
    }

    public function reserveCapacity(int $transferId, float $space, string $now): void
    {
        if ($this->kind === 'depot') { return; }
        if ($this->kind !== 'ship') {
            if ($this->availableCapacity === null || $space > $this->availableCapacity + 0.00001) { throw new OthersActionException(422, 'insufficient_capacity', 'The destination has insufficient capacity.'); }
            $this->pdo->prepare('INSERT INTO sector_storage_capacity_reservations(transfer_id,inventory_kind,inventory_id,amount) VALUES(?,?,?,?)')->execute([$transferId,$this->kind,(string)$this->id,$space]);
            return;
        }
        $query = $this->pdo->prepare('UPDATE others_ships SET inventory_reserved=ROUND(inventory_reserved+CAST(? AS DECIMAL(20,4)),4) WHERE id=? AND inventory_capacity-inventory_reserved-(SELECT COALESCE(SUM(amount),0) FROM others_inventory_resources WHERE ship_id=?)-(SELECT COALESCE(SUM(container_space),0) FROM others_inventory_items WHERE ship_id=?)>=CAST(? AS DECIMAL(20,4))');
        $query->execute([$space, $this->id, $this->id, $this->id, $space]);
        if ($query->rowCount() !== 1) { throw new OthersActionException(422, 'insufficient_capacity', 'The destination has insufficient capacity.'); }
        $this->pdo->prepare('INSERT INTO sector_storage_capacity_reservations(transfer_id,inventory_kind,inventory_id,amount) VALUES(?,?,?,?)')->execute([$transferId, $this->kind, (string) $this->id, $space]);
    }

    public function debit(int $transferId, int $actionId, array $resources, array $itemIds, string $now): void
    {
        foreach ($resources as $type => $amount) {
            $query = $this->pdo->prepare("UPDATE {$this->resourcesTable} SET amount=ROUND(amount-CAST(? AS DECIMAL(20,4)),4),reserved_amount=ROUND(reserved_amount-CAST(? AS DECIMAL(20,4)),4) WHERE {$this->resourceOwnerColumn}=? AND resource_type=? AND reserved_amount>=CAST(? AS DECIMAL(20,4)) AND amount>=CAST(? AS DECIMAL(20,4))");
            $query->execute([$amount, $amount, $this->id, $type, $amount, $amount]);
            if ($query->rowCount() !== 1) { throw new \RuntimeException('Reserved resource debit invariant violated.'); }
            // Remove full debits before updating partial ones (the remainder may equal the debit).
            $this->pdo->prepare('DELETE FROM sector_storage_resource_reservations WHERE transfer_id=? AND inventory_kind=? AND inventory_id=? AND resource_type=? AND amount=CAST(? AS DECIMAL(20,4))')->execute([$transferId, $this->kind, (string) $this->id, $type, $amount]);
            $this->pdo->prepare('UPDATE sector_storage_resource_reservations SET amount=ROUND(amount-CAST(? AS DECIMAL(20,4)),4) WHERE transfer_id=? AND inventory_kind=? AND inventory_id=? AND resource_type=? AND amount>CAST(? AS DECIMAL(20,4))')->execute([$amount, $transferId, $this->kind, (string) $this->id, $type, $amount]);
        }
        foreach (array_chunk($itemIds, 100) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $query = $this->pdo->prepare("DELETE FROM {$this->itemsTable} WHERE {$this->ownerColumn}=? AND {$this->reservationColumn}=? AND {$this->identityColumn} IN ($marks)");
            $query->execute([$this->id, $this->kind === 'ship' ? $actionId : $transferId, ...$chunk]);
            if ($query->rowCount() !== count($chunk)) { throw new \RuntimeException('Reserved item debit invariant violated.'); }
        }
        $this->touch();
    }

    public function credit(array $resources, array $items, string $now): void
    {
        foreach ($resources as $type => $amount) {
            $extraColumn = in_array($this->kind, ['ship','container'], true) ? ',updated_at' : '';
            $extraValue = in_array($this->kind, ['ship','container'], true) ? ',?' : '';
            $conflict = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE amount=ROUND(amount+VALUES(amount),4)'
                : " ON CONFLICT({$this->resourceOwnerColumn},resource_type) DO UPDATE SET amount=ROUND(amount+excluded.amount,4)";
            $this->pdo->prepare("INSERT INTO {$this->resourcesTable}({$this->resourceOwnerColumn},resource_type,amount,reserved_amount$extraColumn) VALUES(?,?,?,0$extraValue)$conflict")->execute([$this->id, $type, $amount, ...(in_array($this->kind, ['ship','container'], true) ? [$now] : [])]);
        }
        if (in_array($this->kind, ['container','detached'], true)) {
            $this->creditCanonicalItems($items, $now);
            $this->touch();
            return;
        }
        foreach (array_chunk($items, 80) as $chunk) {
            $params = [];
            foreach ($chunk as $item) { array_push($params, $this->id, $item['id'], $item['type'], $item['name'], $item['containerSpace'], json_encode($item['metadata'], JSON_THROW_ON_ERROR), $item['createdAt'], $now); }
            $this->pdo->prepare("INSERT INTO {$this->itemsTable}({$this->ownerColumn},public_id,type,name,container_space,metadata_json,created_at,updated_at) VALUES " . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?)')))->execute($params);
        }
        $this->touch();
    }

    public function release(int $transferId, int $actionId, string $now): void
    {
        $query = $this->pdo->prepare('SELECT resource_type,amount FROM sector_storage_resource_reservations WHERE transfer_id=? AND inventory_kind=? AND inventory_id=? ORDER BY resource_type');
        $query->execute([$transferId, $this->kind, (string) $this->id]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $release = $this->pdo->prepare("UPDATE {$this->resourcesTable} SET reserved_amount=ROUND(reserved_amount-CAST(? AS DECIMAL(20,4)),4) WHERE {$this->resourceOwnerColumn}=? AND resource_type=? AND reserved_amount>=CAST(? AS DECIMAL(20,4))");
            $release->execute([$row['amount'], $this->id, $row['resource_type'], $row['amount']]);
            if ($release->rowCount() !== 1) { throw new \RuntimeException('Resource release invariant violated.'); }
        }
        $this->pdo->prepare('DELETE FROM sector_storage_resource_reservations WHERE transfer_id=? AND inventory_kind=? AND inventory_id=?')->execute([$transferId, $this->kind, (string) $this->id]);
        $this->pdo->prepare("UPDATE {$this->itemsTable} SET {$this->reservationColumn}=NULL WHERE {$this->ownerColumn}=? AND {$this->reservationColumn}=?")->execute([$this->id, $this->kind === 'ship' ? $actionId : $transferId]);
        $this->pdo->prepare('DELETE FROM sector_storage_item_claims WHERE transfer_id=? AND inventory_kind=? AND inventory_id=?')->execute([$transferId, $this->kind, (string) $this->id]);
        if ($this->kind === 'ship') {
            $query = $this->pdo->prepare('SELECT amount FROM sector_storage_capacity_reservations WHERE transfer_id=? AND inventory_kind=? AND inventory_id=?');
            $query->execute([$transferId, $this->kind, (string) $this->id]);
            $space = $query->fetchColumn();
            if ($space !== false) {
                $release = $this->pdo->prepare('UPDATE others_ships SET inventory_reserved=ROUND(inventory_reserved-CAST(? AS DECIMAL(20,4)),4) WHERE id=? AND inventory_reserved>=CAST(? AS DECIMAL(20,4))');
                $release->execute([$space, $this->id, $space]);
                if ($release->rowCount() !== 1) { throw new \RuntimeException('Capacity release invariant violated.'); }
                $this->pdo->prepare('DELETE FROM sector_storage_capacity_reservations WHERE transfer_id=? AND inventory_kind=? AND inventory_id=?')->execute([$transferId, $this->kind, (string) $this->id]);
            }
        }
        $this->pdo->prepare('DELETE FROM sector_storage_capacity_reservations WHERE transfer_id=? AND inventory_kind=? AND inventory_id=?')->execute([$transferId, $this->kind, (string) $this->id]);
        $this->touch();
    }

    private function creditCanonicalItems(array $items, string $now): void
    {
        if ($this->kind === 'container' && $this->probeId === null) { throw new \LogicException('Probe inventory requires its owner.'); }
        foreach (array_chunk($items, 40) as $chunk) {
            $rows = []; $parameters = [];
            foreach ($chunk as $item) {
                $metadata = \VonNeumannGame\Repository\ItemMetadataColumns::parameters($item['metadata']);
                $values = [$this->id, $item['id'], $item['type'], $item['name'], $item['containerSpace'], ...array_values($metadata), $item['createdAt'], $now];
                if ($this->kind === 'container') { $values[] = $this->probeId; }
                $rows[] = '(' . implode(',', array_fill(0,count($values),'?')) . ')';
                array_push($parameters,...$values);
            }
            if ($rows !== []) {
                $columns = implode(',', array_keys(\VonNeumannGame\Repository\ItemMetadataColumns::parameters([])));
                $extra = $this->kind === 'container' ? ',probe_id' : '';
                $this->pdo->prepare("INSERT INTO {$this->itemsTable}({$this->ownerColumn},uid,type,name,container_space,$columns,created_at,updated_at$extra) VALUES " . implode(',',$rows))->execute($parameters);
            }
        }
    }

    private function touch(): void
    {
        if ($this->kind === 'depot') { $this->pdo->prepare('UPDATE germination_depots SET version=version+1 WHERE id=?')->execute([$this->id]); }
        if ($this->kind === 'container') { $this->pdo->prepare('UPDATE storage_containers SET storage_version=storage_version+1 WHERE id=?')->execute([$this->id]); }
        if ($this->kind === 'detached') { $this->pdo->prepare('UPDATE detached_storage_containers SET storage_version=storage_version+1 WHERE object_id=?')->execute([$this->id]); }
    }
}
