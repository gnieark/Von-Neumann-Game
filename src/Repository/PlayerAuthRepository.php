<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\AuthProvider;
use VonNeumannGame\Domain\PlayerAuthMethod;

final class PlayerAuthRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function addPasswordAuth(int $playerId, string $passwordHash): PlayerAuthMethod
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO player_auth_methods (player_id, provider, provider_user_id, password_hash, created_at)
             VALUES (:player_id, :provider, NULL, :password_hash, :created_at)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'provider' => AuthProvider::Password->value,
            'password_hash' => $passwordHash,
            'created_at' => $now,
        ]);

        return new PlayerAuthMethod((int) $this->pdo->lastInsertId(), $playerId, AuthProvider::Password->value, null, $passwordHash, $now);
    }

    public function findPasswordAuthByUsername(string $username): ?PlayerAuthMethod
    {
        $stmt = $this->pdo->prepare(
            'SELECT pam.* FROM player_auth_methods pam
             INNER JOIN players p ON p.id = pam.player_id
             WHERE p.username = :username AND pam.provider = :provider
             ORDER BY pam.id DESC LIMIT 1'
        );
        $stmt->execute(['username' => $username, 'provider' => AuthProvider::Password->value]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function addExternalAuth(int $playerId, string $provider, string $providerUserId): PlayerAuthMethod
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO player_auth_methods (player_id, provider, provider_user_id, password_hash, created_at)
             VALUES (:player_id, :provider, :provider_user_id, NULL, :created_at)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'created_at' => $now,
        ]);

        return new PlayerAuthMethod((int) $this->pdo->lastInsertId(), $playerId, $provider, $providerUserId, null, $now);
    }

    private function hydrate(array $row): PlayerAuthMethod
    {
        return new PlayerAuthMethod(
            (int) $row['id'],
            (int) $row['player_id'],
            (string) $row['provider'],
            $row['provider_user_id'] !== null ? (string) $row['provider_user_id'] : null,
            $row['password_hash'] !== null ? (string) $row['password_hash'] : null,
            (string) $row['created_at'],
        );
    }
}
