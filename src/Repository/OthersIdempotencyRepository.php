<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;

final class OthersIdempotencyRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function lockAccount(int $playerId): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->prepare('UPDATE players SET updated_at=updated_at WHERE id=?')->execute([$playerId]);
        }
        $sql = 'SELECT id FROM players WHERE id=?';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') { $sql .= ' FOR UPDATE'; }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$playerId]);
        if ($statement->fetchColumn() === false) { throw new \RuntimeException('Unable to lock the idempotency account.'); }
    }

    public function find(int $playerId, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM others_idempotency_keys WHERE player_id = :player_id AND idempotency_key = :key');
        $stmt->execute(['player_id' => $playerId, 'key' => $key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function store(int $playerId, string $key, string $method, string $path, string $bodyHash, int $status, array $body): void
    {
        $actionId = $body['action']['id'] ?? null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO others_idempotency_keys
             (player_id, idempotency_key, request_method, request_path, request_body_hash, response_status, response_body_json, action_public_id, created_at)
             VALUES (:player_id, :key, :method, :path, :body_hash, :status, :response, :action_id, :created_at)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'key' => $key,
            'method' => $method,
            'path' => $path,
            'body_hash' => $bodyHash,
            'status' => $status,
            'response' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'action_id' => is_string($actionId) ? $actionId : null,
            'created_at' => gmdate('c'),
        ]);
    }

}
