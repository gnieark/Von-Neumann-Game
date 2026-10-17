<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository;

use PDO;
use PDOException;
use VonNeumannGame\Domain\MineableResourceAmounts;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Repository\Storage\StorageLockRepository;

/** All mutations join the caller's transaction. No load-time insertion or JSON fallback. */
final class MineableObjectStockRepository
{
    private readonly StorageLockRepository $locks;

    public function __construct(private readonly PDO $pdo)
    {
        $this->locks = new StorageLockRepository($pdo);
    }

    /** @return array<string, array{amounts: array<string, float>, version: ?int}> */
    public function findMany(array $objectIds): array
    {
        $result = [];
        foreach (array_unique($objectIds) as $id) {
            self::validateId($id);
            $result[$id] = ['amounts' => MineableResourceAmounts::zero(), 'version' => null];
        }
        foreach (array_chunk(array_keys($result), 400) as $ids) {
            $stmt = $this->pdo->prepare('SELECT * FROM mineable_object_stocks WHERE object_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $result[$row['object_id']] = $this->hydrate($row); }
        }
        return $result;
    }

    public function find(string $objectId): array { return $this->findMany([$objectId])[$objectId]; }

    /** Acquire every identity in byte order before accessing any of its stocks. */
    public function lockMany(array $objectIds): array
    {
        $this->requireTransaction();
        $ids = array_values(array_unique($objectIds));
        foreach ($ids as $id) { self::validateId($id); }
        sort($ids, SORT_STRING);
        foreach ($ids as $id) { $this->locks->lock('mineable', $id); }
        $result = [];
        foreach ($ids as $id) {
            $stmt = $this->pdo->prepare('SELECT * FROM mineable_object_stocks WHERE object_id=?' . $this->forUpdate());
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $result[$id] = $row === false ? ['amounts' => MineableResourceAmounts::zero(), 'version' => null] : $this->hydrate($row);
        }
        return $result;
    }

    /** The operation+slot key must be stable across retries; a replay never restores stocks. */
    public function create(string $creationIdentity, string $candidateId, array $amounts): string
    {
        $this->requireTransaction();
        self::validateId($candidateId);
        if ($creationIdentity === '') { throw new \InvalidArgumentException('A durable creation identity is required.'); }
        $amounts = MineableResourceAmounts::normalize($amounts);
        $key = hash('sha256', $creationIdentity);
        $existing = $this->findCreation($key, false);
        if ($existing !== null) { return $existing; }
        for ($attempt = 0; $attempt < 32; ++$attempt) {
            $id = $attempt === 0 ? $candidateId : self::collisionCandidate($candidateId, $key, $attempt);
            try {
                $this->pdo->prepare('INSERT INTO mineable_object_identities(object_id,creation_key) VALUES(?,?)')->execute([$id, $key]);
            } catch (PDOException $error) {
                if (!$this->isUniqueViolation($error)) { throw $error; }
                // A concurrent replay may have won either unique key; use a current read.
                $existing = $this->findCreation($key, true);
                if ($existing !== null) { return $existing; }
                continue;
            }
            $this->insertStock($id, $amounts);
            return $id;
        }
        throw new \RuntimeException('Mineable identity collision limit reached.');
    }

    /** Explicit migration reservation for recoverable historical IDs, without a stock. */
    public function reserveHistoricalIdentity(string $objectId): void
    {
        $this->requireTransaction();
        self::validateId($objectId);
        try {
            $this->pdo->prepare('INSERT INTO mineable_object_identities(object_id,creation_key) VALUES(?,NULL)')->execute([$objectId]);
        } catch (PDOException $error) {
            if (!$this->isUniqueViolation($error)) { throw $error; }
            if ($this->locks->lock('mineable', $objectId) === null) { throw $error; }
        }
    }

    /** Maintenance-only import: resume verifies existing values, never overwrites or repairs them. */
    public function importExisting(string $migrationIdentity, string $objectId, array $amounts): void
    {
        $this->requireTransaction();
        if ($migrationIdentity === '') { throw new \InvalidArgumentException('An import manifest identity is required.'); }
        $amounts = MineableResourceAmounts::normalize($amounts);
        $this->reserveHistoricalIdentity($objectId);
        $identity = $this->locks->lock('mineable', $objectId);
        $key = hash('sha256', $migrationIdentity);
        $stock = $this->lockMany([$objectId])[$objectId];
        if ($identity['creation_key'] === $key) {
            if ($stock['version'] === null || $stock['amounts'] !== $amounts) { throw new \RuntimeException('Previously imported stock is missing or has changed; refusing to restore it.'); }
            return;
        }
        if ($identity['creation_key'] !== null || $stock['version'] !== null) { throw new \RuntimeException('Import identity is already attributed to another operation.'); }
        $this->pdo->prepare('UPDATE mineable_object_identities SET creation_key=? WHERE object_id=? AND creation_key IS NULL')->execute([$key, $objectId]);
        $this->insertStock($objectId, $amounts);
    }

    /** Exact debit. A missing row can supply zero only; the caller credits these same amounts. */
    public function debit(string $objectId, array $amounts): array
    {
        $amounts = MineableResourceAmounts::normalize($amounts);
        $stock = $this->lockMany([$objectId])[$objectId];
        $remaining = MineableResourceAmounts::subtract($stock['amounts'], $amounts);
        if ($stock['version'] !== null && array_sum($amounts) > 0.0) { $this->updateStock($objectId, $remaining, $stock['version']); }
        return $amounts;
    }

    /** Structural operations call this under the same identity locks as mining. */
    public function delete(string $objectId): void
    {
        $this->lockMany([$objectId]);
        $this->pdo->prepare('DELETE FROM mineable_object_stocks WHERE object_id=?')->execute([$objectId]);
    }

    /** Consume sources exactly once and create one result; a missing source contributes zero. */
    public function merge(string $creationIdentity, array $sourceIds, string $candidateId): string
    {
        $this->requireTransaction();
        if ($creationIdentity === '' || $sourceIds === []) { throw new \InvalidArgumentException('A merge requires a durable identity and sources.'); }
        $stocks = $this->lockMany($sourceIds);
        $existing = $this->findCreation(hash('sha256', $creationIdentity), true);
        if ($existing !== null) { return $existing; }
        $total = MineableResourceAmounts::zero();
        foreach ($stocks as $stock) { $total = MineableResourceAmounts::add($total, $stock['amounts']); }
        $id = $this->create($creationIdentity, $candidateId, $total);
        foreach (array_keys($stocks) as $sourceId) {
            $this->pdo->prepare('DELETE FROM mineable_object_stocks WHERE object_id=?')->execute([$sourceId]);
        }
        return $id;
    }

    public function replaceIdentity(string $creationIdentity, string $sourceId, string $candidateId): string
    {
        return $this->merge($creationIdentity, [$sourceId], $candidateId);
    }

    public static function validateId(string $id): void
    {
        if (!preg_match('/^[\x21-\x7e]{1,255}$/D', $id)) { throw new \InvalidArgumentException('Mineable IDs must contain 1 to 255 non-whitespace ASCII bytes.'); }
    }

    private static function collisionCandidate(string $original, string $key, int $attempt): string
    {
        // Preserve the existing generator's prefix and suffix width, including mtr_.
        if (preg_match('/^(.*?)([a-f0-9]{16,64})$/D', $original, $match)) {
            return $match[1] . substr(hash('sha256', $key . ':' . $attempt), 0, strlen($match[2]));
        }
        return substr(hash('sha256', $key . ':' . $attempt), 0, 20);
    }

    private function insertStock(string $id, array $amounts): void
    {
        $this->pdo->prepare('INSERT INTO mineable_object_stocks(object_id,deuterium,metals,ice,carbon_compounds,version) VALUES(?,?,?,?,?,1)')
            ->execute([$id, ...$this->sqlAmounts($amounts)]);
    }

    private function updateStock(string $id, array $amounts, int $version): void
    {
        $stmt = $this->pdo->prepare('UPDATE mineable_object_stocks SET deuterium=?,metals=?,ice=?,carbon_compounds=?,version=version+1 WHERE object_id=? AND version=?');
        $stmt->execute([...$this->sqlAmounts($amounts), $id, $version]);
        if ($stmt->rowCount() !== 1) { throw new \RuntimeException('Mineable stock changed or disappeared during its locked transition.'); }
    }

    private function findCreation(string $key, bool $locked): ?string
    {
        $stmt = $this->pdo->prepare('SELECT object_id FROM mineable_object_identities WHERE creation_key=?' . ($locked ? $this->forUpdate() : ''));
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    private function sqlAmounts(array $amounts): array
    {
        // PDO otherwise stringifies floats using PHP's precision setting (often only 14 digits).
        return array_map(static fn(string $type): string => number_format($amounts[$type], 4, '.', ''), ResourceComposition::TYPES);
    }

    private function hydrate(array $row): array
    {
        $amounts = [];
        foreach (ResourceComposition::TYPES as $type) { $amounts[$type] = (float) $row[$type]; }
        return ['amounts' => MineableResourceAmounts::normalize($amounts), 'version' => (int) $row['version']];
    }

    private function forUpdate(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }

    private function isUniqueViolation(PDOException $error): bool
    {
        return (int) ($error->errorInfo[1] ?? 0) === 1062
            || ((int) ($error->errorInfo[1] ?? 0) === 19 && str_contains($error->getMessage(), 'UNIQUE constraint failed'));
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Mineable stock mutations require a caller-owned transaction.'); }
    }
}
