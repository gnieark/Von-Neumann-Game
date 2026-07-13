<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Service\MannyActionException;

final class StorageMoveTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(NeumannProbe): void $ensureProbeAcceptsMannyOrders
     * @param \Closure(Manny, NeumannProbe): Manny $refreshMannyState
     * @param \Closure(NeumannProbe, string): Manny $requiredManny
     * @param \Closure(Manny, NeumannProbe): void $ensureMannyInRange
     * @param \Closure(Manny): void $ensureMannyIdle
     * @param \Closure(): int $storageMoveSecondsPerUnit
     * @param \Closure(NeumannProbe, string, float, string, string): void $assertCanMoveResource
     * @param \Closure(NeumannProbe, list<string>, string): void $assertCanMoveItems
     * @param \Closure(NeumannProbe, list<string>, string): void $assertCanMoveMannies
     * @param \Closure(string, float): int $storageMoveDurationSeconds
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(NeumannProbe, string, float, string, string, int): void $moveResource
     * @param \Closure(NeumannProbe, list<string>, string, int): void $moveItems
     * @param \Closure(NeumannProbe, string, string, int): void $moveItem
     * @param \Closure(NeumannProbe, list<string>, string, int): void $moveStoredMannies
     * @param \Closure(NeumannProbe, string, string, int): void $moveStoredManny
     * @param \Closure(Manny, array<string, mixed>): void $clearTask
     * @param \Closure(int): ?Manny $findMannyById
     */
    public function __construct(
        private readonly \Closure $ensureProbeAcceptsMannyOrders,
        private readonly \Closure $refreshMannyState,
        private readonly \Closure $requiredManny,
        private readonly \Closure $ensureMannyInRange,
        private readonly \Closure $ensureMannyIdle,
        private readonly \Closure $storageMoveSecondsPerUnit,
        private readonly \Closure $assertCanMoveResource,
        private readonly \Closure $assertCanMoveItems,
        private readonly \Closure $assertCanMoveMannies,
        private readonly \Closure $storageMoveDurationSeconds,
        private readonly \Closure $saveManny,
        private readonly \Closure $moveResource,
        private readonly \Closure $moveItems,
        private readonly \Closure $moveItem,
        private readonly \Closure $moveStoredMannies,
        private readonly \Closure $moveStoredManny,
        private readonly \Closure $clearTask,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_MOVING_STORAGE;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function start(NeumannProbe $probe, string $uid, array $payload): Manny
    {
        ($this->ensureProbeAcceptsMannyOrders)($probe);
        $manny = ($this->refreshMannyState)(($this->requiredManny)($probe, $uid), $probe);
        ($this->ensureMannyInRange)($manny, $probe);
        ($this->ensureMannyIdle)($manny);
        if (!$manny->isOnProbe()) {
            throw new MannyActionException(409, 'manny_not_on_probe', 'The Manny must be inside the probe to move storage.');
        }

        $kind = strtolower(trim((string) ($payload['kind'] ?? '')));
        if ($kind === '') {
            $kind = isset($payload['resourceType']) ? 'resource' : (isset($payload['targetMannyId']) ? 'manny' : 'item');
        }
        $toContainerId = (string) ($payload['toContainerId'] ?? $payload['toContainer'] ?? '');
        if ($toContainerId === '') {
            throw new MannyActionException(400, 'bad_request', 'Storage move target container is required.');
        }

        $movePayload = [
            'kind' => $kind,
            'toContainerId' => $toContainerId,
        ];
        $durationSeconds = ($this->storageMoveSecondsPerUnit)();

        if ($kind === 'resource') {
            $fromContainerId = (string) ($payload['fromContainerId'] ?? $payload['fromContainer'] ?? '');
            $resourceType = (string) ($payload['resourceType'] ?? $payload['type'] ?? '');
            $amount = isset($payload['amount']) && is_numeric($payload['amount']) ? round((float) $payload['amount'], 4) : 0.0;
            if ($fromContainerId === '' || $resourceType === '' || $amount <= 0.0) {
                throw new MannyActionException(400, 'bad_request', 'Resource storage move requires fromContainerId, toContainerId, resourceType and amount.');
            }
            ($this->assertCanMoveResource)($probe, $resourceType, $amount, $fromContainerId, $toContainerId);
            $durationSeconds = ($this->storageMoveDurationSeconds)('resource', $amount);
            $movePayload += [
                'fromContainerId' => $fromContainerId,
                'resourceType' => $resourceType,
                'amount' => $amount,
            ];
        } elseif ($kind === 'item') {
            $itemIds = $this->stringListPayload($payload['itemIds'] ?? null);
            $itemId = (string) ($payload['itemId'] ?? $payload['targetId'] ?? '');
            if ($itemIds === [] && $itemId !== '') {
                $itemIds = [$itemId];
            }
            $quantity = isset($payload['quantity']) && is_numeric($payload['quantity'])
                ? max(1, (int) floor((float) $payload['quantity']))
                : count($itemIds);
            $itemIds = array_slice($itemIds, 0, $quantity);
            if ($itemIds === []) {
                throw new MannyActionException(400, 'bad_request', 'Item storage move requires itemId and toContainerId.');
            }
            ($this->assertCanMoveItems)($probe, $itemIds, $toContainerId);
            $durationSeconds = ($this->storageMoveDurationSeconds)('item', count($itemIds));
            $movePayload['itemIds'] = $itemIds;
            $movePayload['quantity'] = count($itemIds);
        } elseif ($kind === 'manny') {
            $targetMannyIds = $this->stringListPayload($payload['targetMannyIds'] ?? $payload['mannyIds'] ?? null);
            $targetMannyId = (string) ($payload['targetMannyId'] ?? $payload['mannyId'] ?? $payload['targetId'] ?? '');
            if ($targetMannyIds === [] && $targetMannyId !== '') {
                $targetMannyIds = [$targetMannyId];
            }
            $quantity = isset($payload['quantity']) && is_numeric($payload['quantity'])
                ? max(1, (int) floor((float) $payload['quantity']))
                : count($targetMannyIds);
            $targetMannyIds = array_slice($targetMannyIds, 0, $quantity);
            if ($targetMannyIds === []) {
                throw new MannyActionException(400, 'bad_request', 'Manny storage move requires targetMannyId and toContainerId.');
            }
            if (in_array($uid, $targetMannyIds, true)) {
                throw new MannyActionException(422, 'invalid_storage_move', 'A Manny cannot move its own storage slot while executing the order.');
            }
            ($this->assertCanMoveMannies)($probe, $targetMannyIds, $toContainerId);
            $durationSeconds = ($this->storageMoveDurationSeconds)('manny', count($targetMannyIds));
            $movePayload['targetMannyIds'] = $targetMannyIds;
            $movePayload['quantity'] = count($targetMannyIds);
        } else {
            throw new MannyActionException(400, 'bad_request', 'Storage move kind must be resource, item or manny.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manny->currentTask = Manny::TASK_MOVING_STORAGE;
        $manny->taskStartedAt = $now->format('c');
        $manny->taskEndsAt = $now->modify('+' . $durationSeconds . ' seconds')->format('c');
        $manny->taskPayload = $movePayload + ['durationSeconds' => $durationSeconds];
        ($this->saveManny)($manny);

        return ($this->requiredManny)($probe, $uid);
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        $kind = (string) ($manny->taskPayload['kind'] ?? '');
        try {
            if ($kind === 'resource') {
                ($this->moveResource)(
                    $probe,
                    (string) ($manny->taskPayload['resourceType'] ?? ''),
                    (float) ($manny->taskPayload['amount'] ?? 0.0),
                    (string) ($manny->taskPayload['fromContainerId'] ?? ''),
                    (string) ($manny->taskPayload['toContainerId'] ?? ''),
                    $manny->id,
                );
            } elseif ($kind === 'item') {
                $itemIds = $this->stringListPayload($manny->taskPayload['itemIds'] ?? null);
                if ($itemIds !== []) {
                    ($this->moveItems)($probe, $itemIds, (string) ($manny->taskPayload['toContainerId'] ?? ''), $manny->id);
                } else {
                    ($this->moveItem)(
                        $probe,
                        (string) ($manny->taskPayload['itemId'] ?? ''),
                        (string) ($manny->taskPayload['toContainerId'] ?? ''),
                        $manny->id,
                    );
                }
            } elseif ($kind === 'manny') {
                $targetMannyIds = $this->stringListPayload($manny->taskPayload['targetMannyIds'] ?? null);
                if ($targetMannyIds !== []) {
                    ($this->moveStoredMannies)($probe, $targetMannyIds, (string) ($manny->taskPayload['toContainerId'] ?? ''), $manny->id);
                } else {
                    ($this->moveStoredManny)(
                        $probe,
                        (string) ($manny->taskPayload['targetMannyId'] ?? ''),
                        (string) ($manny->taskPayload['toContainerId'] ?? ''),
                        $manny->id,
                    );
                }
            }
            ($this->clearTask)($manny, []);
        } catch (MannyActionException $exception) {
            ($this->clearTask)($manny, [
                'result' => 'failed',
                'failureReason' => $exception->errorCode,
            ]);
        }

        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    /**
     * @return list<string>
     */
    private function stringListPayload(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => trim((string) $item),
            $value,
        ), static fn(string $item): bool => $item !== '')));
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
