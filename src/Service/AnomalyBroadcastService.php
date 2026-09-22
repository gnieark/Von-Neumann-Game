<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Repository\OthersAuditRepository;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Repository\Storage\AnomalyBroadcastRepository;
use VonNeumannGame\Repository\Storage\StorageLockRepository;

final class AnomalyBroadcastService
{
    public const BATCH_SIZE = 100; // Under SQLite's conservative 999-parameter bound.

    public function __construct(
        private readonly AnomalyBroadcastRepository $broadcasts,
        private readonly StorageTransaction $transaction,
        private readonly StorageLockRepository $locks,
        private readonly ScheduledEventRepository $events,
        private readonly OthersAuditRepository $audit,
    ) {}

    public static function message(array $source, array $recipient): string
    {
        $v = [];
        foreach (['x', 'y', 'z'] as $axis) { $v[$axis] = (float) $source['sector_' . $axis] - (float) $recipient['sector_' . $axis]; }
        $maximum = max(array_map('abs', $v));
        if ($maximum == 0) { $origin = 'de votre secteur'; }
        else {
            $direction = array_map(static fn(float $value): int => (int) round(50 * $value / $maximum), $v);
            $origin = 'de la direction approximative (' . implode(', ', $direction) . ')';
        }
        return 'Vos capteurs ont détecté une onde provenant ' . $origin . ". Son signal n'a pas pu être interprété.";
    }

    public function enqueue(array $depot, string $now): void
    {
        if (!$this->broadcasts->inTransaction()) { throw new \LogicException('Broadcast must join the opening transaction.'); }
        $id = $this->broadcasts->create($depot, $now);
        $this->events->schedule('anomaly.broadcast', 'anomaly_broadcast', $id, $now);
    }

    public function deliverPage(int $id): void
    {
        $this->transaction->run(function () use ($id): void {
            $broadcast = $this->locks->lock('broadcast', $id);
            if ($broadcast === null || $broadcast['status'] === 'done') { return; }
            foreach (['probe', 'ship'] as $kind) {
                $cursor = (int) $broadcast[$kind . '_cursor'];
                $maximum = (int) $broadcast[$kind . '_high_watermark'];
                if ($cursor >= $maximum) { continue; }
                $rows = $this->broadcasts->recipients($kind, $cursor, $maximum, self::BATCH_SIZE);
                $deliveries = []; $alerts = [];
                foreach ($rows as $row) {
                    $message = self::message($broadcast, $row);
                    $deliveries[] = [$id, $kind, $row['id'], $row['player_id'], $row['sector_x'], $row['sector_y'], $row['sector_z'], $message];
                    // Public alert location is the recipient's own sector, never the emission's sector.
                    $alerts[] = $kind === 'probe'
                        ? [$row['id'], $broadcast['opened_at'], $row['sector_x'], $row['sector_y'], $row['sector_z'], $message, $broadcast['opened_at'], $broadcast['opened_at']]
                        : ['oalert_' . bin2hex(random_bytes(12)), $row['player_id'], $row['public_id'], $broadcast['public_id'] . ':' . $row['id'], $message, $broadcast['opened_at'], $broadcast['opened_at']];
                }
                $this->broadcasts->deliver($id, $kind, $deliveries, $alerts);
                $next = count($rows) < self::BATCH_SIZE ? $maximum : (int) end($rows)['id'];
                $this->broadcasts->advance($id, $kind, $next);
                $broadcast[$kind . '_cursor'] = $next;
            }
            if ((int) $broadcast['probe_cursor'] >= (int) $broadcast['probe_high_watermark'] && (int) $broadcast['ship_cursor'] >= (int) $broadcast['ship_high_watermark']) {
                $missing = $this->broadcasts->missingRecipients($id);
                if ($missing !== []) { $this->audit->record(null, 'scheduler', 'anomaly.broadcast', 'recipients_missing', $broadcast['public_id'], ['broadcastId' => $id, 'missing' => $missing]); }
                $this->broadcasts->finish($id);
            } else { $this->events->schedule('anomaly.broadcast', 'anomaly_broadcast', $id, gmdate('c')); }
        });
    }

}
