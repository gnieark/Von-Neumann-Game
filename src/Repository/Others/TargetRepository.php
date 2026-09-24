<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Others;

use PDO;

final class TargetRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string|int, mixed> $parameters */
    public function findShipTarget(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT id,public_id,departure_engaged FROM others_ships WHERE public_id=:id AND sector_x=:x AND sector_y=:y AND sector_z=:z AND destroyed_at IS NULL AND status<>'transit' AND status<>'removed'");
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findAuxiliaryTarget(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT id,public_id,sector_x,sector_y,sector_z FROM others_auxiliaries WHERE public_id=:id AND sector_x=:x AND sector_y=:y AND sector_z=:z AND location_type='deployed' AND destroyed_at IS NULL AND status<>'dormant'");
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findProjectileTarget(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT public_id FROM others_projectiles WHERE public_id=:id AND sector_x=:x AND sector_y=:y AND sector_z=:z AND status='moving'");
        $statement->execute($parameters);
        return $statement->fetch();
    }

    /** @param array<string|int, mixed> $parameters */
    public function findAsteroidTarget(array $parameters): array|false
    {
        $statement = $this->pdo->prepare("SELECT * FROM asteroid_trajectories WHERE (asteroid_id=:id OR uid=:id) AND current_sector_x=:x AND current_sector_y=:y AND current_sector_z=:z AND status IN ('accelerating','coasting','crossing_sector','orbiting_black_hole') ORDER BY id DESC LIMIT 1");
        $statement->execute($parameters);
        return $statement->fetch();
    }
}
