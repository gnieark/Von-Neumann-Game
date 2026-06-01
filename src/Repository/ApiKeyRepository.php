<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ApiKey;

final class ApiKeyRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function createKey(int $playerId, string $plainToken, string $label): ApiKey
    {
        $now = gmdate('c');
        $tokenHash = self::hashToken($plainToken);
        $lastFour = substr($plainToken, -4);
        $stmt = $this->pdo->prepare(
            'INSERT INTO api_keys (player_id, token_hash, label, last_four, created_at, last_used_at, revoked_at)
             VALUES (:player_id, :token_hash, :label, :last_four, :created_at, NULL, NULL)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'token_hash' => $tokenHash,
            'label' => $label,
            'last_four' => $lastFour,
            'created_at' => $now,
        ]);

        return new ApiKey((int) $this->pdo->lastInsertId(), $playerId, $tokenHash, $label, $lastFour, $now, null, null);
    }

    public function findValidByToken(string $plainToken): ?ApiKey
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM api_keys WHERE token_hash = :token_hash AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hashToken($plainToken)]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function touch(ApiKey $apiKey): void
    {
        $apiKey->lastUsedAt = gmdate('c');
        $stmt = $this->pdo->prepare('UPDATE api_keys SET last_used_at = :last_used_at WHERE id = :id');
        $stmt->execute(['id' => $apiKey->id, 'last_used_at' => $apiKey->lastUsedAt]);
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', 'api-key:' . $plainToken);
    }

    private function hydrate(array $row): ApiKey
    {
        return new ApiKey(
            (int) $row['id'],
            (int) $row['player_id'],
            (string) $row['token_hash'],
            (string) $row['label'],
            (string) $row['last_four'],
            (string) $row['created_at'],
            $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
        );
    }
}
