<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\Manny;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;

final class ReturningTaskHandler implements TaskHandlerInterface
{
    /**
     * @param \Closure(NeumannProbe, Manny, array<string, mixed>): bool $canAcceptMannyDocking
     * @param \Closure(Manny, array<string, mixed>): void $waitForStorageSpace
     * @param \Closure(Manny, NeumannProbe): void $transferMannyCargoToProbe
     * @param \Closure(NeumannProbe, Manny, array<string, mixed>): void $deliverReservedSalvageItems
     * @param \Closure(NeumannProbe, array<string, mixed>): void $deliverReservedDetachedContainer
     * @param \Closure(Manny): bool $mannyCargoIsEmpty
     * @param \Closure(Manny): bool $hasReservedDeliveryPayload
     * @param \Closure(NeumannProbe, Manny): bool $placeMannyOnProbe
     * @param \Closure(Manny): void $removeMannyFromSector
     * @param \Closure(Manny, array<string, mixed>): void $clearTask
     * @param \Closure(Manny): void $saveManny
     * @param \Closure(int): ?Manny $findMannyById
     */
    public function __construct(
        private readonly \Closure $canAcceptMannyDocking,
        private readonly \Closure $waitForStorageSpace,
        private readonly \Closure $transferMannyCargoToProbe,
        private readonly \Closure $deliverReservedSalvageItems,
        private readonly \Closure $deliverReservedDetachedContainer,
        private readonly \Closure $mannyCargoIsEmpty,
        private readonly \Closure $hasReservedDeliveryPayload,
        private readonly \Closure $placeMannyOnProbe,
        private readonly \Closure $removeMannyFromSector,
        private readonly \Closure $clearTask,
        private readonly \Closure $saveManny,
        private readonly \Closure $findMannyById,
    ) {
    }

    public function supports(?string $task): bool
    {
        return $task === Manny::TASK_RETURNING;
    }

    public function refresh(MannyTaskRuntime $runtime, Manny $manny, NeumannProbe $probe, \DateTimeImmutable $now): Manny
    {
        if (!$this->isAtOrAfter($now, $manny->taskEndsAt)) {
            return $manny;
        }

        if (!($this->canAcceptMannyDocking)($probe, $manny, $manny->taskPayload)) {
            ($this->waitForStorageSpace)($manny, ['reason' => 'return_to_probe']);
            ($this->saveManny)($manny);

            return ($this->findMannyById)($manny->id) ?? $manny;
        }

        ($this->transferMannyCargoToProbe)($manny, $probe);
        ($this->deliverReservedSalvageItems)($probe, $manny, $manny->taskPayload);
        ($this->deliverReservedDetachedContainer)($probe, $manny->taskPayload);
        if (($this->mannyCargoIsEmpty)($manny)) {
            $finalPayload = ($this->hasReservedDeliveryPayload)($manny) ? $manny->taskPayload : [];
            if (!($this->placeMannyOnProbe)($probe, $manny)) {
                ($this->waitForStorageSpace)($manny, ['reason' => 'return_to_probe']);
                ($this->saveManny)($manny);

                return ($this->findMannyById)($manny->id) ?? $manny;
            }
            ($this->removeMannyFromSector)($manny);
            $manny->locationType = Manny::LOCATION_PROBE;
            $manny->sector = null;
            ($this->clearTask)($manny, $finalPayload);
        } else {
            ($this->waitForStorageSpace)($manny, ['reason' => 'cargo_delivery']);
        }
        ($this->saveManny)($manny);

        return ($this->findMannyById)($manny->id) ?? $manny;
    }

    private function isAtOrAfter(\DateTimeImmutable $now, ?string $date): bool
    {
        return $date !== null && $now->getTimestamp() >= (new \DateTimeImmutable($date))->getTimestamp();
    }
}
