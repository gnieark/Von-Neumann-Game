<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use PDO;
use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeStatus;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Repository\ProbeDamageWarningRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;

final class ProbeReinstantiationService
{
    public const TERMINAL_REASON_BLACK_HOLE = 'black_hole_trap';
    public const TERMINAL_REASON_COLLISION = 'movement_collision';

    private readonly SectorGrid $grid;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PlayerRepository $players,
        private readonly NeumannProbeRepository $probes,
        private readonly MannyRepository $mannies,
        private readonly VisitedSectorRepository $visitedSectors,
        private readonly ?SectorService $sectors = null,
        private readonly ?ProbeDamageWarningRepository $damageWarnings = null,
        ?SectorGrid $grid = null,
        private readonly array $gameplayConfig = [],
        private readonly array $universeConfig = [],
    ) {
        $this->grid = $grid ?? new SectorGrid();
    }

    /**
     * @return array{previousProbeId:int, player:Player, probe:NeumannProbe}
     */
    public function reassignMindSnapshot(Player $player, NeumannProbe $terminalProbe): array
    {
        if ($terminalProbe->playerId !== $player->id) {
            throw new ProbeReinstantiationException('This probe does not belong to the authenticated player.', 403, 'forbidden');
        }

        if (!in_array($terminalProbe->status, [ProbeStatus::Dead, ProbeStatus::TrappedByBlackHole], true)) {
            throw new ProbeReinstantiationException('Mind snapshot reassignment is only available after probe destruction or black-hole entrapment.');
        }

        $newHome = $this->preparedHomeSector();
        $detachedMannies = array_values(array_filter(
            $this->mannies->findByProbeId($terminalProbe->id),
            static fn(Manny $manny): bool => !$manny->isOnProbe() && $manny->sector !== null,
        ));

        $this->pdo->beginTransaction();
        try {
            $this->abandonDetachedMannies($detachedMannies, 'Manny abandoned after its probe was destroyed.');
            $this->deleteProbeData($terminalProbe->id);

            $player->homeSector = $newHome;
            $this->players->save($player);
            $this->deleteVisitedSectors($player->id);

            $newProbe = $this->probes->createForPlayer($player->id, 'Probe of ' . $player->username, $newHome);
            $this->mannies->ensureDefaultsForProbe($newProbe);
            $this->visitedSectors->markVisited($player, $newProbe, $newHome);

            $updatedPlayer = $this->players->findById($player->id) ?? $player;
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return [
            'previousProbeId' => $terminalProbe->id,
            'player' => $updatedPlayer,
            'probe' => $newProbe,
        ];
    }

    public function switchDefaultProbeAfterTerminalLoss(NeumannProbe $terminalProbe, string $reason): ?NeumannProbe
    {
        return $this->handleTerminalProbeLoss($terminalProbe, $reason);
    }

    public function handleTerminalProbeLoss(NeumannProbe $terminalProbe, string $reason): ?NeumannProbe
    {
        if (!in_array($terminalProbe->status, [ProbeStatus::Dead, ProbeStatus::TrappedByBlackHole], true)) {
            throw new ProbeReinstantiationException('Probe terminal-loss cleanup is only available after probe destruction or black-hole entrapment.');
        }

        $player = $this->players->findById($terminalProbe->playerId);
        if ($player === null) {
            return null;
        }

        $survivors = $this->replacementProbesFor($player, $terminalProbe);
        if ($survivors === []) {
            return null;
        }

        $terminalWasDefault = $player->defaultProbeId === $terminalProbe->id;
        $replacement = $this->nearestReplacementProbe($survivors, $terminalProbe);
        $alertProbe = $terminalWasDefault ? $replacement : $this->currentDefaultProbe($player, $terminalProbe, $survivors);
        $detachedMannies = $this->detachedManniesForProbe($terminalProbe->id);

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($terminalWasDefault || $player->defaultProbeId === null || $alertProbe->id !== $player->defaultProbeId) {
                $player->defaultProbeId = $alertProbe->id;
                $this->players->save($player);
            }

            if ($terminalWasDefault) {
                $this->damageWarnings?->createMindSnapshotTransferredAlert(
                    $alertProbe->id,
                    $alertProbe->currentSector,
                    $terminalProbe->id,
                    $reason,
                    $this->mindSnapshotTransferMessage($reason),
                );
            } else {
                $this->damageWarnings?->createProbeDestroyedAlert(
                    $alertProbe->id,
                    $alertProbe->currentSector,
                    $terminalProbe->id,
                    $reason,
                    $this->probeDestroyedMessage($player, $terminalProbe, $reason),
                );
            }

            $this->abandonDetachedMannies($detachedMannies, 'Manny abandoned after its probe was destroyed.');
            $this->deleteProbeData($terminalProbe->id);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $this->probes->findById($alertProbe->id) ?? $alertProbe;
    }

    /**
     * @return array<NeumannProbe>
     */
    private function replacementProbesFor(Player $player, NeumannProbe $terminalProbe): array
    {
        $replacements = [];
        foreach ($this->probes->findAllByPlayerId($player->id) as $probe) {
            if ($probe->id === $terminalProbe->id) {
                continue;
            }
            if (in_array($probe->status, [ProbeStatus::Dead, ProbeStatus::TrappedByBlackHole], true)) {
                continue;
            }

            $replacements[] = $probe;
        }

        return $replacements;
    }

    /**
     * @param array<NeumannProbe> $survivors
     */
    private function nearestReplacementProbe(array $survivors, NeumannProbe $terminalProbe): NeumannProbe
    {
        usort(
            $survivors,
            fn(NeumannProbe $left, NeumannProbe $right): int => [
                $this->grid->getDistance($left->currentSector, $terminalProbe->currentSector),
                $left->id,
            ] <=> [
                $this->grid->getDistance($right->currentSector, $terminalProbe->currentSector),
                $right->id,
            ],
        );

        return $survivors[0] ?? throw new \RuntimeException('No replacement probe available.');
    }

    /**
     * @param array<NeumannProbe> $survivors
     */
    private function currentDefaultProbe(Player $player, NeumannProbe $terminalProbe, array $survivors): NeumannProbe
    {
        foreach ($survivors as $probe) {
            if ($probe->id === $player->defaultProbeId) {
                return $probe;
            }
        }

        return $this->nearestReplacementProbe($survivors, $terminalProbe);
    }

    private function mindSnapshotTransferMessage(string $reason): string
    {
        return match ($reason) {
            self::TERMINAL_REASON_BLACK_HOLE => 'Instance switch complete: the last stable backup of your mind has been transferred into this probe after your default probe was trapped beyond a black-hole escape threshold.',
            self::TERMINAL_REASON_COLLISION => 'Instance switch complete: the last stable backup of your mind has been transferred into this probe after your default probe was destroyed by a high-velocity collision during intersector movement.',
            default => 'Instance switch complete: the last stable backup of your mind has been transferred into this probe after your default probe was lost.',
        };
    }

    private function probeDestroyedMessage(Player $player, NeumannProbe $terminalProbe, string $reason): string
    {
        $cause = match ($reason) {
            self::TERMINAL_REASON_BLACK_HOLE => 'black-hole entrapment beyond the escape threshold',
            self::TERMINAL_REASON_COLLISION => 'a high-velocity collision during intersector movement',
            default => 'an unrecoverable terminal event',
        };
        $movement = $this->latestMovementAttemptForProbe($terminalProbe->id);
        $movementSummary = $movement !== null
            ? ' Attempted movement: from relative sector '
                . $this->relativeSectorKey($movement['origin'], $player)
                . ' to relative sector '
                . $this->relativeSectorKey($movement['target'], $player)
                . '.'
            : '';

        return "Probe lost: {$terminalProbe->name} (#{$terminalProbe->id}) was destroyed by {$cause}. The destroyed probe has been removed from your fleet.{$movementSummary}";
    }

    /**
     * @return array{origin:SectorCoordinates,target:SectorCoordinates}|null
     */
    private function latestMovementAttemptForProbe(int $probeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT origin_x, origin_y, origin_z, target_x, target_y, target_z
             FROM probe_movements
             WHERE probe_id = :probe_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['probe_id' => $probeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'origin' => new SectorCoordinates((int) $row['origin_x'], (int) $row['origin_y'], (int) $row['origin_z']),
            'target' => new SectorCoordinates((int) $row['target_x'], (int) $row['target_y'], (int) $row['target_z']),
        ];
    }

    private function relativeSectorKey(SectorCoordinates $sector, Player $player): string
    {
        $relative = $sector->subtract($player->homeSector);

        return $relative['x'] . ':' . $relative['y'] . ':' . $relative['z'];
    }

    private function deleteProbeData(int $probeId): void
    {
        $this->execute('DELETE FROM visited_sectors WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute(
            'DELETE FROM scheduled_events
             WHERE entity_type = :entity_type
             AND entity_id IN (SELECT id FROM probe_movements WHERE probe_id = :probe_id)',
            ['entity_type' => 'probe_movement', 'probe_id' => $probeId],
        );
        $this->execute(
            'DELETE FROM scheduled_events WHERE entity_type = :entity_type AND entity_id = :probe_id',
            ['entity_type' => 'probe', 'probe_id' => $probeId],
        );
        $this->execute(
            'DELETE FROM scheduled_events
             WHERE entity_type = :entity_type
             AND entity_id IN (SELECT id FROM probe_damage_warnings WHERE probe_id = :probe_id)',
            ['entity_type' => 'probe_damage_warning', 'probe_id' => $probeId],
        );
        $this->execute(
            'DELETE FROM scheduled_events
             WHERE entity_type = :entity_type
             AND entity_id IN (SELECT id FROM mannies WHERE probe_id = :probe_id)',
            ['entity_type' => 'manny', 'probe_id' => $probeId],
        );
        $this->execute('DELETE FROM probe_damage_warnings WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM probe_movements WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM mannies WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('UPDATE probe_messages SET sender_probe_id = NULL WHERE sender_probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('UPDATE probe_messages SET recipient_probe_id = NULL WHERE recipient_probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM probe_items WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute(
            'DELETE FROM storage_container_resources
             WHERE container_id IN (SELECT id FROM storage_containers WHERE probe_id = :probe_id)',
            ['probe_id' => $probeId],
        );
        $this->execute('DELETE FROM storage_containers WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM probe_improvement_installations WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM neumann_probes WHERE id = :probe_id', ['probe_id' => $probeId]);
    }

    /**
     * @return array<Manny>
     */
    private function detachedManniesForProbe(int $probeId): array
    {
        return array_values(array_filter(
            $this->mannies->findByProbeId($probeId),
            static fn(Manny $manny): bool => !$manny->isOnProbe() && $manny->sector !== null,
        ));
    }

    /**
     * @param array<Manny> $mannies
     */
    private function abandonDetachedMannies(array $mannies, string $description): void
    {
        foreach ($mannies as $manny) {
            $this->registerAbandonedManny($manny, $description);
            $this->detachManny($manny);
        }
    }

    private function registerAbandonedManny(Manny $manny, string $description): void
    {
        if ($this->sectors === null || $manny->sector === null) {
            return;
        }

        $sector = $this->sectors->getOrCreateSector($manny->sector);
        $object = new SectorManny(
            SectorManny::objectIdForUid($manny->uid),
            $manny->name,
            $manny->uid,
            SectorManny::STATE_ABANDONED,
            $manny->cargoArray(),
            $description,
        );

        if (!$sector->replaceObject($object)) {
            $sector->addObject($object);
        }
        $this->sectors->saveSector($sector);
    }

    private function detachManny(Manny $manny): void
    {
        $this->execute(
            'DELETE FROM scheduled_events WHERE entity_type = :entity_type AND entity_id = :manny_id',
            ['entity_type' => 'manny', 'manny_id' => $manny->id],
        );
        $this->execute(
            'UPDATE mannies
             SET probe_id = NULL,
                 storage_container_id = NULL,
                 location_type = :location_type,
                 current_task = NULL,
                 task_started_at = NULL,
                 task_ends_at = NULL,
                 task_scheduled_event_id = NULL,
                 task_payload_json = :task_payload_json,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $manny->id,
                'location_type' => Manny::LOCATION_SECTOR,
                'task_payload_json' => '{}',
                'updated_at' => gmdate('c'),
            ],
        );
    }

    private function deleteVisitedSectors(int $playerId): void
    {
        $this->execute('DELETE FROM visited_sectors WHERE player_id = :player_id', ['player_id' => $playerId]);
    }

    private function preparedHomeSector(): SectorCoordinates
    {
        $home = $this->randomHomeSector();
        if ($this->sectors === null) {
            return $home;
        }

        $neighbors = $this->grid->getNeighbors($home);
        $existedBefore = $this->sectorExistenceMap([$home, ...$neighbors]);
        $sector = $this->getOrCreateStarterSector($home, $existedBefore[$home->toKey()] ?? false);

        if (!$sector->hasBlackHole()) {
            return $home;
        }

        foreach ($neighbors as $neighbor) {
            $neighborSector = $this->getOrCreateStarterSector($neighbor, $existedBefore[$neighbor->toKey()] ?? false);
            if (!$neighborSector->hasBlackHole()) {
                return $neighbor;
            }
        }

        throw new \RuntimeException('Unable to place reassigned mind snapshot outside a neighboring black hole sector.');
    }

    private function randomHomeSector(): SectorCoordinates
    {
        $min = Config::int($this->gameplayConfig, 'player.spawnCoordinateMin', -1000);
        $max = Config::int($this->gameplayConfig, 'player.spawnCoordinateMax', 1000);
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        do {
            $x = random_int($min, $max);
            $y = random_int($min, $max);
            $z = random_int($min, $max);
        } while (($x + $y + $z) % 2 !== 0);

        return new SectorCoordinates($x, $y, $z);
    }

    /**
     * @param array<SectorCoordinates> $coordinates
     * @return array<string, bool>
     */
    private function sectorExistenceMap(array $coordinates): array
    {
        $map = [];
        foreach ($coordinates as $coordinate) {
            $map[$coordinate->toKey()] = $this->sectors?->sectorExists($coordinate) ?? false;
        }

        return $map;
    }

    private function getOrCreateStarterSector(SectorCoordinates $coordinates, bool $existedBefore): SectorContent
    {
        if ($this->sectors === null) {
            throw new \RuntimeException('Sector service is not configured.');
        }

        $sector = $this->sectors->getOrCreateSector($coordinates);
        if (!$existedBefore && $sector->getObjects() === [] && Config::bool($this->gameplayConfig, 'player.starterAsteroid.enabledForNewEmptySectors', true)) {
            $sector->addObject($this->starterIronAsteroid($coordinates));
            $this->sectors->saveSector($sector);
        }

        return $sector;
    }

    private function starterIronAsteroid(SectorCoordinates $coordinates): Asteroid
    {
        $prefix = (string) Config::value($this->gameplayConfig, 'player.starterAsteroid.idPrefix', 'starter-iron');
        $composition = (string) Config::value($this->gameplayConfig, 'player.starterAsteroid.composition', 'iron');
        $resources = Config::getArray($this->gameplayConfig, 'player.starterAsteroid.estimatedResources', ['iron', 'nickel']);
        $sizeCategory = (string) Config::value($this->gameplayConfig, 'player.starterAsteroid.sizeCategory', 'small');
        $mass = Config::float($this->gameplayConfig, 'player.starterAsteroid.mass', 0.00005);
        $radius = Config::float($this->gameplayConfig, 'player.starterAsteroid.radius', 0.012);
        $description = (string) Config::value($this->gameplayConfig, 'player.starterAsteroid.description', 'Metallic asteroid seeded in a newly reactivated probe sector.');

        $objectId = $prefix . '-' . substr(hash('sha256', $coordinates->toKey()), 0, 16);
        $asteroid = new Asteroid(
            $objectId,
            null,
            $composition,
            $resources,
            $sizeCategory,
            $mass,
            $radius,
            $description,
            resourceContainersPerEarthMass: Config::float($this->universeConfig, 'resourceContainersPerEarthMass', 1000000.0),
        );

        return $asteroid->withGeneratedName('starter:' . $coordinates->toKey() . ':' . $objectId);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function execute(string $sql, array $params): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
