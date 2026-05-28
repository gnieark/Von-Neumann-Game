<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\ProbeMovement;

final class DeterministicRiskRoll
{
    public function roll(string $worldSeed, ProbeMovement $movement): float
    {
        $payload = implode('|', [
            $worldSeed,
            $movement->probeId,
            $movement->id,
            $movement->origin->toKey(),
            $movement->target->toKey(),
            $movement->startedAt,
        ]);
        $hex = substr(hash('sha256', $payload), 0, 15);

        return hexdec($hex) / hexdec(str_repeat('f', 15));
    }
}
