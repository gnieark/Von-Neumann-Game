<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Http\ApiResponse;
use VonNeumannGame\Repository\OthersIdempotencyRepository;
use VonNeumannGame\Repository\OthersAuditRepository;

/** One transaction owns the command, its audit and its replayable response. */
final class OthersCommandService
{
    public function __construct(
        private readonly StorageTransaction $transaction,
        private readonly OthersIdempotencyRepository $othersIdempotency,
        private readonly ?OthersAuditRepository $othersAudit = null,
    ) {}

    public function execute(Player $player, string $method, string $path, ?string $key, string $hash, callable $command): ApiResponse
    {
        if ($key === null) {
            return $this->transaction->run(function () use ($command, $player, $method, $path): ApiResponse {
                $response = $command();
                $this->othersAudit?->record($player->id, 'http', $method . ' ' . $path, $response->status < 400 ? 'accepted' : 'refused');
                return $response;
            });
        }
        return $this->transaction->run(function () use ($method, $path, $player, $command, $key, $hash): ApiResponse {
            $this->othersIdempotency->lockAccount($player->id);
            $existing = $this->othersIdempotency->find($player->id, $key);
            if ($existing !== null) {
                if ($existing['request_method'] !== $method || $existing['request_path'] !== $path || !hash_equals((string) $existing['request_body_hash'], $hash)) {
                    return ApiResponse::error(409, 'idempotency_key_conflict', 'This idempotency key is already bound to another command.');
                }
                return $this->storedResponse($existing);
            }
            $response = $command();
            $this->othersIdempotency->store($player->id, $key, $method, $path, $hash, $response->status, $response->body);
            $this->othersAudit?->record($player->id, 'http', $method . ' ' . $path, $response->status < 400 ? 'accepted' : 'refused', details: ['idempotencyKeyHash' => hash('sha256', $key)]);
            return $response;
        });
    }
    private function storedResponse(array $row): ApiResponse
    {
        $body = json_decode((string) $row['response_body_json'], true, 512, JSON_THROW_ON_ERROR);
        if (in_array($body['action']['type'] ?? null, ['build_germination_depot','depot_deposit','depot_withdrawal'], true)) {
            $body = \VonNeumannGame\Service\Storage\StoragePublicData::normalize($body);
        }
        return new ApiResponse((int) $row['response_status'], $body);
    }
}
