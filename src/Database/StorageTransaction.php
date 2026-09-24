<?php

declare(strict_types=1);

namespace VonNeumannGame\Database;

use PDO;
use PDOException;

/** The owner retries the entire unit of work; participants never commit or swallow errors. */
final class StorageTransaction
{
    private static ?\WeakMap $owners = null;

    public function __construct(private readonly PDO $pdo, private readonly int $maxAttempts = 3, private readonly bool $sqliteWriteIntent = true) {}

    public function run(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) { return $operation(); }
        for ($attempt = 0; ; $attempt++) {
            $this->pdo->beginTransaction();
            self::$owners ??= new \WeakMap();
            self::$owners[$this->pdo] = [];
            try {
                // A write before decision reads upgrades SQLite's deferred transaction.
                if ($this->sqliteWriteIntent && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $this->pdo->exec('UPDATE sector_effect_locks SET sector_x=sector_x WHERE 1=0');
                }
                $result = $operation();
                $this->pdo->commit();
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                unset(self::$owners[$this->pdo]);
                $code = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
                if (!in_array($code, [5, 6, 1205, 1213], true)) { throw $error; }
                if ($attempt >= $this->maxAttempts - 1) { throw new StorageBusyException('Storage transaction retries exhausted.', 0, $error); }
                usleep(10000 * ($attempt + 1));
                continue;
            }
            $callbacks = self::$owners[$this->pdo];
            unset(self::$owners[$this->pdo]);
            foreach ($callbacks as $callback) { $callback(); }
            return $result;
        }
    }

    /** A partially accepted collection rolls back each refused member, including its intentions. */
    public function isolated(callable $operation): mixed
    {
        if (!$this->pdo->inTransaction()) { return $this->run($operation); }
        static $sequence = 0;
        $name = 'storage_member_' . ++$sequence;
        $callbacks = self::$owners[$this->pdo] ?? [];
        $this->pdo->exec('SAVEPOINT ' . $name);
        try {
            $result = $operation();
            $this->pdo->exec('RELEASE SAVEPOINT ' . $name);
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $name);
            }
            if (self::$owners !== null && isset(self::$owners[$this->pdo])) { self::$owners[$this->pdo] = $callbacks; }
            throw $error;
        }
    }

    /** Opportunistic delivery only. Callbacks must leave durable retry records on failure. */
    public static function afterCommit(PDO $pdo, callable $callback): void
    {
        if (self::$owners !== null && isset(self::$owners[$pdo])) {
            $callbacks = self::$owners[$pdo];
            $callbacks[] = $callback;
            self::$owners[$pdo] = $callbacks;
        }
    }

    public function isActive(): bool
    {
        return $this->pdo->inTransaction();
    }

}
