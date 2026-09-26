<?php

declare(strict_types=1);

namespace VonNeumannGame\Repository\Storage;

use PDO;

final class AnomalyBroadcastRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function inTransaction(): bool { return $this->pdo->inTransaction(); }

    public function create(array $depot, string $now): int
    {
        $recipients = $this->pdo->query('SELECT (SELECT COUNT(*) FROM neumann_probes) AS probe_count,(SELECT COALESCE(MAX(id),0) FROM neumann_probes) AS probe_max,(SELECT COUNT(*) FROM others_ships) AS ship_count,(SELECT COALESCE(MAX(id),0) FROM others_ships) AS ship_max')->fetch(PDO::FETCH_ASSOC);
        $query = $this->pdo->prepare('INSERT INTO anomaly_broadcasts(public_id,depot_id,sector_x,sector_y,sector_z,opened_at,probe_high_watermark,ship_high_watermark) VALUES(?,?,?,?,?,?,?,?)');
        $query->execute(['wave_' . bin2hex(random_bytes(12)), $depot['id'], $depot['sector_x'], $depot['sector_y'], $depot['sector_z'], $now, $recipients['probe_max'], $recipients['ship_max']]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO anomaly_broadcast_recipient_counts(broadcast_id,recipient_kind,expected_count) VALUES(?,?,?),(?,?,?)')->execute([$id, 'probe', (int) $recipients['probe_count'], $id, 'ship', (int) $recipients['ship_count']]);
        return $id;
    }

    public function recipients(string $kind, int $cursor, int $maximum, int $limit): array
    {
        $sql = match ($kind) {
            'probe' => 'SELECT id,player_id,sector_x,sector_y,sector_z FROM neumann_probes WHERE id>? AND id<=? ORDER BY id',
            'ship' => 'SELECT s.id,s.public_id,f.player_id,s.sector_x,s.sector_y,s.sector_z FROM others_ships s JOIN others_fleets f ON f.id=s.fleet_id WHERE s.id>? AND s.id<=? ORDER BY s.id',
            default => throw new \InvalidArgumentException('Unknown broadcast recipient kind.'),
        };
        $query = $this->pdo->prepare($sql . ' LIMIT ' . $limit);
        $query->execute([$cursor, $maximum]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deliver(int $id, string $kind, array $deliveries, array $alerts): void
    {
        $this->insertRows('INSERT INTO anomaly_broadcast_deliveries(broadcast_id,recipient_kind,recipient_id,player_id,sector_x,sector_y,sector_z,message) VALUES ', '(?,?,?,?,?,?,?,?)', $deliveries);
        if ($kind === 'probe') {
            $this->insertRows("INSERT INTO probe_damage_warnings(probe_id,movement_id,type,status,phase,scheduled_at,sector_x,sector_y,sector_z,container_id,container_label,object_id,risk_percent,additional_container_count,message,created_at,updated_at) VALUES ", "(?,NULL,'anomaly_detected','unread','detection',?,?,?,?,'','','',0,0,?,?,?)", $alerts);
        } else {
            $this->insertRows('INSERT INTO others_alerts(public_id,player_id,ship_public_id,type,status,phase,event_key,message,created_at,updated_at) VALUES ', "(?,?,?,'anomaly_detected','unread','detection',?,?,?,?)", $alerts);
        }
    }

    public function advance(int $id, string $kind, int $cursor): void
    {
        if (!in_array($kind, ['probe', 'ship'], true)) { throw new \InvalidArgumentException('Unknown broadcast cursor.'); }
        $this->pdo->prepare('UPDATE anomaly_broadcasts SET ' . $kind . '_cursor=? WHERE id=?')->execute([$cursor, $id]);
    }

    public function missingRecipients(int $id): array
    {
        $query = $this->pdo->prepare('SELECT recipient_kind,expected_count FROM anomaly_broadcast_recipient_counts WHERE broadcast_id=?');
        $query->execute([$id]);
        $expected = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($expected) !== 2) { throw new \RuntimeException('Broadcast recipient snapshot is missing.'); }
        $query = $this->pdo->prepare('SELECT recipient_kind,COUNT(*) AS delivered FROM anomaly_broadcast_deliveries WHERE broadcast_id=? GROUP BY recipient_kind');
        $query->execute([$id]);
        $delivered = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        $missing = [];
        foreach (['probe', 'ship'] as $kind) {
            $count = (int) $expected[$kind] - (int) ($delivered[$kind] ?? 0);
            if ($count > 0) { $missing[$kind] = $count; }
        }
        return $missing;
    }

    public function finish(int $id): void
    {
        $this->pdo->prepare("UPDATE anomaly_broadcasts SET status='done' WHERE id=? AND status='pending'")->execute([$id]);
    }

    private function insertRows(string $prefix, string $values, array $rows): void
    {
        if ($rows === []) { return; }
        $this->pdo->prepare($prefix . implode(',', array_fill(0, count($rows), $values)))->execute(array_merge(...$rows));
    }
}
