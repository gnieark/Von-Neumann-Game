<?php
declare(strict_types=1);
namespace VonNeumannGame\Repository;

use PDO;

final class StorageMigrationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function assertReady(): void
    {
        $state = $this->pdo->query("SELECT state FROM storage_migrations WHERE name='mineable-stocks'")->fetchColumn();
        if ($state !== false && $state !== 'verified') { throw new \RuntimeException('World storage migration is incomplete; keep API and workers stopped.'); }
    }

    public function state(): ?array
    {
        return $this->pdo->query("SELECT * FROM storage_migrations WHERE name='mineable-stocks'")->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function begin(string $id): void
    {
        $state = $this->state();
        if ($state !== null) {
            if ($state['migration_id'] !== $id) { throw new \RuntimeException('Database belongs to another migration manifest.'); }
            return;
        }
        $this->pdo->prepare("INSERT INTO storage_migrations(name,migration_id,state,plan_hash) VALUES('mineable-stocks',?,'blocked',NULL)")->execute([$id]);
    }

    public function bindPlan(string $id, string $hash): void
    {
        $state = $this->state();
        if ($state === null || $state['migration_id'] !== $id || ($state['plan_hash'] !== null && $state['plan_hash'] !== $hash)) { throw new \RuntimeException('Migration plan does not match database.'); }
        $this->pdo->prepare("UPDATE storage_migrations SET plan_hash=? WHERE name='mineable-stocks' AND migration_id=?")->execute([$hash, $id]);
    }

    public function verified(string $id): void
    {
        $this->pdo->prepare("UPDATE storage_migrations SET state='verified' WHERE name='mineable-stocks' AND migration_id=? AND plan_hash IS NOT NULL")->execute([$id]);
    }
}
