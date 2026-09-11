<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use PDO;
use VonNeumannGame\Repository\ScheduledEventRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorService;

final class SectorEffectService
{
    public function __construct(private readonly PDO $pdo, private readonly ScheduledEventRepository $events, private readonly SectorService $sectors) {}

    public function enqueue(string $operation, SectorCoordinates $sector, string $type, string $objectId, array $payload, string $now): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Sector intentions must join the business transaction.'); }
        $stmt = $this->pdo->prepare('INSERT INTO sector_effects(operation_id,sector_x,sector_y,sector_z,effect_type,object_id,payload_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$operation, $sector->getX(), $sector->getY(), $sector->getZ(), $type, $objectId, json_encode($payload, JSON_THROW_ON_ERROR), $now, $now]);
        $this->events->schedule('sector.effect', 'sector_effect', (int) $this->pdo->lastInsertId(), $now);
    }

    public function apply(int $id): void
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Project only committed sector intentions.'); }
        $stmt = $this->pdo->prepare('SELECT * FROM sector_effects WHERE id=?');
        $stmt->execute([$id]);
        $effect = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$effect || $effect['status'] === 'applied') { return; }
        $this->pdo->prepare('UPDATE sector_effects SET attempts=attempts+1 WHERE id=?')->execute([$id]);
        try {
            $this->sectors->applySectorEffect(new SectorCoordinates((int) $effect['sector_x'], (int) $effect['sector_y'], (int) $effect['sector_z']), $effect['operation_id'], $effect['effect_type'], $effect['object_id'], json_decode($effect['payload_json'], true, 512, JSON_THROW_ON_ERROR));
            $this->pdo->prepare("UPDATE sector_effects SET status='applied',last_error=NULL,updated_at=? WHERE id=?")->execute([gmdate('c'), $id]);
        } catch (\Throwable $error) {
            $this->pdo->prepare('UPDATE sector_effects SET last_error=?,updated_at=? WHERE id=?')->execute([$error->getMessage(), gmdate('c'), $id]);
            throw $error;
        }
    }
}
