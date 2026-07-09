<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

final class ProbeImprovement
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $playerId,
        public readonly ?int $probeId,
        public readonly string $improvement,
        public bool $available,
        public bool $done,
        public readonly string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function publicArray(array $definition): array
    {
        $payload = [
            'id' => $this->improvement,
            'name' => (string) ($definition['name'] ?? $this->improvement),
            'description' => (string) ($definition['description'] ?? ''),
            'available' => $this->available,
            'done' => $this->done,
            'durationSeconds' => (int) ($definition['durationSeconds'] ?? 0),
            'ingredients' => is_array($definition['ingredients'] ?? null) ? $definition['ingredients'] : [],
            'effects' => is_array($definition['effects'] ?? null) ? $definition['effects'] : [],
        ];
        if ($this->createdAt !== '') {
            $payload['createdAt'] = $this->createdAt;
        }
        if ($this->updatedAt !== '') {
            $payload['updatedAt'] = $this->updatedAt;
        }

        return $payload;
    }
}
