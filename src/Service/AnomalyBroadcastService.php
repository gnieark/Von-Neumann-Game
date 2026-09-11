<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use PDO;
use VonNeumannGame\Database\StorageTransaction;
use VonNeumannGame\Repository\ScheduledEventRepository;

final class AnomalyBroadcastService
{
    public const BATCH_SIZE = 100; // Under SQLite's conservative 999-parameter bound.

    public function __construct(private readonly PDO $pdo, private readonly ScheduledEventRepository $events) {}

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
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Broadcast must join the opening transaction.'); }
        $recipients=$this->pdo->query('SELECT (SELECT COUNT(*) FROM neumann_probes) AS probe_count,(SELECT COALESCE(MAX(id),0) FROM neumann_probes) AS probe_max,(SELECT COUNT(*) FROM others_ships) AS ship_count,(SELECT COALESCE(MAX(id),0) FROM others_ships) AS ship_max')->fetch(PDO::FETCH_ASSOC);
        $query = $this->pdo->prepare('INSERT INTO anomaly_broadcasts(public_id,depot_id,sector_x,sector_y,sector_z,opened_at,probe_high_watermark,ship_high_watermark) VALUES(?,?,?,?,?,?,?,?)');
        $query->execute(['wave_' . bin2hex(random_bytes(12)), $depot['id'], $depot['sector_x'], $depot['sector_y'], $depot['sector_z'], $now,$recipients['probe_max'],$recipients['ship_max']]);
        $id=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO anomaly_broadcast_recipient_counts(broadcast_id,recipient_kind,expected_count) VALUES(?,?,?),(?,?,?)')->execute([$id,'probe',(int)$recipients['probe_count'],$id,'ship',(int)$recipients['ship_count']]);
        $this->events->schedule('anomaly.broadcast', 'anomaly_broadcast', $id, $now);
    }

    public function deliverPage(int $id): void
    {
        $transaction = new StorageTransaction($this->pdo);
        $transaction->run(function () use ($id, $transaction): void {
            $broadcast = $transaction->lock('broadcast', $id);
            if ($broadcast === null || $broadcast['status'] === 'done') { return; }
            foreach (['probe', 'ship'] as $kind) {
                $cursor = (int) $broadcast[$kind . '_cursor'];
                $maximum = (int) $broadcast[$kind . '_high_watermark'];
                if ($cursor >= $maximum) { continue; }
                $sql = $kind === 'probe'
                    ? 'SELECT id,player_id,sector_x,sector_y,sector_z FROM neumann_probes WHERE id>? AND id<=? ORDER BY id'
                    : 'SELECT s.id,s.public_id,f.player_id,s.sector_x,s.sector_y,s.sector_z FROM others_ships s JOIN others_fleets f ON f.id=s.fleet_id WHERE s.id>? AND s.id<=? ORDER BY s.id';
                $query = $this->pdo->prepare($sql . ' LIMIT ' . self::BATCH_SIZE);
                $query->execute([$cursor, $maximum]);
                $rows = $query->fetchAll(PDO::FETCH_ASSOC);
                $deliveries = []; $alerts = [];
                foreach ($rows as $row) {
                    $message = self::message($broadcast, $row);
                    $deliveries[] = [$id, $kind, $row['id'], $row['player_id'], $row['sector_x'], $row['sector_y'], $row['sector_z'], $message];
                    // Public alert location is the recipient's own sector, never the emission's sector.
                    $alerts[] = $kind === 'probe'
                        ? [$row['id'], $broadcast['opened_at'], $row['sector_x'], $row['sector_y'], $row['sector_z'], $message, $broadcast['opened_at'], $broadcast['opened_at']]
                        : ['oalert_' . bin2hex(random_bytes(12)), $row['player_id'], $row['public_id'], $broadcast['public_id'] . ':' . $row['id'], $message, $broadcast['opened_at'], $broadcast['opened_at']];
                }
                $this->insertRows('INSERT INTO anomaly_broadcast_deliveries(broadcast_id,recipient_kind,recipient_id,player_id,sector_x,sector_y,sector_z,message) VALUES ', '(?,?,?,?,?,?,?,?)', $deliveries);
                if ($kind === 'probe') {
                    $this->insertRows("INSERT INTO probe_damage_warnings(probe_id,movement_id,type,status,phase,scheduled_at,sector_x,sector_y,sector_z,container_id,container_label,object_id,risk_percent,additional_container_count,message,created_at,updated_at) VALUES ", "(?,NULL,'anomaly_detected','unread','detection',?,?,?,?,'','','',0,0,?,?,?)", $alerts);
                } else {
                    $this->insertRows('INSERT INTO others_alerts(public_id,player_id,ship_public_id,type,status,phase,event_key,message,created_at,updated_at) VALUES ', "(?,?,?,'anomaly_detected','unread','detection',?,?,?,?)", $alerts);
                }
                $next = count($rows) < self::BATCH_SIZE ? $maximum : (int) end($rows)['id'];
                $this->pdo->prepare('UPDATE anomaly_broadcasts SET ' . $kind . '_cursor=? WHERE id=?')->execute([$next, $id]);
                $broadcast[$kind . '_cursor'] = $next;
            }
            if ((int) $broadcast['probe_cursor'] >= (int) $broadcast['probe_high_watermark'] && (int) $broadcast['ship_cursor'] >= (int) $broadcast['ship_high_watermark']) {
                $query=$this->pdo->prepare('SELECT recipient_kind,expected_count FROM anomaly_broadcast_recipient_counts WHERE broadcast_id=?');
                $query->execute([$id]);$expected=$query->fetchAll(PDO::FETCH_KEY_PAIR);
                if(count($expected)!==2){throw new \RuntimeException('Broadcast recipient snapshot is missing.');}
                $query=$this->pdo->prepare('SELECT recipient_kind,COUNT(*) AS delivered FROM anomaly_broadcast_deliveries WHERE broadcast_id=? GROUP BY recipient_kind');
                $query->execute([$id]);$delivered=$query->fetchAll(PDO::FETCH_KEY_PAIR);$missing=[];
                foreach(['probe','ship'] as $kind){$count=(int)$expected[$kind]-(int)($delivered[$kind]??0);if($count>0){$missing[$kind]=$count;}}
                if($missing!==[]){(new \VonNeumannGame\Repository\OthersAuditRepository($this->pdo))->record(null,'scheduler','anomaly.broadcast','recipients_missing',$broadcast['public_id'],['broadcastId'=>$id,'missing'=>$missing]);}
                $this->pdo->prepare("UPDATE anomaly_broadcasts SET status='done' WHERE id=?")->execute([$id]);
            } else { $this->events->schedule('anomaly.broadcast', 'anomaly_broadcast', $id, gmdate('c')); }
        });
    }

    private function insertRows(string $prefix, string $values, array $rows): void
    {
        if ($rows === []) { return; }
        $this->pdo->prepare($prefix . implode(',', array_fill(0, count($rows), $values)))->execute(array_merge(...$rows));
    }
}
