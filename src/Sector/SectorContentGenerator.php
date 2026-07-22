<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

use VonNeumannGame\Config\Config;

final class SectorContentGenerator
{
    private const GENERATION_VERSION = 5;
    private const ASTEROID_BELT_CHANCE_PER_PLANET = 0.075;
    private const INTELLIGENT_LIFE_CHANCE = 0.2;
    private const DORMANT_CONSTRUCT_CHANCE_DENOMINATOR = 200;

    public function __construct(private readonly array $config = []) {}

    /**
     * @param array<SectorContent> $knownNeighbors
     */
    public function generate(SectorCoordinates $coordinates, string $worldSeed, array $knownNeighbors = []): SectorContent
    {
        $seed = hash('sha256', $worldSeed . ':sector-content:' . $coordinates->toKey() . ':' . $this->neighborSignature($knownNeighbors));
        $random = new DeterministicRandom($seed);
        $nameSeed = $worldSeed . ':sector-content:' . $coordinates->toKey();
        $category = $random->pickWeighted($this->categoryWeights($knownNeighbors));
        $timestamp = $this->timestampFromSeed($seed);
        $sector = new SectorContent($coordinates, [], $timestamp, $timestamp, $this->int('generationVersion', self::GENERATION_VERSION), 'generated');

        match ($category) {
            'stellar_simple' => $sector->addObject($this->createSolarSystem($random, $coordinates, false, $nameSeed)),
            'stellar_binary' => $sector->addObject($this->createSolarSystem($random, $coordinates, true, $nameSeed)),
            'asteroids' => $this->addWanderingAsteroids($sector, $random, $coordinates, $nameSeed),
            'dust_cloud' => $sector->addObject($this->createDustCloud($random, $coordinates, 0)),
            'dead_star' => $sector->addObject($this->createStar($random, $coordinates, 0, true)),
            'black_hole' => $this->addBlackHoleRegion($sector, $random, $coordinates, $nameSeed),
            default => null,
        };

        if ($random->nextInt(1, max(1, $this->int('dormantConstruct.chanceDenominator', self::DORMANT_CONSTRUCT_CHANCE_DENOMINATOR))) === 1) {
            $sector->addObject($this->createDormantConstruct($coordinates, $worldSeed));
        }

        return $sector;
    }

    /**
     * Base probabilities:
     * empty 72%, simple system 18%, binary 2%, wandering asteroids 4%,
     * dust cloud 2.5%, isolated dead star 1%, black hole 0.5%.
     *
     * Neighbor influence remains intentionally small to avoid mechanical clusters.
     *
     * @param array<SectorContent> $knownNeighbors
     */
    private function categoryWeights(array $knownNeighbors): array
    {
        $weights = $this->weights('categoryWeights', [
            'empty' => 72.0,
            'stellar_simple' => 18.0,
            'stellar_binary' => 2.0,
            'asteroids' => 4.0,
            'dust_cloud' => 2.5,
            'dead_star' => 1.0,
            'black_hole' => 0.5,
        ]);

        $stars = 0;
        $blackHoles = 0;
        $empty = 0;

        foreach ($knownNeighbors as $neighbor) {
            if ($neighbor->hasBlackHole()) {
                $blackHoles++;
            } elseif ($neighbor->hasStar()) {
                $stars++;
            } elseif ($neighbor->getObjects() === []) {
                $empty++;
            }
        }

        $weights['stellar_simple'] += min(
            $this->float('neighborInfluence.stellarSimpleBonusMax', 4.0),
            $stars * $this->float('neighborInfluence.stellarSimpleBonusPerStar', 0.8),
        );
        $weights['stellar_binary'] += min(
            $this->float('neighborInfluence.stellarBinaryBonusMax', 0.8),
            $stars * $this->float('neighborInfluence.stellarBinaryBonusPerStar', 0.12),
        );
        $weights['empty'] += min(
            $this->float('neighborInfluence.emptyBonusMax', 5.0),
            $empty * $this->float('neighborInfluence.emptyBonusPerEmptyNeighbor', 0.6),
        );

        if ($blackHoles > 0) {
            $weights['dust_cloud'] += min(
                $this->float('neighborInfluence.dustCloudBonusMax', 3.0),
                $blackHoles * $this->float('neighborInfluence.dustCloudBonusPerBlackHole', 1.0),
            );
            $weights['asteroids'] += min(
                $this->float('neighborInfluence.asteroidBonusMax', 3.0),
                $blackHoles * $this->float('neighborInfluence.asteroidBonusPerBlackHole', 0.9),
            );
            $weights['black_hole'] += min(
                $this->float('neighborInfluence.blackHoleBonusMax', 1.0),
                $blackHoles * $this->float('neighborInfluence.blackHoleBonusPerBlackHole', 0.2),
            );
            $weights['stellar_simple'] *= $this->float('neighborInfluence.stellarSimpleMultiplierNearBlackHole', 0.85);
            $weights['stellar_binary'] *= $this->float('neighborInfluence.stellarBinaryMultiplierNearBlackHole', 0.75);
        }

        return $weights;
    }

    private function createSolarSystem(DeterministicRandom $random, SectorCoordinates $coordinates, bool $binary, string $nameSeed): SolarSystem
    {
        $primary = $this->createStar($random, $coordinates, 0, false);
        $secondary = $binary ? $this->createStar($random, $coordinates, 1, false) : null;
        $planetCount = $random->nextInt(
            $this->int('solarSystem.planetCountMin', 0),
            $this->int('solarSystem.planetCountMax', 12),
        );
        $orbitalBodies = [];
        $orbitIndex = 0;

        for ($i = 0; $i < $planetCount; $i++) {
            $axis = $this->round(
                $this->float('solarSystem.planetAxisBase', 0.15)
                + ($i * $random->nextFloatBetween(
                    $this->float('solarSystem.planetAxisStepMin', 0.28),
                    $this->float('solarSystem.planetAxisStepMax', 1.3),
                ))
                + $random->nextFloatBetween(
                    $this->float('solarSystem.planetAxisJitterMin', 0.0),
                    $this->float('solarSystem.planetAxisJitterMax', 0.4),
                ),
            );
            $planet = $this->createPlanet($random, $coordinates, $i, $primary->getSpectralType(), $axis);
            $orbitalBodies[] = new OrbitingBody($planet, $this->createOrbit($random, $axis, $primary->getMass()));
            $orbitIndex++;
        }

        $asteroidBelts = $this->asteroidBeltCount($random, $planetCount);
        for ($i = 0; $i < $asteroidBelts; $i++) {
            $axis = $this->round($random->nextFloatBetween(
                $this->float('solarSystem.asteroidBeltAxisMin', 1.8),
                $this->float('solarSystem.asteroidBeltAxisMax', 12.0),
            ));
            $orbitalBodies[] = new OrbitingBody(
                $this->createAsteroid($random, $coordinates, $orbitIndex, $nameSeed),
                $this->createOrbit($random, $axis, $primary->getMass()),
            );
            $orbitIndex++;
        }

        $radius = $orbitalBodies === [] ? $this->float('solarSystem.emptySystemRadius', 1.0) : max(array_map(
            static fn(OrbitingBody $body): float => $body->getOrbit()->toArray()['semiMajorAxisAU'],
            $orbitalBodies,
        ));
        $mass = $primary->getMass() + ($secondary?->getMass() ?? 0.0);
        foreach ($orbitalBodies as $body) {
            $mass += $body->getObject()->getMass();
        }

        return new SolarSystem(
            $this->objectId($coordinates, 'system', 0),
            'System ' . substr(hash('sha256', $coordinates->toKey()), 0, 6),
            $primary,
            $secondary,
            $orbitalBodies,
            $this->round($mass),
            $this->round($radius),
            $binary ? 'Rare binary stellar system.' : 'Stellar system with stable orbital architecture.',
        );
    }

    private function asteroidBeltCount(DeterministicRandom $random, int $planetCount): int
    {
        $count = $random->nextInt(
            $this->int('solarSystem.asteroidBeltBaseMin', 0),
            $this->int('solarSystem.asteroidBeltBaseMax', 2),
        );
        if ($planetCount <= 0) {
            return $count;
        }

        $planetaryChance = min(
            $this->float('solarSystem.asteroidBeltChanceMax', 0.95),
            $planetCount * $this->float('solarSystem.asteroidBeltChancePerPlanet', self::ASTEROID_BELT_CHANCE_PER_PLANET),
        );
        if ($count === 0 && $random->nextFloat() < $planetaryChance) {
            $count = 1;
        }
        if ($random->nextFloat() < max(0.0, $planetaryChance - $this->float('solarSystem.asteroidBeltSecondChanceOffset', 0.35))) {
            $count++;
        }
        if ($random->nextFloat() < max(0.0, $planetaryChance - $this->float('solarSystem.asteroidBeltThirdChanceOffset', 0.65))) {
            $count++;
        }

        return min($this->int('solarSystem.asteroidBeltMax', 4), $count);
    }

    private function createStar(DeterministicRandom $random, SectorCoordinates $coordinates, int $index, bool $deadOnly): Star
    {
        $spectralType = $deadOnly
            ? $random->pickWeighted($this->weights('stars.deadWeights', ['white_dwarf' => 70, 'neutron_star' => 25, 'red_dwarf_remnant' => 5]))
            : $random->pickWeighted($this->weights('stars.liveWeights', ['O' => 0.04, 'B' => 0.2, 'A' => 0.7, 'F' => 3, 'G' => 7, 'K' => 14, 'M' => 75]));

        $rangeKey = $spectralType === 'white_dwarf' || $spectralType === 'red_dwarf_remnant'
            ? 'default_dead'
            : $spectralType;
        [$massMin, $massMax] = $this->floatRange('stars.ranges.' . $rangeKey . '.mass', ...$this->defaultStarRange($rangeKey, 'mass'));
        [$radiusMin, $radiusMax] = $this->floatRange('stars.ranges.' . $rangeKey . '.radius', ...$this->defaultStarRange($rangeKey, 'radius'));
        [$luminosityMin, $luminosityMax] = $this->floatRange('stars.ranges.' . $rangeKey . '.luminosity', ...$this->defaultStarRange($rangeKey, 'luminosity'));
        [$temperatureMin, $temperatureMax] = $this->intRange('stars.ranges.' . $rangeKey . '.temperature', ...$this->defaultStarIntRange($rangeKey, 'temperature'));

        $mass = $random->nextFloatBetween($massMin, $massMax);
        $radius = $random->nextFloatBetween($radiusMin, $radiusMax);
        $luminosity = $random->nextFloatBetween($luminosityMin, $luminosityMax);
        $temperature = $random->nextInt($temperatureMin, $temperatureMax);

        return new Star(
            $this->objectId($coordinates, 'star', $index),
            null,
            $spectralType,
            $this->round($luminosity),
            $temperature,
            $this->round($mass),
            $this->round($radius),
            $deadOnly ? 'Compact stellar remnant.' : 'Main stellar mass of the local system.',
        );
    }

    private function createPlanet(DeterministicRandom $random, SectorCoordinates $coordinates, int $index, string $spectralType, float $axis): Planet
    {
        $smallStar = in_array($spectralType, Config::getArray($this->config, 'planets.smallStarSpectralTypes', ['K', 'M']), true);
        $category = $random->pickWeighted($smallStar
            ? $this->weights('planets.smallStarCategoryWeights', ['rocky' => 42, 'frozen' => 18, 'ocean' => 4, 'lava' => 6, 'dwarf' => 18, 'gas_giant' => 6, 'ice_giant' => 6])
            : $this->weights('planets.largeStarCategoryWeights', ['rocky' => 28, 'frozen' => 14, 'ocean' => 3, 'lava' => 8, 'dwarf' => 12, 'gas_giant' => 22, 'ice_giant' => 13]));

        [$massMin, $massMax] = $this->floatRange('planets.ranges.' . $category . '.mass', ...$this->defaultPlanetRange($category, 'mass'));
        [$radiusMin, $radiusMax] = $this->floatRange('planets.ranges.' . $category . '.radius', ...$this->defaultPlanetRange($category, 'radius'));
        $mass = $random->nextFloatBetween($massMin, $massMax);
        $radius = $random->nextFloatBetween($radiusMin, $radiusMax);

        $atmosphere = !in_array($category, Config::getArray($this->config, 'planets.atmosphereBlockedCategories', ['dwarf', 'lava']), true)
            && $random->nextFloat() < $this->float('planets.atmosphereChance', 0.75);
        $habitableBand = $axis > $this->float('planets.habitableAxisMin', 0.45)
            && $axis < $this->float('planets.habitableAxisMax', 2.2)
            && in_array($category, Config::getArray($this->config, 'planets.habitableCategories', ['rocky', 'ocean']), true)
            && $atmosphere;
        [$habitableMin, $habitableMax] = $this->floatRange('planets.habitableRange', 0.35, 0.92);
        [$backgroundMin, $backgroundMax] = $this->floatRange('planets.backgroundHabitabilityRange', 0.0, 0.18);
        $habitability = $habitableBand && $random->nextFloat() < $this->float('planets.habitableChance', 0.08)
            ? $random->nextFloatBetween($habitableMin, $habitableMax)
            : $random->nextFloatBetween($backgroundMin, $backgroundMax);
        $habitability = $this->round(min(1.0, $habitability));
        $intelligentLife = $habitability > $this->float('planets.intelligentLifeThreshold', 0.35)
            && $random->nextFloat() < $this->float('planets.intelligentLifeChance', self::INTELLIGENT_LIFE_CHANCE);

        return new Planet(
            $this->objectId($coordinates, 'planet', $index),
            null,
            $category,
            $this->round($mass),
            $this->round($radius),
            $atmosphere,
            $habitability,
            $this->resourceHints($random, $category),
            intelligentLife: $intelligentLife,
            description: 'Planetary body classified as ' . $category . '.',
        );
    }

    private function createAsteroid(DeterministicRandom $random, SectorCoordinates $coordinates, int $index, string $nameSeed): Asteroid
    {
        $composition = $random->pickWeighted($this->weights('asteroids.compositionWeights', ['iron' => 25, 'silicate' => 35, 'carbonaceous' => 22, 'ice' => 12, 'rare_metals' => 6]));
        $resourcesByComposition = Config::getArray($this->config, 'asteroids.resourcesByComposition', []);
        $resources = is_array($resourcesByComposition[$composition] ?? null)
            ? $resourcesByComposition[$composition]
            : match ($composition) {
                'iron' => ['iron', 'nickel'],
                'silicate' => ['silicates', 'magnesium'],
                'carbonaceous' => ['carbon', 'organics', 'ice_trace'],
                'ice' => ['water_ice', 'deuterium_trace', 'volatiles'],
                default => ['rare_metals', 'platinum_group'],
            };
        [$massMin, $massMax] = $this->floatRange('asteroids.massRange', 0.000001, 0.02);
        [$radiusMin, $radiusMax] = $this->floatRange('asteroids.radiusRange', 0.001, 0.2);

        $objectId = $this->objectId($coordinates, 'asteroid', $index);
        $asteroid = new Asteroid(
            $objectId,
            null,
            $composition,
            $resources,
            $random->pickWeighted($this->weights('asteroids.sizeWeights', ['small' => 55, 'medium' => 35, 'large' => 10])),
            $this->round($random->nextFloatBetween($massMin, $massMax)),
            $this->round($random->nextFloatBetween($radiusMin, $radiusMax)),
            'Uncharted asteroid body.',
            resourceContainersPerEarthMass: $this->float('resourceContainersPerEarthMass', 1000000.0),
        );

        return $asteroid->withGeneratedName($nameSeed . ':' . $objectId);
    }

    private function createDustCloud(DeterministicRandom $random, SectorCoordinates $coordinates, int $index): DustCloud
    {
        [$densityMin, $densityMax] = $this->floatRange('dustClouds.densityRange', 0.05, 0.9);
        [$ionizationMin, $ionizationMax] = $this->floatRange('dustClouds.ionizationRange', 0.05, 0.75);
        [$occlusionMin, $occlusionMax] = $this->floatRange('dustClouds.sensorOcclusionRange', 0.1, 0.95);
        [$massMin, $massMax] = $this->floatRange('dustClouds.massRange', 0.001, 2.5);
        [$radiusMin, $radiusMax] = $this->floatRange('dustClouds.radiusRange', 0.5, 12.0);

        return new DustCloud(
            $this->objectId($coordinates, 'dust', $index),
            null,
            $this->round($random->nextFloatBetween($densityMin, $densityMax)),
            $random->pickWeighted($this->weights('dustClouds.compositionWeights', ['hydrogen' => 45, 'silicate_dust' => 25, 'ice_particles' => 20, 'metallic_dust' => 10])),
            $this->round($random->nextFloatBetween($ionizationMin, $ionizationMax)),
            $this->round($random->nextFloatBetween($occlusionMin, $occlusionMax)),
            $this->round($random->nextFloatBetween($massMin, $massMax)),
            $this->round($random->nextFloatBetween($radiusMin, $radiusMax)),
            'Diffuse cloud affecting sensors and navigation.',
        );
    }

    private function addWanderingAsteroids(SectorContent $sector, DeterministicRandom $random, SectorCoordinates $coordinates, string $nameSeed): void
    {
        $count = $random->nextInt(
            $this->int('asteroids.wanderingCountMin', 1),
            $this->int('asteroids.wanderingCountMax', 5),
        );
        for ($i = 0; $i < $count; $i++) {
            $sector->addObject($this->createAsteroid($random, $coordinates, $i, $nameSeed));
        }
    }

    private function addBlackHoleRegion(SectorContent $sector, DeterministicRandom $random, SectorCoordinates $coordinates, string $nameSeed): void
    {
        [$massMin, $massMax] = $this->floatRange('blackHoles.massRange', 3.0, 30.0);
        [$dangerMin, $dangerMax] = $this->floatRange('blackHoles.dangerRadiusRange', 0.8, 7.5);
        $mass = $random->nextFloatBetween($massMin, $massMax);
        $radius = $mass * $this->float('blackHoles.schwarzschildRadiusMultiplier', 2.95);
        $sector->addObject(new BlackHole(
            $this->objectId($coordinates, 'black-hole', 0),
            null,
            $this->round($mass),
            $this->round($radius),
            $random->nextFloat() < $this->float('blackHoles.accretionDiskChance', 0.42),
            $this->round($random->nextFloatBetween($dangerMin, $dangerMax)),
            'Dangerous compact object dominating the sector.',
        ));

        if ($random->nextFloat() < $this->float('blackHoles.wanderingAsteroidsChance', 0.55)) {
            $this->addWanderingAsteroids($sector, $random, $coordinates, $nameSeed);
        }
        if ($random->nextFloat() < $this->float('blackHoles.dustCloudChance', 0.45)) {
            $sector->addObject($this->createDustCloud($random, $coordinates, 0));
        }
    }

    private function createDormantConstruct(SectorCoordinates $coordinates, string $worldSeed): DormantConstruct
    {
        return new DormantConstruct(DormantConstruct::objectIdForSector($coordinates, $worldSeed));
    }

    private function createOrbit(DeterministicRandom $random, float $axis, float $starMass): OrbitDescriptor
    {
        $period = $this->float('orbits.periodDaysPerYear', 365.25)
            * sqrt(($axis ** 3) / max($this->float('orbits.minimumStarMassForPeriod', 0.05), $starMass));
        [$eccentricityMin, $eccentricityMax] = $this->floatRange('orbits.eccentricityRange', 0.0, 0.25);
        [$inclinationMin, $inclinationMax] = $this->floatRange('orbits.inclinationRange', 0.0, 12.0);
        [$phaseMin, $phaseMax] = $this->floatRange('orbits.phaseRange', 0.0, 360.0);

        return new OrbitDescriptor(
            $this->round($axis),
            $this->round($random->nextFloatBetween($eccentricityMin, $eccentricityMax)),
            $this->round($random->nextFloatBetween($inclinationMin, $inclinationMax)),
            $this->round($period),
            $this->round($random->nextFloatBetween($phaseMin, $phaseMax)),
        );
    }

    private function resourceHints(DeterministicRandom $random, string $category): array
    {
        $base = match ($category) {
            'gas_giant' => ['hydrogen', 'helium', 'exotic_gases'],
            'ice_giant', 'frozen' => ['water_ice', 'ammonia', 'volatiles'],
            'ocean' => ['water', 'salts', 'organics_trace'],
            'lava' => ['silicates', 'thermal_energy', 'heavy_metals_trace'],
            'dwarf' => ['ice_trace', 'silicates'],
            default => ['iron', 'silicates', 'carbon'],
        };

        if ($random->nextFloat() < $this->float('planets.rareMetalsTraceChance', 0.22)) {
            $base[] = 'rare_metals_trace';
        }

        return $base;
    }

    /**
     * @param array<SectorContent> $knownNeighbors
     */
    private function neighborSignature(array $knownNeighbors): string
    {
        $parts = [];
        foreach ($knownNeighbors as $neighbor) {
            $parts[] = $neighbor->getCoordinates()->toKey() . '=' . hash('sha256', json_encode($neighbor->toArray(), JSON_THROW_ON_ERROR));
        }
        sort($parts);

        return implode('|', $parts);
    }

    private function timestampFromSeed(string $seed): string
    {
        $rangeSeconds = max(1, $this->int('generatedTimestamp.rangeSeconds', 31536000));
        $seconds = hexdec(substr($seed, 0, 8)) % $rangeSeconds;
        return gmdate('c', $this->int('generatedTimestamp.epochSeconds', 1704067200) + $seconds);
    }

    private function objectId(SectorCoordinates $coordinates, string $kind, int $index): string
    {
        return substr(hash('sha256', $coordinates->toKey() . ':' . $kind . ':' . $index), 0, 20);
    }

    private function round(float $value): float
    {
        return round($value, max(0, $this->int('roundPrecision', 6)));
    }

    private function int(string $path, int $default): int
    {
        return Config::int($this->config, $path, $default);
    }

    private function float(string $path, float $default): float
    {
        return Config::float($this->config, $path, $default);
    }

    /**
     * @param array<string, float|int> $default
     * @return array<string, float>
     */
    private function weights(string $path, array $default): array
    {
        $values = Config::getArray($this->config, $path, $default);
        $weights = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !is_numeric($value)) {
                continue;
            }
            $weights[$key] = max(0.0, (float) $value);
        }

        return $weights !== [] ? $weights : array_map('floatval', $default);
    }

    /**
     * @return array{0:float, 1:float}
     */
    private function floatRange(string $path, float $defaultMin, float $defaultMax): array
    {
        $range = Config::getArray($this->config, $path, [$defaultMin, $defaultMax]);
        $min = is_numeric($range[0] ?? null) ? (float) $range[0] : $defaultMin;
        $max = is_numeric($range[1] ?? null) ? (float) $range[1] : $defaultMax;

        return $min <= $max ? [$min, $max] : [$max, $min];
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function intRange(string $path, int $defaultMin, int $defaultMax): array
    {
        $range = Config::getArray($this->config, $path, [$defaultMin, $defaultMax]);
        $min = is_numeric($range[0] ?? null) ? (int) $range[0] : $defaultMin;
        $max = is_numeric($range[1] ?? null) ? (int) $range[1] : $defaultMax;

        return $min <= $max ? [$min, $max] : [$max, $min];
    }

    /**
     * @return array{0:float, 1:float}
     */
    private function defaultStarRange(string $rangeKey, string $field): array
    {
        $defaults = [
            'O' => ['mass' => [16.0, 45.0], 'radius' => [6.0, 15.0], 'luminosity' => [30000.0, 700000.0]],
            'B' => ['mass' => [2.1, 16.0], 'radius' => [1.8, 6.0], 'luminosity' => [25.0, 30000.0]],
            'A' => ['mass' => [1.4, 2.1], 'radius' => [1.4, 2.1], 'luminosity' => [5.0, 25.0]],
            'F' => ['mass' => [1.04, 1.4], 'radius' => [1.1, 1.4], 'luminosity' => [1.5, 5.0]],
            'G' => ['mass' => [0.8, 1.04], 'radius' => [0.9, 1.1], 'luminosity' => [0.6, 1.5]],
            'K' => ['mass' => [0.45, 0.8], 'radius' => [0.65, 0.9], 'luminosity' => [0.08, 0.6]],
            'M' => ['mass' => [0.08, 0.45], 'radius' => [0.1, 0.65], 'luminosity' => [0.0005, 0.08]],
            'neutron_star' => ['mass' => [1.1, 2.3], 'radius' => [0.000015, 0.000025], 'luminosity' => [0.001, 0.05]],
            'default_dead' => ['mass' => [0.2, 1.3], 'radius' => [0.008, 0.02], 'luminosity' => [0.0001, 0.1]],
        ];

        return $defaults[$rangeKey][$field] ?? $defaults['default_dead'][$field];
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function defaultStarIntRange(string $rangeKey, string $field): array
    {
        $defaults = [
            'O' => ['temperature' => [30000, 52000]],
            'B' => ['temperature' => [10000, 30000]],
            'A' => ['temperature' => [7500, 10000]],
            'F' => ['temperature' => [6000, 7500]],
            'G' => ['temperature' => [5200, 6000]],
            'K' => ['temperature' => [3700, 5200]],
            'M' => ['temperature' => [2400, 3700]],
            'neutron_star' => ['temperature' => [250000, 900000]],
            'default_dead' => ['temperature' => [6000, 100000]],
        ];

        return $defaults[$rangeKey][$field] ?? $defaults['default_dead'][$field];
    }

    /**
     * @return array{0:float, 1:float}
     */
    private function defaultPlanetRange(string $category, string $field): array
    {
        $defaults = [
            'gas_giant' => ['mass' => [20.0, 320.0], 'radius' => [3.5, 11.5]],
            'ice_giant' => ['mass' => [8.0, 25.0], 'radius' => [2.5, 4.5]],
            'dwarf' => ['mass' => [0.01, 0.2], 'radius' => [0.15, 0.55]],
            'default' => ['mass' => [0.2, 6.0], 'radius' => [0.45, 1.8]],
        ];

        return $defaults[$category][$field] ?? $defaults['default'][$field];
    }
}
