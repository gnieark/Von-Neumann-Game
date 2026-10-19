<?php
declare(strict_types=1);

namespace VonNeumannGame\Operations;

use PDO;
use VonNeumannGame\Database\Migration\MaintenanceFiles;
use VonNeumannGame\Database\Migration\WorldMaintenanceBackup;
use VonNeumannGame\Repository\StorageMigrationRepository;

/** Observes the live world without comparing mutable stocks to the migration baseline. */
final class MineableCutoverMonitor
{
    public function __construct(private readonly PDO $pdo) {}

    public function assertManifest(string $directory, string $universe): string
    {
        $meta = MaintenanceFiles::read($directory . '/manifest.json');
        $state = (new StorageMigrationRepository($this->pdo))->state();
        if (($meta['complete'] ?? false) !== true
            || ($meta['fingerprint'] ?? null) !== (new WorldMaintenanceBackup($this->pdo, $universe))->fingerprint()
            || ($state['state'] ?? null) !== 'verified'
            || ($state['migration_id'] ?? null) !== ($meta['migrationId'] ?? null)
            || ($state['plan_hash'] ?? null) !== hash_file('sha256', $directory . '/plan.json')) {
            throw new \RuntimeException('Migration non vérifiée ou manifeste incompatible avec cette paire SQL/univers.');
        }
        return $state['migration_id'];
    }

    /** Caller supplies a read-only snapshot. Counters describe symptoms, not automatic repairs. */
    public function collect(int $now, int $since, int $leaseSeconds = 3600, int $alertSeconds = 900): array
    {
        if ($since > $now || $leaseSeconds < 60 || $alertSeconds < 1) {
            throw new \InvalidArgumentException('Fenêtre ou seuils de surveillance invalides.');
        }
        $timestamp = gmdate('c', $now);
        $sinceAt = gmdate('c', $since);
        $oldAt = gmdate('c', $now - $alertSeconds);
        $events = $this->pdo->query('SELECT type,status,COUNT(*) AS count FROM scheduled_events GROUP BY type,status ORDER BY type,status')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as &$row) { $row['count'] = (int) $row['count']; }
        unset($row);
        $count = function (string $sql, array $params = []): int {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return (int) $statement->fetchColumn();
        };
        $counters = [
            'stocks' => $count('SELECT COUNT(*) FROM mineable_object_stocks'),
            'identities' => $count('SELECT COUNT(*) FROM mineable_object_identities'),
            'invalidStocks' => $count('SELECT COUNT(*) FROM mineable_object_stocks WHERE version < 1 OR deuterium < 0 OR metals < 0 OR ice < 0 OR carbon_compounds < 0'),
            'stocksWithoutIdentity' => $count('SELECT COUNT(*) FROM mineable_object_stocks s LEFT JOIN mineable_object_identities i ON i.object_id=s.object_id WHERE i.object_id IS NULL'),
            'forgottenMannies' => $count('SELECT COUNT(*) FROM mannies WHERE is_forgotten=1'),
            'duePendingEvents' => $count("SELECT COUNT(*) FROM scheduled_events WHERE status='pending' AND run_at<=?", [$timestamp]),
            'oldDuePendingEvents' => $count("SELECT COUNT(*) FROM scheduled_events WHERE status='pending' AND run_at<=?", [$oldAt]),
            'expiredRunningEvents' => $count("SELECT COUNT(*) FROM scheduled_events WHERE status='running' AND (locked_at IS NULL OR locked_at<?)", [gmdate('c', $now - $leaseSeconds)]),
            'failedEventsSince' => $count("SELECT COUNT(*) FROM scheduled_events WHERE status='failed' AND processed_at>=?", [$sinceAt]),
            'failedEventsWithoutTimestamp' => $count("SELECT COUNT(*) FROM scheduled_events WHERE status='failed' AND processed_at IS NULL"),
            'failedMannyEvents' => $count("SELECT COUNT(*) FROM scheduled_events WHERE type='manny.task' AND status='failed'"),
            'activeMiningConflicts' => $count("SELECT COUNT(*) FROM scheduled_events WHERE type='manny.task' AND status IN ('pending','running') AND conflict_count>0"),
            'oldMiningConflicts' => $count("SELECT COUNT(*) FROM scheduled_events WHERE type='manny.task' AND status IN ('pending','running') AND conflict_count>0 AND conflict_started_at<=?", [$oldAt]),
            'pendingEffects' => $count("SELECT COUNT(*) FROM sector_effects WHERE status='pending'"),
            'pendingEffectsWithError' => $count("SELECT COUNT(*) FROM sector_effects WHERE status='pending' AND last_error IS NOT NULL"),
            'oldPendingEffects' => $count("SELECT COUNT(*) FROM sector_effects WHERE status='pending' AND created_at<=?", [$oldAt]),
            'activeAdmissions' => $count('SELECT COUNT(*) FROM probe_operations'),
            'expiredAdmissions' => $count('SELECT COUNT(*) FROM probe_operations WHERE expires_at<=?', [$now]),
            'closedProbeGates' => $count('SELECT COUNT(*) FROM probe_operation_gates WHERE closed=1'),
            'pendingProbeLosses' => $count("SELECT COUNT(*) FROM probe_losses WHERE state<>'completed'"),
            'oldPendingProbeLosses' => $count("SELECT COUNT(*) FROM probe_losses WHERE state<>'completed' AND updated_at<=?", [$oldAt]),
        ];
        $critical = array_values(array_filter(['invalidStocks', 'stocksWithoutIdentity', 'failedEventsSince'], static fn($key) => $counters[$key] > 0));
        $warnings = array_values(array_filter(['oldDuePendingEvents', 'expiredRunningEvents', 'failedMannyEvents', 'failedEventsWithoutTimestamp', 'oldMiningConflicts', 'pendingEffectsWithError', 'oldPendingEffects', 'expiredAdmissions', 'oldPendingProbeLosses'], static fn($key) => $counters[$key] > 0));
        return ['observedAt' => $timestamp, 'since' => $sinceAt, 'leaseSeconds' => $leaseSeconds, 'alertSeconds' => $alertSeconds,
            'status' => $critical !== [] ? 'critical' : ($warnings !== [] ? 'warning' : 'ok'),
            'critical' => $critical, 'warnings' => $warnings, 'counters' => $counters, 'events' => $events];
    }
}
