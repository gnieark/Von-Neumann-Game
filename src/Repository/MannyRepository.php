<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Sector\SectorCoordinates;

final class MannyRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function ensureDefaultsForProbe(NeumannProbe $probe): void
    {
        $existing = $this->findByProbeId($probe->id);
        if (count($existing) >= 4) {
            return;
        }

        $names = array_map(static fn(Manny $manny): string => strtolower($manny->name), $existing);
        for ($i = 1; count($existing) < 4 && $i <= 12; $i++) {
            $name = 'manny-' . $i;
            if (in_array($name, $names, true)) {
                continue;
            }

            $existing[] = $this->createForProbe($probe->id, $name);
            $names[] = $name;
        }
    }

    public function createForProbe(int $probeId, string $name): Manny
    {
        $now = gmdate('c');
        $uid = $this->uniqueUid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO mannies
             (uid, probe_id, name, location_type, sector_x, sector_y, sector_z, current_task, task_started_at, task_ends_at, task_payload_json, cargo_deuterium, cargo_metals, cargo_other, created_at, updated_at)
             VALUES (:uid, :probe_id, :name, :location_type, NULL, NULL, NULL, NULL, NULL, NULL, :task_payload_json, 0, 0, 0, :created_at, :updated_at)'
        );
        $stmt->execute([
            'uid' => $uid,
            'probe_id' => $probeId,
            'name' => $name,
            'location_type' => Manny::LOCATION_PROBE,
            'task_payload_json' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? throw new \RuntimeException('Manny creation failed.');
    }

    /**
     * @return array<Manny>
     */
    public function findByProbeId(int $probeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE probe_id = :probe_id ORDER BY name ASC, id ASC');
        $stmt->execute(['probe_id' => $probeId]);

        return array_map(fn(array $row): Manny => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findByUidForProbe(int $probeId, string $uid): ?Manny
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE probe_id = :probe_id AND uid = :uid');
        $stmt->execute(['probe_id' => $probeId, 'uid' => $uid]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByUid(string $uid): ?Manny
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE uid = :uid');
        $stmt->execute(['uid' => $uid]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?Manny
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mannies WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function nameExistsForProbe(int $probeId, string $name, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM mannies WHERE probe_id = :probe_id');
        $stmt->execute(['probe_id' => $probeId]);
        $needle = strtolower($name);
        foreach ($stmt->fetchAll() as $row) {
            if ($exceptId !== null && (int) $row['id'] === $exceptId) {
                continue;
            }
            if (strtolower((string) $row['name']) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function save(Manny $manny): void
    {
        $manny->updatedAt = gmdate('c');
        $stmt = $this->pdo->prepare(
            'UPDATE mannies SET
                probe_id = :probe_id,
                name = :name,
                location_type = :location_type,
                sector_x = :sector_x,
                sector_y = :sector_y,
                sector_z = :sector_z,
                current_task = :current_task,
                task_started_at = :task_started_at,
                task_ends_at = :task_ends_at,
                task_payload_json = :task_payload_json,
                cargo_deuterium = :cargo_deuterium,
                cargo_metals = :cargo_metals,
                cargo_other = :cargo_other,
                updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $manny->id,
            'probe_id' => $manny->probeId,
            'name' => $manny->name,
            'location_type' => $manny->locationType,
            'sector_x' => $manny->sector?->getX(),
            'sector_y' => $manny->sector?->getY(),
            'sector_z' => $manny->sector?->getZ(),
            'current_task' => $manny->currentTask,
            'task_started_at' => $manny->taskStartedAt,
            'task_ends_at' => $manny->taskEndsAt,
            'task_payload_json' => json_encode($manny->taskPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'cargo_deuterium' => $manny->cargoDeuterium,
            'cargo_metals' => $manny->cargoMetals,
            'cargo_other' => $manny->cargoOther,
            'updated_at' => $manny->updatedAt,
        ]);
    }

    private function hydrate(array $row): Manny
    {
        $payload = json_decode((string) ($row['task_payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $sector = $row['sector_x'] === null || $row['sector_y'] === null || $row['sector_z'] === null
            ? null
            : new SectorCoordinates((int) $row['sector_x'], (int) $row['sector_y'], (int) $row['sector_z']);

        return new Manny(
            (int) $row['id'],
            (string) $row['uid'],
            $row['probe_id'] !== null ? (int) $row['probe_id'] : null,
            (string) $row['name'],
            (string) $row['location_type'],
            $sector,
            $row['current_task'] !== null ? (string) $row['current_task'] : null,
            $row['task_started_at'] !== null ? (string) $row['task_started_at'] : null,
            $row['task_ends_at'] !== null ? (string) $row['task_ends_at'] : null,
            $payload,
            (float) ($row['cargo_deuterium'] ?? 0),
            (float) ($row['cargo_metals'] ?? 0),
            (float) ($row['cargo_other'] ?? 0),
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    private function uniqueUid(): string
    {
        do {
            $uid = 'mny_' . bin2hex(random_bytes(12));
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mannies WHERE uid = :uid');
            $stmt->execute(['uid' => $uid]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $uid;
    }
}
