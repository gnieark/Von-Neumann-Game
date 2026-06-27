<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ScutNetwork;
use VonNeumannGame\Domain\ScutRelay;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ScutNetworkRepository;
use VonNeumannGame\Repository\ScutRelayRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;

final class ScutNetworkService
{
    private readonly SectorGrid $grid;

    public function __construct(
        private readonly ScutRelayRepository $relays,
        private readonly ScutNetworkRepository $networks,
        private readonly NeumannProbeRepository $probes,
        ?SectorGrid $grid = null,
    ) {
        $this->grid = $grid ?? new SectorGrid();
    }

    public function createOffRelay(SectorCoordinates $sector, ?int $createdByProbeId = null): ScutRelay
    {
        return $this->relays->create($createdByProbeId, $sector);
    }

    public function relayById(int $id): ?ScutRelay
    {
        return $this->relays->findById($id);
    }

    /**
     * @return array<ScutRelay>
     */
    public function relaysInSector(SectorCoordinates $sector): array
    {
        return $this->relays->findBySector($sector);
    }

    public function turnOnRelay(int $relayId, ?string $requestedNetworkName = null): ScutRelay
    {
        $relay = $this->relays->findById($relayId)
            ?? throw new MannyActionException(404, 'scut_relay_not_found', 'SCUT relay not found.');
        if ($relay->isOn()) {
            throw new MannyActionException(409, 'scut_relay_already_on', 'SCUT relay is already on.');
        }

        $now = gmdate('c');
        $relay->status = ScutRelay::STATUS_ON;
        $relay->activatedAt = $now;
        $relay->coveredSectors = $this->coverageFor($relay->sector);

        $candidateRelays = $this->relays->findOnRelaysCoveringSector($relay->sector);
        $candidateNetworkIds = array_values(array_unique(array_filter(
            array_map(static fn(ScutRelay $candidate): ?int => $candidate->networkId, $candidateRelays),
            static fn(?int $id): bool => $id !== null,
        )));

        if ($candidateNetworkIds === []) {
            $networkName = trim((string) $requestedNetworkName);
            if ($networkName === '') {
                $networkName = 'scut network';
            }
            $network = $this->networks->create($networkName, $relay->coveredSectors, $relay->activatedAt);
            $relay->networkId = $network->id;
            $this->relays->save($relay);

            return $this->relays->findById($relay->id) ?? $relay;
        }

        $network = $this->mergeIntoDominantNetwork($candidateNetworkIds, $relay);
        $relay->networkId = $network->id;
        $this->relays->save($relay);
        $this->recalculateNetwork($network->id);

        return $this->relays->findById($relay->id) ?? $relay;
    }

    /**
     * @return array<ScutNetwork>
     */
    public function networksCoveringSector(SectorCoordinates $sector): array
    {
        $networkIds = array_values(array_unique(array_filter(
            array_map(
                static fn(ScutRelay $relay): ?int => $relay->networkId,
                $this->relays->findOnRelaysCoveringSector($sector),
            ),
            static fn(?int $id): bool => $id !== null,
        )));

        return $this->networks->findByIds($networkIds);
    }

    public function networkCoversSector(int $networkId, SectorCoordinates $sector): bool
    {
        foreach ($this->relays->findOnRelaysCoveringSector($sector) as $relay) {
            if ($relay->networkId === $networkId) {
                return true;
            }
        }

        return false;
    }

    public function canSectorsCommunicate(SectorCoordinates $a, SectorCoordinates $b): bool
    {
        if ($a->equals($b)) {
            return true;
        }

        $aNetworks = array_fill_keys(
            array_map(static fn(ScutNetwork $network): int => $network->id, $this->networksCoveringSector($a)),
            true,
        );
        foreach ($this->networksCoveringSector($b) as $network) {
            if (isset($aNetworks[$network->id])) {
                return true;
            }
        }

        return false;
    }

    public function networkById(int $id): ?ScutNetwork
    {
        return $this->networks->findById($id);
    }

    public function deleteRelay(int $id): void
    {
        $this->relays->delete($id);
    }

    /**
     * @return array<ScutRelay>
     */
    public function relaysForNetwork(int $networkId): array
    {
        return $this->relays->findByNetworkId($networkId);
    }

    /**
     * @return array<NeumannProbe>
     */
    public function probesCoveredByNetwork(int $networkId): array
    {
        $network = $this->networks->findById($networkId);
        if ($network === null) {
            return [];
        }

        $covered = array_fill_keys(array_map([$this, 'sectorKeyFromArray'], $network->coveredSectors), true);
        $found = [];
        foreach ($this->relays->findByNetworkId($networkId) as $relay) {
            foreach ($this->probes->findWithinRange($relay->sector, ScutRelay::RADIUS_SECTORS) as $probe) {
                if (!isset($covered[$probe->currentSector->toKey()])) {
                    continue;
                }
                $found[$probe->id] = $probe;
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * @return array<array{x:int,y:int,z:int}>
     */
    private function coverageFor(SectorCoordinates $sector): array
    {
        return array_map(
            static fn(SectorCoordinates $covered): array => $covered->toArray(),
            $this->grid->getSectorsWithinDistance($sector, ScutRelay::RADIUS_SECTORS),
        );
    }

    /**
     * @param array<int> $networkIds
     */
    private function mergeIntoDominantNetwork(array $networkIds, ScutRelay $newRelay): ScutNetwork
    {
        $relayCounts = [];
        foreach ($this->relays->findByNetworkIds($networkIds) as $relay) {
            if ($relay->networkId !== null) {
                $relayCounts[$relay->networkId] = ($relayCounts[$relay->networkId] ?? 0) + 1;
            }
        }

        $networks = $this->networks->findByIds($networkIds);
        usort(
            $networks,
            static fn(ScutNetwork $a, ScutNetwork $b): int => [-(int) ($relayCounts[$a->id] ?? 0), $a->createdAt, $a->id]
                <=> [-(int) ($relayCounts[$b->id] ?? 0), $b->createdAt, $b->id],
        );
        $dominant = $networks[0] ?? throw new \RuntimeException('SCUT network merge target missing.');

        foreach ($networks as $network) {
            if ($network->id === $dominant->id) {
                continue;
            }
            $this->relays->reassignNetwork($network->id, $dominant->id);
            $this->networks->delete($network->id);
        }

        $newRelay->networkId = $dominant->id;

        return $dominant;
    }

    private function recalculateNetwork(int $networkId): void
    {
        $network = $this->networks->findById($networkId);
        if ($network === null) {
            return;
        }

        $covered = [];
        $oldestActivation = null;
        foreach ($this->relays->findByNetworkId($networkId) as $relay) {
            foreach ($relay->coveredSectors as $sector) {
                if (is_array($sector)) {
                    $covered[$this->sectorKeyFromArray($sector)] = [
                        'x' => (int) ($sector['x'] ?? 0),
                        'y' => (int) ($sector['y'] ?? 0),
                        'z' => (int) ($sector['z'] ?? 0),
                    ];
                }
            }
            if ($relay->activatedAt !== null && ($oldestActivation === null || $relay->activatedAt < $oldestActivation)) {
                $oldestActivation = $relay->activatedAt;
            }
        }

        ksort($covered);
        $network->coveredSectors = array_values($covered);
        if ($oldestActivation !== null) {
            $network->createdAt = $oldestActivation;
        }
        $this->networks->save($network);
    }

    /**
     * @param array<string, mixed> $sector
     */
    private function sectorKeyFromArray(array $sector): string
    {
        return (int) ($sector['x'] ?? 0) . ':' . (int) ($sector['y'] ?? 0) . ':' . (int) ($sector['z'] ?? 0);
    }
}
