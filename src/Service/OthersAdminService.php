<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Repository\OthersAuditRepository;
use VonNeumannGame\Repository\OthersRepository;
use VonNeumannGame\Repository\PlayerRepository;
use VonNeumannGame\Sector\SectorCoordinates;

final class OthersAdminService
{
    public function __construct(
        private readonly PlayerRepository $players,
        private readonly OthersRepository $others,
        private readonly OthersAuditRepository $audit,
    ) {}

    public function createFleet(int $playerId, SectorCoordinates $sector): array
    {
        if ($this->players->findById($playerId) === null) {
            $this->audit->record(null, 'cli', 'create_fleet', 'refused', details: ['reason' => 'player_not_found']);
            throw new \InvalidArgumentException('Player not found.');
        }
        try {
            $result = $this->others->transaction(function () use ($playerId, $sector): array {
                $fleet = $this->others->createFleet($playerId, $sector->getX(), $sector->getY(), $sector->getZ());
                $this->players->setOthersControl($playerId, true);
                return $fleet;
            });
            $this->audit->record($playerId, 'cli', 'create_fleet', 'accepted', (string) $result['public_id']);
            return $result;
        } catch (\Throwable $error) {
            $this->audit->record($playerId, 'cli', 'create_fleet', 'refused', details: ['reason' => $error->getMessage()]);
            throw $error;
        }
    }

    public function createStandardShip(string $mothershipPublicId): array
    {
        $mothership = $this->others->findShipByPublicId($mothershipPublicId);
        if ($mothership === null || $mothership['type'] !== 'mothership' || $mothership['destroyed_at'] !== null) {
            $this->audit->record(null, 'cli', 'create_standard_ship', 'refused', $mothershipPublicId, ['reason' => 'mothership_not_found']);
            throw new \InvalidArgumentException('Active Others mothership not found.');
        }
        $ship = $this->others->createStandardShip($mothership);
        $this->audit->record((int) $mothership['player_id'], 'cli', 'create_standard_ship', 'accepted', (string) $ship['public_id']);
        return $ship;
    }

    public function createAuxiliary(string $shipPublicId): array
    {
        $ship = $this->others->findShipByPublicId($shipPublicId);
        if ($ship === null || $ship['destroyed_at'] !== null || $ship['status'] === 'removed') {
            $this->audit->record(null, 'cli', 'create_auxiliary', 'refused', $shipPublicId, ['reason' => 'ship_not_found']);
            throw new \InvalidArgumentException('Active Others ship not found.');
        }
        $auxiliary = $this->others->createAuxiliary((int) $ship['id']);
        $this->audit->record((int) $ship['player_id'], 'cli', 'create_auxiliary', 'accepted', (string) $auxiliary['public_id']);
        return $auxiliary;
    }

    public function setControl(int $playerId, bool $enabled): void
    {
        if (!$this->players->setOthersControl($playerId, $enabled)) {
            $this->audit->record(null, 'cli', 'set_control', 'refused', details: ['reason' => 'player_not_found', 'enabled' => $enabled]);
            throw new \InvalidArgumentException('Player not found.');
        }
        $this->audit->record($playerId, 'cli', 'set_control', 'accepted', details: ['enabled' => $enabled]);
    }
}
