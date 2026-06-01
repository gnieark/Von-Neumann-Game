<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Domain\SectorKnowledgeLevel;
use VonNeumannGame\Domain\SectorObservation;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\BlackHole;
use VonNeumannGame\Sector\DustCloud;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SolarSystem;
use VonNeumannGame\Sector\Star;
use VonNeumannGame\Sector\UniverseObject;

final class SectorObservationService
{
    private const MANNY_MINEABLE_MAX_MASS = 0.0123;

    private readonly SectorGrid $grid;

    public function __construct(
        private readonly SectorService $sectors,
        private readonly VisitedSectorRepository $visitedSectors,
        ?SectorGrid $grid = null,
    ) {
        $this->grid = $grid ?? new SectorGrid();
    }

    public function observe(Player $player, NeumannProbe $probe, SectorCoordinates $target): SectorObservation
    {
        $frame = new PlayerReferenceFrame($player->homeSector);
        $relative = $frame->globalToRelative($target);
        $distance = $this->grid->getDistance($probe->currentSector, $target);
        $residenceSeconds = $this->residenceSeconds($probe);
        $requiredSeconds = $this->requiredResidenceSeconds($distance);
        $content = $this->sectors->getOrCreateSector($target);
        $visited = $this->visitedSectors->hasVisited($player, $target);
        $current = $probe->currentSector->equals($target);
        $scan = $this->scanMetadata($residenceSeconds, $requiredSeconds);

        if ($current || $visited) {
            return new SectorObservation(
                $relative,
                $distance,
                SectorKnowledgeLevel::Detailed,
                1.0,
                ['objects' => $this->detailedObjects($content, $current)],
                $scan,
            );
        }

        if ($residenceSeconds < $requiredSeconds) {
            throw new ObservationAccessException(
                'insufficient_scan_data',
                $distance === 1
                    ? 'Too little passive scan data available to estimate nearby objects.'
                    : 'Too little passive scan data available to estimate distant object trajectories.',
            );
        }

        if ($distance === 1) {
            return new SectorObservation(
                $relative,
                $distance,
                SectorKnowledgeLevel::NeighborScan,
                $this->round(min(0.92, 0.62 + ($scan['scanQuality'] * 0.3))),
                ['estimatedObjects' => $this->neighborEstimate($content, $scan['scanQuality'])],
                $scan,
            );
        }

        if ($distance === 2) {
            return new SectorObservation(
                $relative,
                $distance,
                SectorKnowledgeLevel::DistantScan,
                $this->round(min(0.58, 0.24 + ($scan['scanQuality'] * 0.25))),
                [
                    'possibleObjects' => $this->distantSignatures($content),
                    'dangerEstimate' => $this->dangerEstimate($content),
                ],
                $scan,
            );
        }

        return new SectorObservation(
            $relative,
            $distance,
            SectorKnowledgeLevel::LongRangeEstimation,
            $this->round(min(0.24, 0.08 + ($scan['scanQuality'] * 0.12))),
            [
                'navigationalRisk' => $content->hasBlackHole() ? 'high' : 'unknown',
                'message' => 'Insufficient sensor accuracy.',
            ],
            $scan,
        );
    }

    public function relativeToAbsolute(Player $player, int $x, int $y, int $z): SectorCoordinates
    {
        return (new PlayerReferenceFrame($player->homeSector))->relativeToGlobal($x, $y, $z);
    }

    private function residenceSeconds(NeumannProbe $probe): int
    {
        $entered = new \DateTimeImmutable($probe->enteredCurrentSectorAt);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return max(0, $now->getTimestamp() - $entered->getTimestamp());
    }

    private function requiredResidenceSeconds(int $distance): int
    {
        return match (true) {
            $distance === 0 => 0,
            $distance === 1 => 300,
            $distance === 2 => 1800,
            default => 7200,
        };
    }

    private function scanMetadata(int $residenceSeconds, int $requiredSeconds): array
    {
        $quality = $requiredSeconds === 0
            ? 1.0
            : min(1.0, $residenceSeconds / max(1, $requiredSeconds * 4));

        return [
            'currentSectorResidenceSeconds' => $residenceSeconds,
            'requiredResidenceSeconds' => $requiredSeconds,
            'scanQuality' => $this->round($quality),
        ];
    }

    private function detailedObject(UniverseObject $object): array
    {
        $data = [
            'id' => $object->getId(),
            'type' => $object->getType()->value,
            'name' => $object->getName(),
            'estimated' => false,
            'summary' => $this->summary($object),
            'mass' => $object->getMass(),
            'radius' => $object->getRadius(),
            'dangerLevel' => $object instanceof BlackHole ? 'extreme' : ($object instanceof DustCloud ? 'moderate' : 'low'),
        ];

        if ($object instanceof SolarSystem) {
            $planetCount = 0;
            foreach ($object->getOrbitalBodies() as $body) {
                if ($body->getObject() instanceof Planet) {
                    $planetCount++;
                }
            }
            $data['starCount'] = count($object->getStars());
            $data['planetCount'] = $planetCount;
            $data['orbitalBodyCount'] = count($object->getOrbitalBodies());
            $data['minableTargets'] = $this->minableTargets($object);
        }

        if ($object instanceof Star) {
            $data['spectralClass'] = $object->getSpectralType();
        }

        if ($object instanceof Planet || $object instanceof Asteroid) {
            $resources = $this->objectResourceHints($object);
            $composition = $this->objectResourceComposition($object);
            $data['resources'] = $resources;
            $data['resourceTypes'] = ResourceComposition::availableTypes($composition);
            $data['resourceComposition'] = $composition;
            $data['mannyMineable'] = $this->isMannyMineable($object);
            if ($object instanceof Asteroid) {
                $data['composition'] = $object->toArray()['composition'] ?? null;
                $data['resourceAmounts'] = $object->getResourceAmounts();
            }
            if ($object instanceof Planet) {
                $data['category'] = $object->getCategory();
            }
        }

        if ($object instanceof SectorManny) {
            $data['mannyState'] = $object->getState();
            $data['mannyUid'] = $object->getMannyUid();
            $data['cargo'] = $object->getCargo();
        }

        return $data;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function detailedObjects(SectorContent $content, bool $isCurrentSector): array
    {
        $objects = [];
        foreach ($content->getObjects() as $object) {
            if ($object instanceof SectorManny && !$isCurrentSector) {
                continue;
            }

            $objects[] = $this->detailedObject($object);
        }

        return $objects;
    }

    private function minableTargets(SolarSystem $system): array
    {
        $targets = [];
        foreach ($system->getOrbitalBodies() as $body) {
            $object = $body->getObject();
            if (!$this->isMannyMineable($object)) {
                continue;
            }

            $resources = $this->objectResourceHints($object);
            $composition = $this->objectResourceComposition($object);
            $target = [
                'id' => $object->getId(),
                'type' => $object->getType()->value,
                'name' => $object->getName(),
                'mass' => $object->getMass(),
                'resources' => $resources,
                'resourceTypes' => ResourceComposition::availableTypes($composition),
                'resourceComposition' => $composition,
            ];
            if ($object instanceof Asteroid) {
                $target['resourceAmounts'] = $object->getResourceAmounts();
            }
            $targets[] = $target;
        }

        return $targets;
    }

    private function objectResourceHints(UniverseObject $object): array
    {
        $data = $object->toArray();

        return $data['resourceHints'] ?? $data['estimatedResources'] ?? [];
    }

    /**
     * @return array<string, float>
     */
    private function objectResourceComposition(UniverseObject $object): array
    {
        if ($object instanceof Asteroid) {
            return ResourceComposition::fromAmounts($object->getResourceAmounts());
        }

        return ResourceComposition::fromHints($this->objectResourceHints($object));
    }

    private function isMannyMineable(UniverseObject $object): bool
    {
        return ($object instanceof Planet || $object instanceof Asteroid)
            && $object->getMass() <= self::MANNY_MINEABLE_MAX_MASS;
    }

    private function neighborEstimate(SectorContent $content, float $quality): array
    {
        $planetCount = $this->planetCount($content);
        $blur = max(1, (int) round(3 - ($quality * 2)));

        return [
            'star' => $content->hasStar(),
            'planetCountMin' => max(0, $planetCount - $blur),
            'planetCountMax' => $planetCount + $blur,
            'blackHoleProbability' => $content->hasBlackHole() ? 0.7 : 0.03,
            'dangerEstimate' => $this->dangerEstimate($content),
            'signalAge' => 'recent',
        ];
    }

    private function distantSignatures(SectorContent $content): array
    {
        $signatures = [];
        if ($content->hasStar()) {
            $signatures[] = 'stellar_mass_detected';
        }
        if ($content->hasBlackHole()) {
            $signatures[] = 'strong_gravity_signature';
        }
        foreach ($content->getObjects() as $object) {
            if ($object instanceof DustCloud) {
                $signatures[] = 'dust_cloud_possible';
            }
        }

        return $signatures === [] ? ['no_major_signature'] : array_values(array_unique($signatures));
    }

    private function planetCount(SectorContent $content): int
    {
        $count = 0;
        foreach ($content->getObjects() as $object) {
            if ($object instanceof Planet) {
                $count++;
            }
            if ($object instanceof SolarSystem) {
                foreach ($object->getOrbitalBodies() as $body) {
                    if ($body->getObject() instanceof Planet) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private function dangerEstimate(SectorContent $content): string
    {
        if ($content->hasBlackHole()) {
            return 'extreme';
        }
        foreach ($content->getObjects() as $object) {
            if ($object instanceof DustCloud) {
                return 'moderate';
            }
        }

        return 'low';
    }

    private function summary(UniverseObject $object): string
    {
        return match (true) {
            $object instanceof SolarSystem => sprintf('Stellar system with %d star(s) and %d orbital body(ies).', count($object->getStars()), count($object->getOrbitalBodies())),
            $object instanceof Star => 'Isolated star or stellar remnant.',
            $object instanceof Planet => 'Planetary body detected.',
            $object instanceof Asteroid => 'Wandering asteroid body.',
            $object instanceof DustCloud => 'Diffuse dust cloud with sensor interference.',
            $object instanceof BlackHole => 'Dangerous compact object detected.',
            $object instanceof SectorManny => match ($object->getState()) {
                SectorManny::STATE_FORGOTTEN => 'Manny left behind by a probe.',
                default => 'Abandoned Manny drifting in this sector.',
            },
            default => 'Unknown astronomical object.',
        };
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }
}
