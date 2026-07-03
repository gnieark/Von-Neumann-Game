<?php

declare(strict_types=1);

namespace VonNeumannGame\Http\Controller;

use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeInventory;
use VonNeumannGame\Http\ApiResponse;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\Repository\ProbeItemRepository;
use VonNeumannGame\Service\MannyService;
use VonNeumannGame\Service\ProbeMovementService;
use VonNeumannGame\Service\ProbeStorageService;

final class ProbeManniesApiController
{
    public function __construct(
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeMovementService $movements,
        private readonly MannyService $mannies,
        private readonly ProbeStorageService $storage,
        private readonly ProbeItemRepository $items,
        private readonly ProbeManniesApiPresenter $presenter,
    ) {}

    public function list(Player $player): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $mannies = $this->mannies->manniesForProbe($probe);

        return new ApiResponse(200, [
            'mannies' => $this->presenter->mannies($player, $probe, $mannies),
        ]);
    }

    public function rename(Player $player, string $uid, ?string $body): ApiResponse
    {
        $probe = $this->requiredProbe($player);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a Manny name.');
        }

        $manny = $this->mannies->renameManny($probe, $uid, $data['name']);

        return new ApiResponse(200, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
    }

    public function atomicPrinterCraft(Player $player, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['recipe']) || !is_string($data['recipe'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain recipe.');
        }

        $manny = $this->mannies->startAtomicPrinterCrafting($probe, $data['recipe']);

        return new ApiResponse(202, [
            'manny' => $this->presenter->manny($player, $probe, $manny),
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    public function action(Player $player, string $uid, string $action, ?string $body): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($this->requiredProbe($player));
        $data = $this->decodeJsonBody($body) ?? [];

        if ($action === 'repair') {
            $repairPercent = $data['integrityPercent'] ?? $data['percent'] ?? null;
            if (!is_numeric($repairPercent)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain repair percent.');
            }

            $manny = $this->mannies->startRepair($probe, $uid, (float) $repairPercent);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'mine') {
            if (!isset($data['objectId'], $data['targetAmount']) || !is_string($data['objectId']) || !is_numeric($data['targetAmount'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId, resources and targetAmount.');
            }

            $resources = $data['resources'] ?? $data['resource'] ?? null;
            if (is_array($resources)) {
                foreach ($resources as $resource) {
                    if (!is_string($resource)) {
                        return ApiResponse::error(400, 'bad_request', 'Mining resources must be strings.');
                    }
                }
            } elseif (!is_string($resources)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain resources or resource.');
            }

            $targetContainerId = $data['targetContainerId'] ?? null;
            if ($targetContainerId !== null && !is_string($targetContainerId)) {
                return ApiResponse::error(400, 'bad_request', 'targetContainerId must be a string when provided.');
            }

            $manny = $this->mannies->startMining($probe, $uid, $data['objectId'], $resources, (float) $data['targetAmount'], $targetContainerId);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'craft') {
            if (!isset($data['recipe']) || !is_string($data['recipe'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain recipe.');
            }

            $manny = $this->mannies->startCrafting($probe, $uid, $data['recipe']);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'salvage') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startSalvage($probe, $uid, $data['objectId']);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'detach-storage-container') {
            $blocked = $this->probeMovementOrderError($probe);
            if ($blocked instanceof ApiResponse) {
                return $blocked;
            }
            if (!isset($data['containerId'], $data['mode']) || !is_string($data['containerId']) || !is_string($data['mode'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain containerId and mode.');
            }
            $objectId = isset($data['objectId']) && is_string($data['objectId']) ? $data['objectId'] : null;
            $manny = $this->mannies->startDetachStorageContainer($probe, $player->id, $uid, $data['containerId'], $data['mode'], $objectId);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'drop-storage-container') {
            $blocked = $this->probeMovementOrderError($probe);
            if ($blocked instanceof ApiResponse) {
                return $blocked;
            }
            if (!isset($data['containerId'], $data['planetId']) || !is_string($data['containerId']) || !is_string($data['planetId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain containerId and planetId.');
            }

            $manny = $this->mannies->startDropStorageContainerOnPlanet($probe, $player->id, $uid, $data['containerId'], $data['planetId']);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'inspect-sector-object' || $action === 'inspect-asteroid') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startInspectSectorObject($probe, $uid, $data['objectId']);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'recover-storage-container') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startRecoverDetachedContainer($probe, $uid, $data['objectId']);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'drop-manny-cargo') {
            $manny = $this->mannies->dropMannyCargo($probe, $uid);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'refill-deuterium-tank') {
            $manny = $this->mannies->startDeuteriumTankRefill($probe, $uid);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'improve-probe') {
            $improvement = $data['improvement'] ?? $data['id'] ?? null;
            if (!is_string($improvement) || trim($improvement) === '') {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain improvement.');
            }

            $manny = $this->mannies->startProbeImprovement($probe, $uid, $improvement);

            return new ApiResponse(202, [
                'manny' => $this->presenter->manny($player, $probe, $manny),
                'improvements' => $this->presenter->probeImprovements($probe),
            ]);
        }

        if ($action === 'turn-on-relay') {
            $blocked = $this->probeMovementOrderError($probe);
            if ($blocked instanceof ApiResponse) {
                return $blocked;
            }
            $relayId = $data['relayId'] ?? $data['scutRelayId'] ?? null;
            if (!is_int($relayId)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain relayId as an integer.');
            }
            $networkName = $data['networkName'] ?? $data['name'] ?? null;
            if ($networkName !== null && !is_string($networkName)) {
                return ApiResponse::error(400, 'bad_request', 'networkName must be a string when provided.');
            }

            $manny = $this->mannies->startScutRelayTurnOn($probe, $uid, $relayId, $networkName);

            return new ApiResponse(202, [
                'manny' => $this->presenter->manny($player, $probe, $manny),
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
            ]);
        }

        if ($action === 'install-bookmark') {
            $blocked = $this->probeMovementOrderError($probe);
            if ($blocked instanceof ApiResponse) {
                return $blocked;
            }
            if (!isset($data['objectId'], $data['name']) || !is_string($data['objectId']) || !is_string($data['name'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId and name.');
            }

            $manny = $this->mannies->startWaypointBookmarkInstallation($probe, $player, $uid, $data['objectId'], $data['name']);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        $manny = $this->mannies->recallManny($probe, $uid);

        return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
    }

    private function probeMovementOrderError(NeumannProbe $probe): ?ApiResponse
    {
        $this->movements->ensureProbeOperational($probe);
        if ($this->movements->activeMovementForProbe($probe) !== null) {
            return ApiResponse::error(409, 'probe_already_moving', 'The probe is already moving between sectors.');
        }

        return null;
    }

    private function requiredProbe(Player $player): NeumannProbe
    {
        return $this->probes->findByPlayerId($player->id) ?? throw new \RuntimeException('Probe not found.');
    }

    private function inventoryForProbe(NeumannProbe $probe): ProbeInventory
    {
        return $this->storage->inventoryForProbe(
            $probe,
            $this->mannies->manniesForProbe($probe),
            $this->items->findByProbeId($probe->id),
        );
    }

    private function decodeJsonBody(?string $body): ?array
    {
        try {
            $decoded = json_decode($body ?? '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
