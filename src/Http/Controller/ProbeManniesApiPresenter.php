<?php

declare(strict_types=1);

namespace VonNeumannGame\Http\Controller;

use VonNeumannGame\Domain\Manny;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ProbeImprovement;
use VonNeumannGame\Domain\ProbeImprovementCatalog;
use VonNeumannGame\Repository\ProbeImprovementRepository;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Service\MannyService;

final class ProbeManniesApiPresenter
{
    public function __construct(
        private readonly MannyService $mannies,
        private readonly ?ProbeImprovementRepository $improvements,
        private readonly array $gameplayConfig = [],
    ) {}

    public function manny(Player $player, NeumannProbe $probe, Manny $manny): array
    {
        $relativeSector = $manny->sector === null
            ? null
            : (new PlayerReferenceFrame($player->homeSector))->globalToRelative($manny->sector);

        return $this->mannies->publicArray($probe, $manny, $relativeSector);
    }

    /**
     * @param array<Manny> $mannies
     * @return list<array<string, mixed>>
     */
    public function mannies(Player $player, NeumannProbe $probe, array $mannies): array
    {
        return array_map(fn(Manny $manny): array => $this->manny($player, $probe, $manny), $mannies);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function probeImprovements(NeumannProbe $probe, bool $includeAll = false): array
    {
        $definitions = [];
        foreach (ProbeImprovementCatalog::all($this->gameplayConfig['probeImprovements'] ?? []) as $definition) {
            $definitions[(string) $definition['id']] = $definition;
        }

        $rows = [];
        if ($this->improvements !== null) {
            foreach ($this->improvements->findByProbeId($probe->id) as $improvement) {
                $rows[$improvement->improvement] = $improvement;
            }
        }

        $result = [];
        foreach ($definitions as $id => $definition) {
            $row = $rows[$id] ?? new ProbeImprovement(0, $probe->id, $id, false, false, '', '');
            if (!$includeAll && !$row->available && !$row->done) {
                continue;
            }
            $result[] = $row->publicArray($definition);
        }

        return $result;
    }
}
