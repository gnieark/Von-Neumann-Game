<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Sector\SectorCoordinates;

final class PlayerRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function createPlayer(string $username, ?string $displayName, ?string $passwordHash = null, ?SectorCoordinates $homeSector = null): Player
    {
        $homeSector ??= SectorCoordinates::origin();
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO players (username, display_name, password_hash, home_sector_x, home_sector_y, home_sector_z, created_at, updated_at)
             VALUES (:username, :display_name, :password_hash, :x, :y, :z, :created_at, :updated_at)'
        );
        $stmt->execute([
            'username' => $username,
            'display_name' => $displayName,
            'password_hash' => $passwordHash,
            'x' => $homeSector->getX(),
            'y' => $homeSector->getY(),
            'z' => $homeSector->getZ(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Player creation failed.');
    }

    public function findById(int $id): ?Player
    {
        $stmt = $this->pdo->prepare('SELECT * FROM players WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByUsername(string $username): ?Player
    {
        $stmt = $this->pdo->prepare('SELECT * FROM players WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function existsByUsername(string $username): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM players WHERE username = :username');
        $stmt->execute(['username' => $username]);

        return (bool) $stmt->fetchColumn();
    }

    public function save(Player $player): void
    {
        $player->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE players SET username = :username, display_name = :display_name, home_sector_x = :x, home_sector_y = :y, home_sector_z = :z, forum_admin = :forum_admin, forum_moderator = :forum_moderator, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $player->id,
            'username' => $player->username,
            'display_name' => $player->displayName,
            'x' => $player->homeSector->getX(),
            'y' => $player->homeSector->getY(),
            'z' => $player->homeSector->getZ(),
            'forum_admin' => $player->forumAdmin ? 1 : 0,
            'forum_moderator' => $player->forumModerator ? 1 : 0,
            'updated_at' => $player->updatedAt,
        ]);
    }

    private function hydrate(array $row): Player
    {
        return new Player(
            (int) $row['id'],
            (string) $row['username'],
            $row['display_name'] !== null ? (string) $row['display_name'] : null,
            new SectorCoordinates((int) $row['home_sector_x'], (int) $row['home_sector_y'], (int) $row['home_sector_z']),
            (string) $row['created_at'],
            (string) $row['updated_at'],
            (bool) ($row['forum_admin'] ?? false),
            (bool) ($row['forum_moderator'] ?? false),
        );
    }
}
