<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class SectorManny extends UniverseObject
{
    public const STATE_ABANDONED = 'abandoned';
    public const STATE_FORGOTTEN = 'forgotten';

    public static function objectIdForUid(string $uid): string
    {
        return 'manny-' . $uid;
    }

    public function __construct(
        string $id,
        ?string $name,
        private readonly string $mannyUid,
        private readonly string $state,
        private readonly array $cargo = [],
        ?string $description = null,
        array $waypointBookmarks = [],
    ) {
        parent::__construct($id, $name, UniverseObjectType::Manny, 0.0, 0.0, $description, $waypointBookmarks);
    }

    public function getMannyUid(): string
    {
        return $this->mannyUid;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getCargo(): array
    {
        return $this->cargo;
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'mannyUid' => $this->mannyUid,
            'state' => $this->state,
            'cargo' => $this->cargo,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            $data['name'] ?? null,
            (string) ($data['mannyUid'] ?? ''),
            (string) ($data['state'] ?? self::STATE_ABANDONED),
            is_array($data['cargo'] ?? null) ? $data['cargo'] : [],
            $data['description'] ?? null,
            is_array($data['waypointBookmarks'] ?? null) ? $data['waypointBookmarks'] : [],
        );
    }
}
