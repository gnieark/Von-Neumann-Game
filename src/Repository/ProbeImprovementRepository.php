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
        $stmt = $this->pdo->prepare('SELECT * FROM probe_improvements WHERE probe_id = :probe_id ORDER BY improvement ASC');
        $stmt->execute(['probe_id' => $probeId]);

        return array_map(fn(array $row): ProbeImprovement => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findForProbe(int $probeId, string $improvement): ?ProbeImprovement
    {
        $stmt = $this->pdo->prepare('SELECT * FROM probe_improvements WHERE probe_id = :probe_id AND improvement = :improvement');
        $stmt->execute([
            'probe_id' => $probeId,
            'improvement' => ProbeImprovementCatalog::normalizeId($improvement),
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function isDone(int $probeId, string $improvement): bool
    {
        return $this->findForProbe($probeId, $improvement)?->done ?? false;
    }

    public function ensure(int $probeId, string $improvement, bool $available = false, bool $done = false): ProbeImprovement
    {
        $improvement = ProbeImprovementCatalog::normalizeId($improvement);
        $existing = $this->findForProbe($probeId, $improvement);
        if ($existing !== null) {
            return $existing;
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO probe_improvements
             (probe_id, improvement, available, done, created_at, updated_at)
             VALUES (:probe_id, :improvement, :available, :done, :created_at, :updated_at)'
        );
        $stmt->execute([
            'probe_id' => $probeId,
            'improvement' => $improvement,
            'available' => $available ? 1 : 0,
            'done' => $done ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findForProbe($probeId, $improvement) ?? throw new \RuntimeException('Probe improvement creation failed.');
    }

    public function markAvailable(int $probeId, string $improvement): ProbeImprovement
    {
        $row = $this->ensure($probeId, $improvement);
        $row->available = true;
        $this->save($row);

        return $this->findForProbe($probeId, $row->improvement) ?? $row;
    }

    public function markDone(int $probeId, string $improvement): ProbeImprovement
    {
        $row = $this->ensure($probeId, $improvement);
        $row->available = true;
        $row->done = true;
        $this->save($row);

        return $this->findForProbe($probeId, $row->improvement) ?? $row;
    }

    private function save(ProbeImprovement $improvement): void
    {
        $improvement->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE probe_improvements
             SET available = :available, done = :done, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $improvement->id,
            'available' => $improvement->available ? 1 : 0,
            'done' => $improvement->done ? 1 : 0,
            'updated_at' => $improvement->updatedAt,
        ]);
    }

    private function hydrate(array $row): ProbeImprovement
    {
        return new ProbeImprovement(
            (int) $row['id'],
            (int) $row['probe_id'],
            (string) $row['improvement'],
            (int) $row['available'] === 1,
            (int) $row['done'] === 1,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
