<?php

declare(strict_types=1);

namespace VonNeumannGame\Database;

/** Only the scheduler's atomic mining path may use this policy. */
final class MiningRetryPolicy
{
    public function __construct(
        private readonly array $delays = [1, 5, 15, 60, 300],
        public readonly int $alertAfterSeconds = 900,
    ) {
        if ($delays === [] || !array_is_list($delays) || $alertAfterSeconds < 1) {
            throw new \InvalidArgumentException('Invalid mining retry configuration.');
        }
        $previous = 0;
        foreach ($delays as $delay) {
            if (!is_int($delay) || $delay < max(1, $previous) || $delay > 300) {
                throw new \InvalidArgumentException('Mining retry delays must increase and stay within 1..300 seconds.');
            }
            $previous = $delay;
        }
    }

    public function delay(int $consecutiveConflicts): int
    {
        $base = $this->delays[min(max(0, $consecutiveConflicts - 1), count($this->delays) - 1)];
        // +/- 20%, with a hard five-minute ceiling, including jitter at the ceiling.
        $spread = intdiv($base, 5);
        return random_int(max(1, $base - $spread), min(300, $base + $spread));
    }

    /** No message matching: integrity/business errors must never become retries. */
    public static function conflict(\Throwable $error, string $driver): ?array
    {
        while ($error instanceof StorageBusyException && $error->getPrevious() !== null) { $error = $error->getPrevious(); }
        if (!$error instanceof \PDOException) { return null; }
        $code = (int) ($error->errorInfo[1] ?? 0);
        $allowed = match ($driver) { 'mysql' => [1205, 1213, 3572], 'sqlite' => [5, 6], default => [] };
        if (!in_array($code, $allowed, true)) { return null; }
        $family = 'mining_transaction';
        foreach ($error->getTrace() as $frame) {
            $class = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            $candidate = match (true) {
                str_ends_with($class, '\\MineableObjectStockRepository') => 'mineable_stock',
                str_ends_with($class, '\\ProbeOperationRepository') => 'probe_admission',
                str_ends_with($class, '\\ScheduledEventRepository') => 'scheduler_event',
                str_ends_with($class, '\\MannyRepository') => 'manny_task',
                str_ends_with($class, '\\NeumannProbeRepository') && str_contains(strtolower($function), 'deuterium') => 'fuel_tank',
                str_ends_with($class, '\\StorageLockRepository') => 'storage_root',
                str_ends_with($class, '\\StorageContainerRepository'),
                str_ends_with($class, '\\DetachedStorageContainerRepository') => 'storage_capacity',
                default => null,
            };
            if ($candidate !== null) { $family = $candidate; break; }
        }
        return ['sql_code' => $code, 'sql_state' => (string) ($error->errorInfo[0] ?? ''), 'lock_family' => $family];
    }
}
