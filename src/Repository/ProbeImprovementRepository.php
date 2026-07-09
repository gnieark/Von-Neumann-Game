<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\ProbeImprovement;
use VonNeumannGame\Domain\ProbeImprovementCatalog;

final class ProbeImprovementRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<ProbeImprovement>
     */
    public function findByProbeId(int $probeId): array
    {
        $playerId = $this->playerIdForProbe($probeId);
        if ($playerId === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT b.id AS blueprint_id,
                    b.player_id,
                    b.improvement,
                    b.created_at AS blueprint_created_at,
                    b.updated_at AS blueprint_updated_at,
                    i.id AS installation_id,
                    i.probe_id,
                    i.created_at AS installation_created_at,
                    i.updated_at AS installation_updated_at
             FROM probe_improvement_blueprints b
             LEFT JOIN probe_improvement_installations i
               ON i.probe_id = :probe_id
              AND i.improvement = b.improvement
             WHERE b.player_id = :player_id
             ORDER BY b.improvement ASC'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'player_id' => $playerId,
        ]);

        return array_map(fn(array $row): ProbeImprovement => $this->hydrateJoined($row), $stmt->fetchAll());
    }

    public function findForProbe(int $probeId, string $improvement): ?ProbeImprovement
    {
        $playerId = $this->playerIdForProbe($probeId);
        if ($playerId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT b.id AS blueprint_id,
                    b.player_id,
                    b.improvement,
                    b.created_at AS blueprint_created_at,
                    b.updated_at AS blueprint_updated_at,
                    i.id AS installation_id,
                    i.probe_id,
                    i.created_at AS installation_created_at,
                    i.updated_at AS installation_updated_at
             FROM probe_improvement_blueprints b
             LEFT JOIN probe_improvement_installations i
               ON i.probe_id = :probe_id
              AND i.improvement = b.improvement
             WHERE b.player_id = :player_id
               AND b.improvement = :improvement'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'player_id' => $playerId,
            'improvement' => ProbeImprovementCatalog::normalizeId($improvement),
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateJoined($row) : null;
    }

    public function isDone(int $probeId, string $improvement): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM probe_improvement_installations
             WHERE probe_id = :probe_id
               AND improvement = :improvement
             LIMIT 1'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'improvement' => ProbeImprovementCatalog::normalizeId($improvement),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function markAvailable(int $probeId, string $improvement): ProbeImprovement
    {
        $playerId = $this->playerIdForProbe($probeId) ?? throw new \RuntimeException('Probe not found.');
        $this->ensureBlueprint($playerId, $improvement);

        return $this->findForProbe($probeId, $improvement) ?? throw new \RuntimeException('Probe improvement availability failed.');
    }

    public function markDone(int $probeId, string $improvement): ProbeImprovement
    {
        $playerId = $this->playerIdForProbe($probeId) ?? throw new \RuntimeException('Probe not found.');
        $improvement = ProbeImprovementCatalog::normalizeId($improvement);
        $this->ensureBlueprint($playerId, $improvement);
        $this->ensureInstallation($probeId, $improvement);

        return $this->findForProbe($probeId, $improvement) ?? throw new \RuntimeException('Probe improvement installation failed.');
    }

    public function deleteInstallationsForProbe(int $probeId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM probe_improvement_installations WHERE probe_id = :probe_id');
        $stmt->execute(['probe_id' => $probeId]);
    }

    private function ensureBlueprint(int $playerId, string $improvement): void
    {
        $improvement = ProbeImprovementCatalog::normalizeId($improvement);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM probe_improvement_blueprints WHERE player_id = :player_id AND improvement = :improvement'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'improvement' => $improvement,
        ]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $now = gmdate('c');
        $insert = $this->pdo->prepare(
            'INSERT INTO probe_improvement_blueprints
             (player_id, improvement, created_at, updated_at)
             VALUES (:player_id, :improvement, :created_at, :updated_at)'
        );
        $insert->execute([
            'player_id' => $playerId,
            'improvement' => $improvement,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureInstallation(int $probeId, string $improvement): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM probe_improvement_installations WHERE probe_id = :probe_id AND improvement = :improvement'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'improvement' => $improvement,
        ]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $now = gmdate('c');
        $insert = $this->pdo->prepare(
            'INSERT INTO probe_improvement_installations
             (probe_id, improvement, created_at, updated_at)
             VALUES (:probe_id, :improvement, :created_at, :updated_at)'
        );
        $insert->execute([
            'probe_id' => $probeId,
            'improvement' => $improvement,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function playerIdForProbe(int $probeId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT player_id FROM neumann_probes WHERE id = :probe_id');
        $stmt->execute(['probe_id' => $probeId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function hydrateJoined(array $row): ProbeImprovement
    {
        $done = $row['installation_id'] !== null;

        return new ProbeImprovement(
            (int) $row['blueprint_id'],
            (int) $row['player_id'],
            $done ? (int) $row['probe_id'] : null,
            (string) $row['improvement'],
            true,
            $done,
            (string) $row['blueprint_created_at'],
            (string) ($done ? $row['installation_updated_at'] : $row['blueprint_updated_at']),
        );
    }
}
