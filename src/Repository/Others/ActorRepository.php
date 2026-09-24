<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Others;

use PDO;

final class ActorRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string|int, mixed> $parameters */
    public function findShipById(array $parameters): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM others_ships WHERE id = :id');
        $statement->execute($parameters);
        return $statement->fetch();
    }

    public function findTankForUpdate(int $shipId): array|false
    {
        $sql = 'SELECT deuterium_stock,deuterium_capacity,deuterium_reserved FROM others_ships WHERE id=:id';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $sql .= ' FOR UPDATE'; }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $shipId]);
        return $statement->fetch();
    }
}
