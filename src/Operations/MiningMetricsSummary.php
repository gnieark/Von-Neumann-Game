<?php
declare(strict_types=1);

namespace VonNeumannGame\Operations;

/** Exact nearest-rank percentiles over an explicitly selected log window. No raw payload output. */
final class MiningMetricsSummary
{
    /** @param resource $stream */
    public function read($stream, ?int $windowSeconds = null): array
    {
        if (!is_resource($stream) || ($windowSeconds !== null && $windowSeconds < 1)) {
            throw new \InvalidArgumentException('Journal ou fenêtre de mesure invalide.');
        }
        $counts = ['success' => 0, 'sql_conflict' => 0, 'permanent_failure' => 0];
        $samples = []; $codes = []; $families = [];
        $malformed = 0; $completed = 0; $recovered = 0; $alerts = 0;
        while (($line = fgets($stream, 1048577)) !== false) {
            if (!str_ends_with($line, "\n") && !feof($stream)) {
                throw new \RuntimeException('Ligne de journal trop longue ; sélectionner les messages scheduler.');
            }
            $start = strpos($line, '{');
            if ($start === false) { continue; }
            $record = json_decode(substr($line, $start), true);
            if (!is_array($record) || ($record['metric'] ?? null) !== 'scheduler_mining') {
                if (str_contains($line, 'scheduler_mining')) { $malformed++; }
                continue;
            }
            $outcome = $record['outcome'] ?? '';
            if (!is_string($outcome) || !isset($counts[$outcome])) { $malformed++; continue; }
            foreach (['duration_ms', 'admission_ms', 'transaction_ms'] as $field) {
                if (!array_key_exists($field, $record)) { continue; }
                if (!is_numeric($record[$field]) || !is_finite((float) $record[$field]) || (float) $record[$field] < 0) {
                    $malformed++;
                    continue 2;
                }
            }
            if (!isset($record['duration_ms'])) { $malformed++; continue; }
            if (array_sum($counts) >= 1000000) { throw new \RuntimeException('Plus d’un million de mesures ; réduire la fenêtre de journal.'); }
            $counts[$outcome]++;
            foreach (['duration_ms', 'admission_ms', 'transaction_ms'] as $field) {
                if (isset($record[$field])) { $samples[$outcome][$field][] = (float) $record[$field]; }
            }
            if ($outcome === 'success') {
                if (($record['completed'] ?? false) === true) { $completed++; }
                if ((int) ($record['recovered_conflicts'] ?? 0) > 0) { $recovered++; }
            }
            if ($outcome === 'sql_conflict') {
                $rawCode = $record['sql_code'] ?? null;
                $code = is_int($rawCode) || (is_string($rawCode) && ctype_digit($rawCode)) ? (string) (int) $rawCode : 'unknown';
                $family = in_array($record['lock_family'] ?? null, ['mining_transaction', 'mineable_stock', 'probe_admission', 'scheduler_event', 'manny_task', 'fuel_tank', 'storage_root', 'storage_capacity'], true)
                    ? $record['lock_family'] : 'unknown';
                $codes[$code] = ($codes[$code] ?? 0) + 1;
                $families[$family] = ($families[$family] ?? 0) + 1;
                if (($record['alert'] ?? false) === true) { $alerts++; }
            }
        }
        if (!feof($stream)) { throw new \RuntimeException('Lecture du journal interrompue.'); }
        $durations = [];
        foreach ($samples as $outcome => $fields) {
            foreach ($fields as $field => $values) {
                sort($values, SORT_NUMERIC);
                $n = count($values);
                $durations[$outcome][$field] = ['samples' => $n, 'p50' => $values[(int) ceil($n * .50) - 1],
                    'p95' => $values[(int) ceil($n * .95) - 1], 'p99' => $values[(int) ceil($n * .99) - 1], 'max' => $values[$n - 1]];
            }
        }
        ksort($codes); ksort($families);
        return ['records' => array_sum($counts), 'outcomes' => $counts, 'completedExecutions' => $completed,
            'successfulExecutionsAfterConflict' => $recovered, 'conflictAlerts' => $alerts, 'malformedRecords' => $malformed,
            'sqlCodes' => $codes, 'lockFamilies' => $families, 'durationsMs' => $durations,
            'windowSeconds' => $windowSeconds, 'completedExecutionsPerSecond' => $windowSeconds === null ? null : $completed / $windowSeconds];
    }
}
