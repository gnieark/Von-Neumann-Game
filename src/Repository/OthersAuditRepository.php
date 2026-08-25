<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;

final class OthersAuditRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function record(?int $playerId, string $source, string $command, string $outcome, ?string $entityPublicId = null, array $details = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO others_operator_audit (player_id, source, command, entity_public_id, outcome, details_json, created_at)
             VALUES (:player_id, :source, :command, :entity_public_id, :outcome, :details_json, :created_at)'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'source' => $source,
            'command' => $command,
            'entity_public_id' => $entityPublicId,
            'outcome' => $outcome,
            'details_json' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => gmdate('c'),
        ]);
    }
}
