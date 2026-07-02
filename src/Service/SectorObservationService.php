<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

use VonNeumannGame\Config\Config;
use VonNeumannGame\Domain\NeumannProbe;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\Domain\ResourceComposition;
use VonNeumannGame\Domain\SectorKnowledgeLevel;
use VonNeumannGame\Domain\SectorObservation;
use VonNeumannGame\Repository\MannyRepository;
use VonNeumannGame\Repository\VisitedSectorRepository;
use VonNeumannGame\Sector\Asteroid;
use VonNeumannGame\Sector\BlackHole;
use VonNeumannGame\Sector\DeuteriumRefuelStation;
use VonNeumannGame\Sector\DormantConstruct;
use VonNeumannGame\Sector\DustCloud;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorDriftingItem;
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
    private readonly array $scanConfig;
    private readonly array $mannyConfig;

    public function __construct(
        private readonly SectorService $sectors,
        private readonly VisitedSectorRepository $visitedSectors,
        ?SectorGrid $grid = null,
        array $config = [],
        private readonly ?MannyRepository $mannies = null,
    ) {
        $this->grid = $grid ?? new SectorGrid();
        $this->scanConfig = Config::getArray($config, 'scan', $config);
        $this->mannyConfig = Config::getArray($config, 'manny', $config);
    }

    public function observe(Player $player, NeumannProbe $probe, SectorCoordinates $target): SectorObservation
    {
        $frame = new PlayerReferenceFrame($player->homeSector);
        $relative = $frame->globalToRelative($target);
        $distance = $this->grid->getDistance($probe->currentSector, $target);
        $residenceSeconds = $this->residenceSeconds($probe);
        $requiredSeconds = $this->skipsInitialNeighborDelay($player, $distance)
            ? 0
            : $this->requiredResidenceSeconds($distance);
        $content = $this->abandonOrphanedForgottenMannies($this->sectors->getOrCreateSector($target));
        $visited = $this->visitedSectors->hasVisited($player, $target);
        $current = $probe->currentSector->equals($target);
        $scan = $this->scanMetadata($residenceSeconds, $requiredSeconds);

        if ($current || $visited) {
            return new SectorObservation(
                $relative,
                $distance,
                SectorKnowledgeLevel::Detailed,
                1.0,
                ['objects' => $this->detailedObjects($content, $current, $relative, $player->id)],
                $scan,
            );
        }

        if ($residenceSeconds < $requiredSeconds) {
            $retryAfterSeconds = max(0, $requiredSeconds - $residenceSeconds);
            throw new ObservationAccessException(
                'insufficient_scan_data',
                $distance === 1
                    ? 'Insufficient data collection time in the current sector to display a nearby sector scan.'
                    : 'Insufficient data collection time in the current sector to display a distant sector scan.',
                400,
                [
                    'distance' => $distance,
                    'currentSectorResidenceSeconds' => $residenceSeconds,
                    'requiredResidenceSeconds' => $requiredSeconds,
                    'retryAfterSeconds' => $retryAfterSeconds,
                ],
            );
        }

        if ($distance === 1) {
            return new SectorObservation(
                $relative,
                $distance,
                SectorKnowledgeLevel::NeighborScan,
                $this->round(min(
                    $this->scanFloat('neighborConfidenceMax', 0.92),
                    $this->scanFloat('neighborConfidenceBase', 0.62) + ($scan['scanQuality'] * $this->scanFloat('neighborConfidenceQualityScale', 0.3)),
                )),
                ['estimatedObjects' => $this->neighborEstimate($content, $scan['scanQuality'])],
                $scan,
            );
        }

        if ($distance === 2) {
            return new SectorObservation(
                $relative,
                $distance,
                SectorKnowledgeLevel::DistantScan,
                $this->round(min(
                    $this->scanFloat('distantConfidenceMax', 0.58),
                    $this->scanFloat('distantConfidenceBase', 0.24) + ($scan['scanQuality'] * $this->scanFloat('distantConfidenceQualityScale', 0.25)),
                )),
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
            $this->round(min(
                $this->scanFloat('longRangeConfidenceMax', 0.24),
                $this->scanFloat('longRangeConfidenceBase', 0.08) + ($scan['scanQuality'] * $this->scanFloat('longRangeConfidenceQualityScale', 0.12)),
            )),
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
        $seconds = Config::getArray($this->scanConfig, 'residenceSecondsByDistance', [
            '0' => 0,
            '1' => 300,
            '2' => 1800,
            'default' => 7200,
        ]);

        return is_numeric($seconds[(string) $distance] ?? null)
            ? max(0, (int) $seconds[(string) $distance])
            : max(0, (int) ($seconds['default'] ?? 7200));
    }

    private function skipsInitialNeighborDelay(Player $player, int $distance): bool
    {
        return $distance === 1
            && $this->visitedSectors->countVisited($player) === $this->scanInt('initialNeighborDelayBypassVisitedCount', 1);
    }

    private function scanMetadata(int $residenceSeconds, int $requiredSeconds): array
    {
        $quality = $requiredSeconds === 0
            ? 1.0
            : min(1.0, $residenceSeconds / max(1, (int) round($requiredSeconds * $this->scanFloat('qualityRequiredSecondsMultiplier', 4.0))));

        return [
            'currentSectorResidenceSeconds' => $residenceSeconds,
            'requiredResidenceSeconds' => $requiredSeconds,
            'scanQuality' => $this->round($quality),
        ];
    }

    private function detailedObject(UniverseObject $object, SectorCoordinates $sector, array $relativeCoordinates): array
    {
        $data = [
            'id' => $object->getId(),
            'type' => $object->getType()->value,
            'name' => $this->publicObjectName($object, $sector, $relativeCoordinates),
            'estimated' => false,
            'summary' => $this->summary($object),
            'mass' => $object->getMass(),
            'radius' => $object->getRadius(),
            'dangerLevel' => $object instanceof BlackHole ? 'extreme' : ($object instanceof DustCloud ? 'moderate' : 'low'),
        ];
        $data += $this->objectUnitFields($object);

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
            $data['bookmarkTargets'] = $this->bookmarkTargets($object, $sector, $relativeCoordinates);
            $data['minableTargets'] = $this->minableTargets($object, $sector, $relativeCoordinates);
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
                $data['habitabilityScore'] = $object->getHabitabilityScore();
                $data['intelligentLife'] = $object->hasIntelligentLife();
            }
        }

        if ($object instanceof SectorManny) {
            $data['mannyState'] = $object->getState();
            $data['mannyUid'] = $object->getMannyUid();
            $data['cargo'] = $object->getCargo();
            $data['salvageable'] = $object->getState() === SectorManny::STATE_ABANDONED;
        }

        if ($object instanceof SectorDriftingItem) {
            $data['itemType'] = $object->getItemType();
            $data['quantity'] = $object->getQuantity();
            $data['containerSpace'] = $object->getContainerSpace();
            $data['capacityUnit'] = $object->getCapacityUnit();
            $data['salvageable'] = $object->getQuantity() > 0 && $object->getContainerSpace() > 0.0;
        }

        if ($object instanceof SectorDetachedContainer) {
            $data['mode'] = $object->getMode();
            $data['targetObjectId'] = $object->getTargetObjectId();
            $data['capacity'] = $object->getCapacity();
            $data['capacityUnit'] = $object->getCapacityUnit();
            $data['salvageable'] = $object->getMode() === SectorDetachedContainer::MODE_DRIFTING;
        }

        if ($object instanceof DeuteriumRefuelStation) {
            $data['planetId'] = $object->getPlanetId();
            $data['planetName'] = $object->getPlanetName();
            $data['resourceTypes'] = [ResourceComposition::DEUTERIUM];
            $data['createdAt'] = $object->getCreatedAt();
        }

        if ($object instanceof DormantConstruct) {
            $data['apparentOrigin'] = DormantConstruct::APPARENT_ORIGIN;
            $data['activityStatus'] = DormantConstruct::ACTIVITY_STATUS;
            $data['knownFunction'] = DormantConstruct::KNOWN_FUNCTION;
        }

        if ($object->getWaypointBookmarks() !== []) {
            $data['waypointBookmarks'] = $object->getWaypointBookmarks();
        }

        return $data;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function detailedObjects(SectorContent $content, bool $isCurrentSector, array $relativeCoordinates, int $playerId): array
    {
        $objects = [];
        foreach ($content->getObjects() as $object) {
            if ($object instanceof SectorManny && !$isCurrentSector) {
                continue;
            }

            $objects[] = $this->detailedObject($object, $content->getCoordinates(), $relativeCoordinates);
        }
        foreach ($content->getHiddenDetachedContainers() as $container) {
            if (!$container->isDiscoveredByPlayer($playerId)) {
                continue;
            }

            $objects[] = $this->detailedObject($container, $content->getCoordinates(), $relativeCoordinates);
        }

        return $objects;
    }

    /**
     * @param array<UniverseObject> $objects
     * @return array<Planet>
     */
    private function intelligentLifePlanets(array $objects): array
    {
        $planets = [];
        foreach ($objects as $object) {
            if ($object instanceof Planet && $object->hasIntelligentLife()) {
                $planets[] = $object;
                continue;
            }
            if ($object instanceof SolarSystem) {
                foreach ($object->getOrbitalBodies() as $body) {
                    $bodyObject = $body->getObject();
                    if ($bodyObject instanceof Planet && $bodyObject->hasIntelligentLife()) {
                        $planets[] = $bodyObject;
                    }
                }
            }
        }

        return $planets;
    }

    private function abandonOrphanedForgottenMannies(SectorContent $content): SectorContent
    {
        if ($this->mannies === null) {
            return $content;
        }

        $changed = false;
        foreach ($content->getObjects() as $object) {
            if (
                !$object instanceof SectorManny
                || $object->getState() !== SectorManny::STATE_FORGOTTEN
                || $this->mannies->hasExistingOwnerForUid($object->getMannyUid())
            ) {
                continue;
            }

            $changed = $content->replaceObject($object->withState(
                SectorManny::STATE_ABANDONED,
                'Manny abandoned after its owner account disappeared.',
            )) || $changed;
        }

        if ($changed) {
            $this->sectors->saveSector($content);
        }

        return $content;
    }

    private function minableTargets(SolarSystem $system, SectorCoordinates $sector, array $relativeCoordinates): array
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
                'name' => $this->publicObjectName($object, $sector, $relativeCoordinates),
                'mass' => $object->getMass(),
                'massUnit' => $this->massUnit($object),
                'resources' => $resources,
                'resourceTypes' => ResourceComposition::availableTypes($composition),
                'resourceComposition' => $composition,
            ];
            if ($object->getWaypointBookmarks() !== []) {
                $target['waypointBookmarks'] = $object->getWaypointBookmarks();
            }
            if ($object instanceof Asteroid) {
                $target['resourceAmounts'] = $object->getResourceAmounts();
            }
            if ($object instanceof Planet) {
                $target['category'] = $object->getCategory();
                $target['habitabilityScore'] = $object->getHabitabilityScore();
                $target['intelligentLife'] = $object->hasIntelligentLife();
            }
            $targets[] = $target;
        }

        return $targets;
    }

    private function bookmarkTargets(SolarSystem $system, SectorCoordinates $sector, array $relativeCoordinates): array
    {
        $targets = [];
        foreach ($system->getStars() as $star) {
            $targets[] = $this->bookmarkTargetArray($star, $sector, $relativeCoordinates);
        }
        foreach ($system->getOrbitalBodies() as $body) {
            $targets[] = $this->bookmarkTargetArray($body->getObject(), $sector, $relativeCoordinates);
        }

        return $targets;
    }

    private function bookmarkTargetArray(UniverseObject $object, SectorCoordinates $sector, array $relativeCoordinates): array
    {
        $target = [
            'id' => $object->getId(),
            'type' => $object->getType()->value,
            'name' => $this->publicObjectName($object, $sector, $relativeCoordinates),
            'mass' => $object->getMass(),
            'radius' => $object->getRadius(),
        ];
        $target += $this->objectUnitFields($object);
        if ($object instanceof Planet) {
            $target['category'] = $object->getCategory();
            $target['habitabilityScore'] = $object->getHabitabilityScore();
            $target['intelligentLife'] = $object->hasIntelligentLife();
        }
        if ($object->getWaypointBookmarks() !== []) {
            $target['waypointBookmarks'] = $object->getWaypointBookmarks();
        }

        return $target;
    }

    /**
     * @param array{x: int, y: int, z: int} $relativeCoordinates
     */
    private function publicObjectName(UniverseObject $object, SectorCoordinates $sector, array $relativeCoordinates): ?string
    {
        $name = $object->getName();
        if (!$object instanceof Planet || !$object->hasIntelligentLife()) {
            return $name;
        }

        if ($name !== null && !$this->nameContainsSectorCoordinates($name, $sector)) {
            return $name;
        }

        return 'Monde habite du secteur relatif ' . $this->coordinateLabel($relativeCoordinates);
    }

    private function nameContainsSectorCoordinates(string $name, SectorCoordinates $sector): bool
    {
        $absoluteKey = $sector->toKey();
        if (str_contains($name, $absoluteKey)) {
            return true;
        }

        $hyphenatedKey = str_replace(':', '-', $absoluteKey);
        if (str_contains($name, $hyphenatedKey)) {
            return true;
        }

        return str_contains($name, str_replace(':', ' ', $absoluteKey));
    }

    /**
     * @param array{x: int, y: int, z: int} $coordinates
     */
    private function coordinateLabel(array $coordinates): string
    {
        return (string) ($coordinates['x'] ?? 0)
            . ':' . (string) ($coordinates['y'] ?? 0)
            . ':' . (string) ($coordinates['z'] ?? 0);
    }

    private function objectUnitFields(UniverseObject $object): array
    {
        $units = [];
        $massUnit = $this->massUnit($object);
        if ($massUnit !== null) {
            $units['massUnit'] = $massUnit;
        }
        $radiusUnit = $this->radiusUnit($object);
        if ($radiusUnit !== null) {
            $units['radiusUnit'] = $radiusUnit;
        }

        return $units;
    }

    private function massUnit(UniverseObject $object): ?string
    {
        return match (true) {
            $object instanceof Star,
            $object instanceof BlackHole,
            $object instanceof DustCloud => 'solar_mass',
            $object instanceof Planet,
            $object instanceof Asteroid => 'earth_mass',
            default => null,
        };
    }

    private function radiusUnit(UniverseObject $object): ?string
    {
        return match (true) {
            $object instanceof Star => 'solar_radius',
            $object instanceof Planet,
            $object instanceof Asteroid => 'earth_radius',
            $object instanceof BlackHole => 'kilometer',
            $object instanceof SolarSystem,
            $object instanceof DustCloud => 'astronomical_unit',
            default => null,
        };
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
        return $object instanceof Asteroid
            || ($object instanceof Planet && $object->getMass() <= Config::float($this->mannyConfig, 'mineablePlanetMaxMassEarthUnits', self::MANNY_MINEABLE_MAX_MASS));
    }

    private function neighborEstimate(SectorContent $content, float $quality): array
    {
        $planetCount = $this->planetCount($content);
        $blur = max(
            $this->scanInt('neighborPlanetCountBlurMin', 1),
            (int) round($this->scanFloat('neighborPlanetCountBlurBase', 3.0) - ($quality * $this->scanFloat('neighborPlanetCountBlurQualityScale', 2.0))),
        );

        return [
            'star' => $content->hasStar(),
            'planetCountMin' => max(0, $planetCount - $blur),
            'planetCountMax' => $planetCount + $blur,
            'blackHoleProbability' => $content->hasBlackHole()
                ? $this->scanFloat('neighborBlackHoleProbabilityIfPresent', 0.7)
                : $this->scanFloat('neighborBlackHoleProbabilityIfAbsent', 0.03),
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
            $object instanceof SolarSystem => sprintf(
                'Stellar system with %d star(s) and %d orbital body(ies).',
                count($object->getStars()),
                count($object->getOrbitalBodies()),
            ),
            $object instanceof Star => 'Isolated star or stellar remnant.',
            $object instanceof Planet => 'Planetary body detected.',
            $object instanceof Asteroid => 'Wandering asteroid body.',
            $object instanceof DustCloud => 'Diffuse dust cloud with sensor interference.',
            $object instanceof BlackHole => 'Dangerous compact object detected.',
            $object instanceof SectorManny => match ($object->getState()) {
                SectorManny::STATE_FORGOTTEN => 'Manny left behind by a probe.',
                default => 'Abandoned Manny drifting in this sector.',
            },
            $object instanceof SectorDriftingItem => sprintf(
                '%d inventory item(s) drifting in open space.',
                $object->getQuantity(),
            ),
            $object instanceof SectorDetachedContainer => 'Detached storage container detected.',
            $object instanceof DeuteriumRefuelStation => 'Deuterium refuel station detected in orbit.',
            $object instanceof DormantConstruct => 'Dormant non-natural construct detected; function unknown.',
            default => 'Unknown astronomical object.',
        };
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }

    private function scanInt(string $path, int $default): int
    {
        return Config::int($this->scanConfig, $path, $default);
    }

    private function scanFloat(string $path, float $default): float
    {
        return Config::float($this->scanConfig, $path, $default);
    }
}
