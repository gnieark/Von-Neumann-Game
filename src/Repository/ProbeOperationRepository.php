<?php
declare(strict_types=1);
namespace VonNeumannGame\Repository;

use PDO;
use VonNeumannGame\Database\StorageTransaction;

/** Short admission, durable ticket, then a separately owned business transaction. */
final class ProbeOperationRepository
{
    private static ?\WeakMap $contexts = null;

    public function __construct(private readonly PDO $pdo) {}

    public function create(int $probeId): void
    {
        $this->pdo->prepare('INSERT INTO probe_operation_gates(probe_id,closed) VALUES(?,0)')->execute([$probeId]);
    }

    private function covered(int $probeId): bool
    {
        return in_array($probeId, self::$contexts[$this->pdo] ?? [], true);
    }

    /** Legacy writers hold a shared guard until their owning transaction ends. */
    public function protect(array $probeIds): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Probe protection requires an owning transaction.'); }
        sort($probeIds, SORT_NUMERIC);
        foreach (array_unique($probeIds) as $id) {
            if ($this->covered($id)) { continue; }
            $stmt = $this->pdo->prepare('SELECT closed FROM probe_operation_gates WHERE probe_id=?' . ($this->mysql() ? ' LOCK IN SHARE MODE' : ''));
            $stmt->execute([$id]);
            $closed = $stmt->fetchColumn();
            if ($closed === false || (int) $closed !== 0) {
                $status = $this->pdo->prepare('SELECT status FROM neumann_probes WHERE id=?');
                $status->execute([$id]);
                $code = $status->fetchColumn() === \VonNeumannGame\Domain\ProbeStatus::TrappedByBlackHole->value ? 'probe_trapped_by_black_hole' : 'probe_dead';
                throw new ProbeAdmissionClosedException('Probe admissions are closed: ' . $id, $code);
            }
        }
    }

    public function run(array $probeIds, callable $operation): mixed
    {
        $probeIds = array_values(array_unique($probeIds));
        if (array_diff($probeIds, self::$contexts[$this->pdo] ?? []) === []) { return $operation(); }
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Admit probe operations before opening the business transaction.');
        }
        $ticket = $this->admit($probeIds);
        try { return $this->execute($ticket, $probeIds, $operation); }
        finally {
            // A process crash leaves the ticket for fenced lease recovery.
            $this->pdo->prepare('DELETE FROM probe_operations WHERE token=?')->execute([$ticket]);
        }
    }

    public function admit(array $probeIds, int $leaseSeconds = 60): string
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Admission must own its transaction.'); }
        return $this->transaction()->run(function () use ($probeIds, $leaseSeconds): string {
            $this->protect($probeIds);
            $token = bin2hex(random_bytes(24));
            $stmt = $this->pdo->prepare('INSERT INTO probe_operations(token,probe_id,expires_at) VALUES(?,?,?)');
            sort($probeIds, SORT_NUMERIC);
            foreach (array_unique($probeIds) as $id) { $stmt->execute([$token, $id, time() + max(1, $leaseSeconds)]); }
            return $token;
        });
    }

    public function execute(string $token, array $probeIds, callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('An admitted operation must own its transaction.'); }
        return $this->transaction()->run(function () use ($token, $probeIds, $operation): mixed {
            $stmt = $this->pdo->prepare('SELECT probe_id,expires_at FROM probe_operations WHERE token=? ORDER BY probe_id' . ($this->mysql() ? ' FOR UPDATE' : ''));
            $stmt->execute([$token]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $actual = array_map(static fn(array $r): int => (int) $r['probe_id'], $rows);
            sort($probeIds, SORT_NUMERIC);
            if ($actual !== array_values(array_unique($probeIds)) || $rows === [] || (int) $rows[0]['expires_at'] <= time()) {
                throw new ProbeAdmissionClosedException('Probe operation lease lost.');
            }
            $result = $this->internal($probeIds, $operation);
            $this->pdo->prepare('DELETE FROM probe_operations WHERE token=?')->execute([$token]);
            return $result;
        });
    }

    /** Only the loss coordinator may use this after closing and draining. */
    public function internal(array $probeIds, callable $operation): mixed
    {
        self::$contexts ??= new \WeakMap();
        $previous = self::$contexts[$this->pdo] ?? [];
        self::$contexts[$this->pdo] = array_values(array_unique([...$previous, ...$probeIds]));
        try { return $operation(); }
        finally { self::$contexts[$this->pdo] = $previous; }
    }

    /** Closing never waits on a legacy writer. The caller retries on the scheduler. */
    public function close(int $probeId): bool
    {
        return $this->transaction()->run(function () use ($probeId): bool {
            if (!$this->tryLock('probe_operation_gates', 'probe_id', $probeId)) { return false; }
            $this->pdo->prepare('UPDATE probe_operation_gates SET closed=1 WHERE probe_id=?')->execute([$probeId]);
            return true;
        });
    }

    /** A live business transaction owns its ticket row even after the deadline. */
    public function drained(int $probeId): bool
    {
        $stmt = $this->pdo->prepare('SELECT token,expires_at FROM probe_operations WHERE probe_id=? ORDER BY token');
        $stmt->execute([$probeId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['expires_at'] > time()) { return false; }
            if (!$this->tryLock('probe_operations', 'token', $row['token'])) { return false; }
            $this->pdo->prepare('DELETE FROM probe_operations WHERE token=? AND expires_at<=?')->execute([$row['token'], time()]);
        }
        return true;
    }

    /** Bound orphan growth even on probes that are never destroyed. */
    public function recoverExpired(): int
    {
        return $this->transaction()->run(function (): int {
            $stmt = $this->pdo->prepare('SELECT DISTINCT token FROM probe_operations WHERE expires_at<=? ORDER BY token LIMIT 100');
            $stmt->execute([time()]);
            $count = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $token) {
                if (!$this->tryLock('probe_operations', 'token', $token)) { continue; }
                $delete = $this->pdo->prepare('DELETE FROM probe_operations WHERE token=? AND expires_at<=?');
                $delete->execute([$token, time()]);
                $count += $delete->rowCount();
            }
            return $count;
        });
    }

    public function tryLock(string $table, string $key, int|string $value): bool
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Nonblocking locks require a transaction.'); }
        if (!in_array([$table, $key], [['probe_operation_gates', 'probe_id'], ['probe_operations', 'token'], ['probe_losses', 'probe_id']], true)) { throw new \LogicException('Invalid coordination root.'); }
        try {
            $stmt = $this->pdo->prepare("SELECT $key FROM $table WHERE $key=?" . ($this->mysql() ? ' FOR UPDATE NOWAIT' : ''));
            $stmt->execute([$value]);
            return $stmt->fetchColumn() !== false;
        } catch (\PDOException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 3572], true)) { return false; }
            throw $error;
        }
    }

    private function transaction(): StorageTransaction { return new StorageTransaction($this->pdo, maxAttempts: 1, readCommitted: true); }
    private function mysql(): bool { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
}
