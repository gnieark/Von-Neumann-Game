<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Http\ApiResponse;

final class ProbeCommandRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function execute(int $playerId, ?string $key, string $method, string $path, string $bodyHash, callable $command): ApiResponse
    {
        if ($key !== null && !preg_match('/^[\x21-\x7E]{1,128}$/D', $key)) { return ApiResponse::error(400, 'bad_request', 'Invalid Idempotency-Key.'); }
        return (new StorageTransaction($this->pdo))->run(function () use ($playerId, $key, $method, $path, $bodyHash, $command): ApiResponse {
            if ($key === null) { return $command(); }
            $this->pdo->prepare('UPDATE players SET updated_at=updated_at WHERE id=?')->execute([$playerId]);
            $query = $this->pdo->prepare('SELECT * FROM probe_command_keys WHERE player_id=? AND idempotency_key=?');
            $query->execute([$playerId, $key]);
            $existing = $query->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['request_method'] !== $method || $existing['request_path'] !== $path || !hash_equals($existing['request_body_hash'], $bodyHash)) { return ApiResponse::error(409, 'idempotency_key_conflict', 'This key is bound to another command.'); }
                return new ApiResponse((int) $existing['response_status'], \VonNeumannGame\Service\Storage\StoragePublicData::normalize(json_decode($existing['response_body_json'], true, 512, JSON_THROW_ON_ERROR)));
            }
            $response = $command();
            $this->pdo->prepare('INSERT INTO probe_command_keys(player_id,idempotency_key,request_method,request_path,request_body_hash,response_status,response_body_json,created_at) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$playerId, $key, $method, $path, $bodyHash, $response->status, json_encode($response->body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), gmdate('c')]);
            return $response;
        });
    }
}
