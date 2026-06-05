<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeItem;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\UniverseObject;

final class WaypointBookmarkService
{
    public function __construct(
        private readonly ProbeItemRepository $items,
        private readonly SectorService $sectors,
    ) {}

    public function deploy(NeumannProbe $probe, Player $player, string $itemUid, string $objectId, string $name): UniverseObject
    {
        $item = $this->requiredBookmarkItem($probe, $itemUid);
        $updatedObject = $this->deployForPlayer(
            $probe,
            $player->id,
            $player->displayName ?? $player->username,
            $objectId,
            $name,
        );
        $this->items->delete($item);

        return $updatedObject;
    }

    public function requiredBookmarkItem(NeumannProbe $probe, string $itemUid): ProbeItem
    {
        $item = $this->items->findByUidForProbe($probe->id, $itemUid);
        if ($item === null || $item->type !== ProbeItem::TYPE_WAYPOINT_BOOKMARK) {
            throw new MannyActionException(404, 'waypoint_bookmark_not_found', 'Waypoint bookmark not found in probe inventory.');
        }

        return $item;
    }

    public function deployableTarget(NeumannProbe $probe, string $objectId, ?SectorCoordinates $sectorCoordinates = null): UniverseObject
    {
        $sector = $this->sectors->getOrCreateSector($sectorCoordinates ?? $probe->currentSector);
        $target = $sector->findObjectById($objectId);
        if ($target === null) {
            throw new MannyActionException(404, 'bookmark_target_not_found', 'Target object not found in the current sector.');
        }
        if ($target instanceof SectorManny) {
            throw new MannyActionException(422, 'invalid_bookmark_target', 'A waypoint bookmark can only be placed on a celestial object.');
        }

        return $target;
    }

    public function deployForPlayer(NeumannProbe $probe, int $playerId, string $playerName, string $objectId, string $name, ?SectorCoordinates $sectorCoordinates = null): UniverseObject
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 80) {
            throw new MannyActionException(400, 'bad_request', 'Waypoint bookmark name must contain 1 to 80 characters.');
        }

        $sector = $this->sectors->getOrCreateSector($sectorCoordinates ?? $probe->currentSector);
        $target = $this->deployableTarget($probe, $objectId, $sector->getCoordinates());
        $bookmark = [
            'name' => $name,
            'playerId' => $playerId,
            'playerName' => $playerName,
            'createdAt' => gmdate('c'),
        ];
        $updatedObject = $target->withWaypointBookmark($name, $bookmark);
        if (!$sector->replaceObject($updatedObject)) {
            throw new \RuntimeException('Unable to update bookmark target.');
        }

        $this->sectors->saveSector($sector);

        return $updatedObject;
    }
}
