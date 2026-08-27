<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Http\ApiResponse;

final class OthersIdempotencyRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(int $playerId, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM others_idempotency_keys WHERE player_id = :player_id AND idempotency_key = :key');
        $stmt->execute(['player_id' => $playerId, 'key' => $key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function store(int $playerId, string $key, string $method, string $path, string $bodyHash, ApiResponse $response): void
    {
        $actionId = $response->body['action']['id'] ?? null;
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
            'status' => $response->status,
            'response' => json_encode($response->body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'action_id' => is_string($actionId) ? $actionId : null,
            'created_at' => gmdate('c'),
        ]);
    }

    public function responseFrom(array $row): ApiResponse
    {
        return new ApiResponse((int) $row['response_status'], json_decode((string) $row['response_body_json'], true, 512, JSON_THROW_ON_ERROR));
    }
}
