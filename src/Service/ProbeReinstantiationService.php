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
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;

final class ProbeReinstantiationService
{
    private readonly SectorGrid $grid;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PlayerRepository $players,
        private readonly NeumannProbeRepository $probes,
        private readonly MannyRepository $mannies,
        private readonly VisitedSectorRepository $visitedSectors,
        private readonly ?SectorService $sectors = null,
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

        $this->removeDetachedMannyObjects($detachedMannies);

        $this->pdo->beginTransaction();
        try {
            $this->deleteProbeData($terminalProbe->id);

            $player->homeSector = $newHome;
            $this->players->save($player);
            $this->deleteVisitedSectors($player->id);

            $newProbe = $this->probes->createForPlayer($player->id, 'Probe of ' . $player->username, $newHome);
            $this->mannies->ensureDefaultsForProbe($newProbe);
            $this->visitedSectors->markVisited($player, $newHome);

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

    /**
     * @param array<Manny> $mannies
     */
    private function removeDetachedMannyObjects(array $mannies): void
    {
        if ($this->sectors === null) {
            return;
        }

        foreach ($mannies as $manny) {
            if ($manny->sector === null) {
                continue;
            }

            $sector = $this->sectors->getOrCreateSector($manny->sector);
            if ($sector->removeObjectById(SectorManny::objectIdForUid($manny->uid))) {
                $this->sectors->saveSector($sector);
            }
        }
    }

    private function deleteProbeData(int $probeId): void
    {
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
        $this->execute('DELETE FROM probe_movements WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM probe_damage_warnings WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM mannies WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute(
            'DELETE FROM probe_messages WHERE sender_probe_id = :probe_id OR recipient_probe_id = :probe_id',
            ['probe_id' => $probeId],
        );
        $this->execute('DELETE FROM probe_items WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute(
            'DELETE FROM storage_container_resources
             WHERE container_id IN (SELECT id FROM storage_containers WHERE probe_id = :probe_id)',
            ['probe_id' => $probeId],
        );
        $this->execute('DELETE FROM storage_containers WHERE probe_id = :probe_id', ['probe_id' => $probeId]);
        $this->execute('DELETE FROM neumann_probes WHERE id = :probe_id', ['probe_id' => $probeId]);
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

        return new Asteroid(
            $prefix . '-' . substr(hash('sha256', $coordinates->toKey()), 0, 16),
            null,
            $composition,
            $resources,
            $sizeCategory,
            $mass,
            $radius,
            $description,
            resourceContainersPerEarthMass: Config::float($this->universeConfig, 'resourceContainersPerEarthMass', 1000000.0),
        );
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
