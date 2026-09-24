<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Others;

use PDO;

final class ProductionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string|int, mixed> $parameters */
    public function createHarvest(array $parameters): int
    {
        $statement = $this->pdo->prepare("INSERT INTO others_harvests (action_id,ship_id,target_object_id,phase,phase_started_at,auxiliary_count,reserved_capacity,biological_carbon,pending_output_json,created_at,updated_at) VALUES (:action_id,:ship_id,:target,:phase,:started,:count,:capacity,:biomass,NULL,:created,:updated)");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function engageHarvest(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_ships SET status='low_orbit', current_action_id=:action_id, inventory_reserved=inventory_reserved+:capacity, updated_at=:now WHERE id=:id AND current_action_id IS NULL");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function consumeCraftIngredient(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_resources SET amount=amount-CAST(:amount AS DECIMAL(20,4)),updated_at=:now WHERE ship_id=:ship_id AND resource_type=:type AND amount-reserved_amount>=CAST(:amount AS DECIMAL(20,4))');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function createCraft(array $parameters): int
    {
        $statement = $this->pdo->prepare("INSERT INTO others_crafts (public_id,action_id,ship_id,assistant_auxiliary_id,recipe_id,ingredients_json,output_space,status,created_at,updated_at) VALUES (:public_id,:action_id,:ship_id,:assistant,:recipe,:ingredients,:space,'queued',:now,:now)");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function claimCraftAssistant(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET status='busy',current_action_id=:action_id,updated_at=:now WHERE id=:id AND current_action_id IS NULL");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function reserveCraftCapacity(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET inventory_reserved=inventory_reserved+:space,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function consumeRepairMetals(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_inventory_resources SET amount = amount - CAST(:cost AS DECIMAL(20,4)), updated_at = :now WHERE ship_id = :ship_id AND resource_type = 'metals' AND amount - reserved_amount >= CAST(:cost AS DECIMAL(20,4))");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function addIntegrity(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET integrity = integrity + :restored, updated_at = :now WHERE id = :id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function releaseRepairActor(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', current_action_id = NULL, updated_at = :now WHERE id = :id AND current_action_id = :action_id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishAuxiliaryMining(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET cargo_deuterium = cargo_deuterium + :deuterium, cargo_metals = cargo_metals + :metals, cargo_ice = cargo_ice + :ice, cargo_carbon_compounds = cargo_carbon_compounds + :carbon, status = 'inactive', current_action_id = NULL, spatial_state = 'landed_on_sector_object', updated_at = :now WHERE id = :id AND current_action_id = :action_id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishRecall(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET status = 'inactive', location_type = 'embarked', spatial_state = 'drifting', sector_x = NULL, sector_y = NULL, sector_z = NULL, object_id = NULL, current_action_id = NULL, updated_at = :now WHERE id = :id AND current_action_id = :action_id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findHarvest(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM others_harvests WHERE action_id=:action_id');
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function beginHarvestRecall(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_harvests SET phase='recalling',phase_started_at=:now,pending_output_json=:output,updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function beginOrbitExit(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_harvests SET phase='orbit_exit',phase_started_at=:now,updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function beginHarvestMining(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_harvests SET phase='mining',phase_started_at=:now,updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function creditHarvestResource(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_inventory_resources SET amount=amount+:amount,updated_at=:now WHERE ship_id=:ship_id AND resource_type=:type');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function embarkHarvestActors(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET status='inactive',location_type='embarked',spatial_state='drifting',sector_x=NULL,sector_y=NULL,sector_z=NULL,object_id=NULL,current_action_id=NULL,updated_at=:now WHERE current_action_id=:action_id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishHarvestShip(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_ships SET status='inactive',current_action_id=NULL,inventory_reserved=CASE WHEN inventory_reserved > :reserved_floor THEN inventory_reserved - :reserved_decrease ELSE 0 END,updated_at=:now WHERE id=:id AND current_action_id=:action_id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishHarvest(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_harvests SET phase=:phase,pending_output_json=:output,updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function creditHarvestFuel(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships
                 SET deuterium_stock=deuterium_stock+:tank_points,updated_at=:now
                 WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findCraft(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM others_crafts WHERE action_id=:action_id');
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findCraftCarrier(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT s.*,f.player_id,f.public_id AS fleet_public_id FROM others_ships s JOIN others_fleets f ON f.id=s.fleet_id WHERE s.id=:id AND s.destroyed_at IS NULL');
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function failCraft(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_crafts SET status='failed',updated_at=:now WHERE id=:id");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function finishCraft(array $parameters): int
    {
        $statement = $this->pdo->prepare("UPDATE others_crafts SET status='succeeded',updated_at=:now WHERE id=:id AND status='queued'");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param array<string|int, mixed> $parameters */
    public function releaseCraftCapacity(array $parameters): int
    {
        $statement = $this->pdo->prepare('UPDATE others_ships SET inventory_reserved=CASE WHEN inventory_reserved > :reserved_floor THEN inventory_reserved - :reserved_decrease ELSE 0 END,updated_at=:now WHERE id=:id');
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /** @param list<int> $auxiliaryIds */
    public function recordSwarmParticipants(int $actionId, array $auxiliaryIds, string $now): void
    {
        foreach (array_chunk($auxiliaryIds, 200) as $batch) {
            $values = []; $parameters = [];
            foreach ($batch as $id) { $values[] = '(?,?,?)'; array_push($parameters, $actionId, $id, $now); }
            $statement = $this->pdo->prepare('INSERT INTO others_swarm_participants (action_id, auxiliary_id, created_at) VALUES ' . implode(',', $values));
            $statement->execute($parameters);
        }
    }

    /** @param list<int> $auxiliaryIds @param array<string, mixed> $ship */
    public function deploySwarm(int $actionId, array $auxiliaryIds, array $ship, string $objectId, string $now): int
    {
        $updated = 0;
        foreach (array_chunk($auxiliaryIds, 200) as $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $statement = $this->pdo->prepare("UPDATE others_auxiliaries SET location_type='deployed', spatial_state='moving_to_sector_object', sector_x=?, sector_y=?, sector_z=?, object_id=?, updated_at=? WHERE current_action_id=? AND id IN ($marks)");
            $statement->execute(array_merge([(int) $ship['sector_x'], (int) $ship['sector_y'], (int) $ship['sector_z'], $objectId, $now, $actionId], $batch));
            $updated += $statement->rowCount();
        }
        return $updated;
    }

    /** @param array<string, mixed> $parameters */
    public function claimAuxiliaryTask(array $parameters, bool $deployed): int
    {
        $sql = "UPDATE others_auxiliaries SET status='busy', current_action_id=:action_id, updated_at=:now";
        if ($deployed) { $sql .= ", location_type='deployed', spatial_state=:spatial_state, sector_x=:x, sector_y=:y, sector_z=:z, object_id=:object_id"; }
        $sql .= ' WHERE id=:id AND current_action_id IS NULL';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount();
    }
}
