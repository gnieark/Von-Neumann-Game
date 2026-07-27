<?php

declare(strict_types=1);

namespace VonNeumannGame\Http\Controller;

use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\ProbeModel;
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
    private const IDLE_REFRESH_DELAY_MS = 30000;
    private const MAX_BATCH_TASKS = 100;
    private const BATCH_ACTIONS = [
        'repair',
        'mine',
        'craft',
        'salvage',
        'install-bookmark',
        'detach-storage-container',
        'drop-storage-container',
        'drop-manny-cargo',
        'inspect-sector-object',
        'recover-storage-container',
        'refill-deuterium-tank',
        'transfer-deuterium-to-probe',
        'transfer-to-probe',
        'turn-on-relay',
        'install-scut-transit-beacon',
        'improve-probe',
        'assemble-probe',
        'recall',
    ];

    public function __construct(
        private readonly NeumannProbeRepository $probes,
        private readonly ProbeMovementService $movements,
        private readonly MannyService $mannies,
        private readonly ProbeStorageService $storage,
        private readonly ProbeItemRepository $items,
        private readonly ProbeManniesApiPresenter $presenter,
    ) {}

    public function list(Player $player, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $mannies = $this->mannies->manniesForProbe($probe);

        return new ApiResponse(200, [
            'mannies' => $this->presenter->mannies($player, $probe, $mannies),
            'nextUsefulRefreshDelayMs' => $this->nextUsefulRefreshDelayMs($mannies),
        ]);
    }

    /**
     * @param array<\VonNeumannGame\Domain\Manny> $mannies
     */
    private function nextUsefulRefreshDelayMs(array $mannies): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nextEndAt = null;
        foreach ($mannies as $manny) {
            if ($manny->currentTask === null || $manny->taskEndsAt === null) {
                continue;
            }

            $endsAt = new \DateTimeImmutable($manny->taskEndsAt);
            if ($nextEndAt === null || $endsAt < $nextEndAt) {
                $nextEndAt = $endsAt;
            }
        }

        if ($nextEndAt === null) {
            return self::IDLE_REFRESH_DELAY_MS;
        }

        return max(0, ($nextEndAt->getTimestamp() - $now->getTimestamp()) * 1000);
    }

    public function rename(Player $player, string $uid, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe ??= $this->requiredProbe($player);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a Manny name.');
        }

        $manny = $this->mannies->renameManny($probe, $uid, $data['name']);

        return new ApiResponse(200, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
    }

    public function atomicPrinterCraft(Player $player, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $this->movements->ensureProbeOperational($probe);
        $data = $this->decodeJsonBody($body);
        if (!is_array($data) || !isset($data['recipe']) || !is_string($data['recipe'])) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain recipe.');
        }

        $manny = $this->mannies->startAtomicPrinterCrafting($probe, $data['recipe']);
        $probe = $this->freshProbe($probe);

        return new ApiResponse(202, [
            'manny' => $this->presenter->manny($player, $probe, $manny),
            'inventory' => $this->inventoryForProbe($probe)->toArray(),
        ]);
    }

    public function batchActions(Player $player, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $data = $this->decodeJsonBody($body);
        $tasks = is_array($data) ? ($data['tasks'] ?? null) : null;
        if (!is_array($tasks) || !array_is_list($tasks) || $tasks === []) {
            return ApiResponse::error(400, 'bad_request', 'JSON body must contain a non-empty tasks list.');
        }
        if (count($tasks) > self::MAX_BATCH_TASKS) {
            return ApiResponse::error(400, 'bad_request', 'A batch cannot contain more than 100 tasks.');
        }

        $normalizedTasks = [];
        $seenMannyIds = [];
        foreach ($tasks as $index => $task) {
            if (!is_array($task)) {
                return $this->batchTaskError($index, 'Each task must be an object.');
            }

            $mannyId = $task['mannyId'] ?? null;
            $action = $task['task'] ?? null;
            $payload = $task['payload'] ?? [];
            if (!is_string($mannyId) || trim($mannyId) === '') {
                return $this->batchTaskError($index, 'mannyId must be a non-empty string.');
            }
            if (!is_string($action) || !in_array($action, self::BATCH_ACTIONS, true)) {
                return $this->batchTaskError($index, 'task is not a supported Manny action.');
            }
            if (!is_array($payload) || (array_is_list($payload) && $payload !== [])) {
                return $this->batchTaskError($index, 'payload must be a JSON object.');
            }
            if (isset($seenMannyIds[$mannyId])) {
                return $this->batchTaskError($index, 'A Manny can only occur once in a batch.');
            }

            $seenMannyIds[$mannyId] = true;
            $normalizedTasks[] = [
                'mannyId' => $mannyId,
                'task' => $action,
                'payload' => $payload,
            ];
        }

        try {
            $results = $this->probes->withProbeLock(
                $probe->id,
                function () use ($player, $probe, $normalizedTasks): array {
                    $results = [];
                    foreach ($normalizedTasks as $index => $task) {
                        $response = $this->action(
                            $player,
                            $task['mannyId'],
                            $task['task'],
                            json_encode($task['payload'], JSON_THROW_ON_ERROR),
                            $probe,
                        );
                        if ($response->status !== 202) {
                            throw new BatchMannyActionRejected($index, $response);
                        }
                        $results[] = $response->body;
                    }

                    return $results;
                },
            );
        } catch (BatchMannyActionRejected $rejected) {
            $error = $rejected->response->body['error'] ?? [
                'code' => 'bad_request',
                'message' => 'Manny task rejected.',
            ];
            $error['details'] = array_merge($error['details'] ?? [], ['taskIndex' => $rejected->taskIndex]);

            return new ApiResponse($rejected->response->status, ['error' => $error]);
        }

        return new ApiResponse(202, ['results' => $results]);
    }

    public function action(Player $player, string $uid, string $action, ?string $body, ?NeumannProbe $probe = null): ApiResponse
    {
        $probe = $this->movements->refreshProbeMovementState($probe ?? $this->requiredProbe($player));
        $data = $this->decodeJsonBody($body) ?? [];

        if ($action === 'repair') {
            $repairPercent = $data['integrityPercent'] ?? $data['percent'] ?? null;
            if (!is_numeric($repairPercent)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain repair percent.');
            }

            $manny = $this->mannies->startRepair($probe, $uid, (float) $repairPercent);
            $probe = $this->freshProbe($probe);

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
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'craft') {
            if (!isset($data['recipe']) || !is_string($data['recipe'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain recipe.');
            }

            $manny = $this->mannies->startCrafting($probe, $uid, $data['recipe']);
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'salvage') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startSalvage($probe, $uid, $data['objectId']);
            $probe = $this->freshProbe($probe);

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
            $probe = $this->freshProbe($probe);

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
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'inspect-sector-object' || $action === 'inspect-asteroid') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startInspectSectorObject($probe, $uid, $data['objectId']);
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'recover-storage-container') {
            if (!isset($data['objectId']) || !is_string($data['objectId'])) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain objectId.');
            }

            $manny = $this->mannies->startRecoverDetachedContainer($probe, $uid, $data['objectId']);
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'drop-manny-cargo') {
            $manny = $this->mannies->dropMannyCargo($probe, $uid);
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'refill-deuterium-tank') {
            $manny = $this->mannies->startDeuteriumTankRefill($probe, $uid);
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'transfer-deuterium-to-probe') {
            $targetProbeId = $data['targetProbeId'] ?? $data['probeId'] ?? null;
            $amount = $data['amount'] ?? $data['deuteriumAmount'] ?? null;
            if (!is_int($targetProbeId) && !(is_string($targetProbeId) && ctype_digit($targetProbeId))) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain targetProbeId as an integer.');
            }
            if (!is_numeric($amount)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain amount.');
            }

            $manny = $this->mannies->startDeuteriumTransferToProbe($probe, $uid, (int) $targetProbeId, (float) $amount);
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'transfer-to-probe') {
            $targetProbeId = $data['targetProbeId'] ?? $data['probeId'] ?? null;
            if (!is_int($targetProbeId) && !(is_string($targetProbeId) && ctype_digit($targetProbeId))) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain targetProbeId as an integer.');
            }

            $manny = $this->mannies->startMannyTransferToProbe($probe, $uid, (int) $targetProbeId);
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        if ($action === 'improve-probe') {
            $improvement = $data['improvement'] ?? $data['id'] ?? null;
            if (!is_string($improvement) || trim($improvement) === '') {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain improvement.');
            }

            $manny = $this->mannies->startProbeImprovement($probe, $uid, $improvement);
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, [
                'manny' => $this->presenter->manny($player, $probe, $manny),
                'improvements' => $this->presenter->probeImprovements($probe),
            ]);
        }

        if ($action === 'assemble-probe') {
            $containerIds = $data['containerIds'] ?? $data['containers'] ?? null;
            if ($containerIds === null && isset($data['containerIdA'], $data['containerIdB'])) {
                $containerIds = [$data['containerIdA'], $data['containerIdB']];
            }
            if (!is_array($containerIds)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain containerIds.');
            }
            $containerIds = array_values($containerIds);
            foreach ($containerIds as $containerId) {
                if (!is_string($containerId)) {
                    return ApiResponse::error(400, 'bad_request', 'containerIds must contain strings.');
                }
            }

            $model = $data['model'] ?? ProbeModel::GENERIC;
            if (!is_string($model) || !ProbeModel::isValid($model)) {
                return ApiResponse::error(400, 'invalid_probe_model', 'model must be one of: ' . implode(', ', ProbeModel::ALL) . '.');
            }

            $manny = $this->mannies->startProbeAssembly($probe, $uid, $containerIds, $model);
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, [
                'manny' => $this->presenter->manny($player, $probe, $manny),
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
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
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, [
                'manny' => $this->presenter->manny($player, $probe, $manny),
                'inventory' => $this->inventoryForProbe($probe)->toArray(),
            ]);
        }

        if ($action === 'install-scut-transit-beacon') {
            $blocked = $this->probeMovementOrderError($probe);
            if ($blocked instanceof ApiResponse) {
                return $blocked;
            }
            $relayId = $data['relayId'] ?? $data['scutRelayId'] ?? null;
            if (!is_int($relayId)) {
                return ApiResponse::error(400, 'bad_request', 'JSON body must contain relayId as an integer.');
            }

            $manny = $this->mannies->startScutTransitBeaconInstallation($probe, $uid, $relayId);
            $probe = $this->freshProbe($probe);

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
            $probe = $this->freshProbe($probe);

            return new ApiResponse(202, ['manny' => $this->presenter->manny($player, $probe, $manny)]);
        }

        $manny = $this->mannies->recallManny($probe, $uid);
        $probe = $this->freshProbe($probe);

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

    private function batchTaskError(int $index, string $message): ApiResponse
    {
        return ApiResponse::error(400, 'bad_request', $message, ['taskIndex' => $index]);
    }

    private function requiredProbe(Player $player): NeumannProbe
    {
        return $this->probes->findByPlayerId($player->id) ?? throw new \RuntimeException('Probe not found.');
    }

    private function freshProbe(NeumannProbe $probe): NeumannProbe
    {
        return $this->probes->findById($probe->id) ?? $probe;
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

final class BatchMannyActionRejected extends \RuntimeException
{
    public function __construct(
        public readonly int $taskIndex,
        public readonly ApiResponse $response,
    ) {
        parent::__construct('Manny batch action rejected.');
    }
}
