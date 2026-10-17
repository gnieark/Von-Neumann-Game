<?php

declare(strict_types=1);

namespace VonNeumannGame\Database\Migration;

use PDO;
use VonNeumannGame\Domain\AsteroidTrajectory;
use VonNeumannGame\Repository\MineableObjectStockRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\MineableDescriptors;

/** Maintenance only, after stock import. Historical snapshots remain historical. */
final class AsteroidSnapshotDescriptorMigration
{
    public function __construct(private readonly PDO $pdo) {}

    public function run(bool $apply = false, ?string $backupPath = null): array
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Snapshot migration owns its transaction.'); }
        $this->pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count(AsteroidTrajectory::ACTIVE_STATUSES), '?'));
            $suffix = $apply && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->pdo->prepare("SELECT id,uid,asteroid_id,asteroid_snapshot_json FROM asteroid_trajectories WHERE status IN ($placeholders) ORDER BY id" . $suffix);
            $statement->execute(AsteroidTrajectory::ACTIVE_STATUSES);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $stocks = (new MineableObjectStockRepository($this->pdo))->findMany(array_column($rows, 'asteroid_id'));
            $changes = [];
            foreach ($rows as $row) {
                $data = json_decode($row['asteroid_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
                if (($data['type'] ?? null) !== 'asteroid' || ($data['id'] ?? null) !== $row['asteroid_id']) {
                    throw new \RuntimeException('Invalid active trajectory descriptor: ' . $row['uid']);
                }
                if ($stocks[$row['asteroid_id']]['version'] === null) {
                    throw new \RuntimeException('Import and verify the active source stock first: ' . $row['asteroid_id']);
                }
                $descriptor = MineableDescriptors::descriptor($data);
                Asteroid::fromDescriptor($descriptor); // Validate before touching any row.
                if ($descriptor !== $data) {
                    $changes[] = $row + ['descriptor' => json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
                }
            }
            if ($apply && $changes !== []) {
                if ($backupPath === null || $backupPath === '') { throw new \InvalidArgumentException('--backup is required for snapshot conversion.'); }
                // Exclusive creation: a rerun must never overwrite the evidence from a previous run.
                $handle = @fopen($backupPath, 'x');
                if ($handle === false) { throw new \RuntimeException('Backup already exists or cannot be created; use a new backup path.'); }
                try {
                    $backup = json_encode(['format' => 'asteroid-snapshot-descriptors-v1', 'rows' => $changes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                    if (fwrite($handle, $backup) !== strlen($backup) || !fflush($handle) || !fsync($handle)) { throw new \RuntimeException('Snapshot backup could not be persisted.'); }
                } finally { fclose($handle); }
                $update = $this->pdo->prepare('UPDATE asteroid_trajectories SET asteroid_snapshot_json=? WHERE id=? AND uid=? AND asteroid_snapshot_json=?');
                foreach ($changes as $change) {
                    $update->execute([$change['descriptor'], $change['id'], $change['uid'], $change['asteroid_snapshot_json']]);
                    if ($update->rowCount() !== 1) { throw new \RuntimeException('Trajectory changed during maintenance conversion.'); }
                }
            }
            if ($apply) { $this->pdo->commit(); } else { $this->pdo->rollBack(); }
            return ['apply' => $apply, 'activeTrajectories' => count($rows), 'snapshotsToConvert' => count($changes)];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }
}
