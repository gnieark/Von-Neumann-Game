<?php
declare(strict_types=1);

require_once __DIR__ . '/MiningAtomicFixture.php';

/** Throws after a real write, exercising rollback of the entire scheduler execution. */
final class MiningRetryFaultPdo extends PDO
{
    public ?string $faultSql = null;
    public int $faultCode = 5;
    public ?\Closure $beforeCommit = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MiningRetryFaultStatement::class, [$this]]);
        $this->exec('PRAGMA foreign_keys=ON');
    }

    public function commit(): bool
    {
        if ($this->beforeCommit !== null) { ($this->beforeCommit)($this); }
        return parent::commit();
    }

    public function inject(string $sql): void
    {
        if ($this->faultSql === null || !str_contains($sql, $this->faultSql)) { return; }
        $this->faultSql = null;
        $error = new PDOException('INJECTED secret SQL and payload must not be logged');
        $error->errorInfo = ['HY000', $this->faultCode, 'injected'];
        throw $error;
    }
}

final class MiningRetryFaultStatement extends PDOStatement
{
    protected function __construct(private MiningRetryFaultPdo $connection) {}
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        $this->connection->inject($this->queryString);
        return $result;
    }
}

function miningRetryScheduler(PDO $db, array $f, ?Closure $observer = null): \VonNeumannGame\Service\SchedulerService
{
    $movements = new \VonNeumannGame\Repository\ProbeMovementRepository($db);
    $movement = new \VonNeumannGame\Service\ProbeMovementService($f['probes'], $movements, new \VonNeumannGame\Repository\VisitedSectorRepository($db), $f['events'], $f['sectors']);
    return new \VonNeumannGame\Service\SchedulerService($f['events'], $f['probes'], $movements, $movement, $f['mining'], miningObserver: $observer);
}

function miningRetryDue(PDO $db, array $f, \VonNeumannGame\Domain\Manny $manny): \VonNeumannGame\Domain\ScheduledEvent
{
    $manny = $f['mannies']->findById($manny->id);
    $manny->taskEndsAt = gmdate('c', time() - 1);
    $manny->taskPayload[\VonNeumannGame\Domain\Manny::TASK_SCHEDULED_RUN_AT_PAYLOAD_KEY] = $manny->taskEndsAt;
    $f['mannies']->save($manny);
    return $f['events']->findById($manny->taskScheduledEventId);
}
