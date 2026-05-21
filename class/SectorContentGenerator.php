<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class SectorContentGenerator
{
    private const GENERATION_VERSION = 1;

    /**
     * @param array<SectorContent> $knownNeighbors
     */
    public function generate(SectorCoordinates $coordinates, string $worldSeed, array $knownNeighbors = []): SectorContent
    {
        $seed = hash('sha256', $worldSeed . ':sector-content:' . $coordinates->toKey() . ':' . $this->neighborSignature($knownNeighbors));
        $random = new DeterministicRandom($seed);
        $category = $random->pickWeighted($this->categoryWeights($knownNeighbors));
        $timestamp = $this->timestampFromSeed($seed);
        $sector = new SectorContent($coordinates, [], $timestamp, $timestamp, self::GENERATION_VERSION, 'generated');

        match ($category) {
            'stellar_simple' => $sector->addObject($this->createSolarSystem($random, $coordinates, false)),
            'stellar_binary' => $sector->addObject($this->createSolarSystem($random, $coordinates, true)),
            'asteroids' => $this->addWanderingAsteroids($sector, $random, $coordinates),
            'dust_cloud' => $sector->addObject($this->createDustCloud($random, $coordinates, 0)),
            'dead_star' => $sector->addObject($this->createStar($random, $coordinates, 0, true)),
            'black_hole' => $this->addBlackHoleRegion($sector, $random, $coordinates),
            default => null,
        };

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
        $weights = [
            'empty' => 72.0,
            'stellar_simple' => 18.0,
            'stellar_binary' => 2.0,
            'asteroids' => 4.0,
            'dust_cloud' => 2.5,
            'dead_star' => 1.0,
            'black_hole' => 0.5,
        ];

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

        $weights['stellar_simple'] += min(4.0, $stars * 0.8);
        $weights['stellar_binary'] += min(0.8, $stars * 0.12);
        $weights['empty'] += min(5.0, $empty * 0.6);

        if ($blackHoles > 0) {
            $weights['dust_cloud'] += min(3.0, $blackHoles * 1.0);
            $weights['asteroids'] += min(3.0, $blackHoles * 0.9);
            $weights['black_hole'] += min(1.0, $blackHoles * 0.2);
            $weights['stellar_simple'] *= 0.85;
            $weights['stellar_binary'] *= 0.75;
        }

        return $weights;
    }

    private function createSolarSystem(DeterministicRandom $random, SectorCoordinates $coordinates, bool $binary): SolarSystem
    {
        $primary = $this->createStar($random, $coordinates, 0, false);
        $secondary = $binary ? $this->createStar($random, $coordinates, 1, false) : null;
        $planetCount = $random->nextInt(0, 12);
        $orbitalBodies = [];
        $orbitIndex = 0;

        for ($i = 0; $i < $planetCount; $i++) {
            $axis = $this->round(0.15 + ($i * $random->nextFloatBetween(0.28, 1.3)) + $random->nextFloatBetween(0.0, 0.4));
            $planet = $this->createPlanet($random, $coordinates, $i, $primary->getSpectralType(), $axis);
            $orbitalBodies[] = new OrbitingBody($planet, $this->createOrbit($random, $axis, $primary->getMass()));
            $orbitIndex++;
        }

        $asteroidBelts = $random->nextInt(0, 2);
        for ($i = 0; $i < $asteroidBelts; $i++) {
            $axis = $this->round($random->nextFloatBetween(1.8, 12.0));
            $orbitalBodies[] = new OrbitingBody(
                $this->createAsteroid($random, $coordinates, $orbitIndex),
                $this->createOrbit($random, $axis, $primary->getMass()),
            );
            $orbitIndex++;
        }

        $radius = $orbitalBodies === [] ? 1.0 : max(array_map(
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

    private function createStar(DeterministicRandom $random, SectorCoordinates $coordinates, int $index, bool $deadOnly): Star
    {
        $spectralType = $deadOnly
            ? $random->pickWeighted(['white_dwarf' => 70, 'neutron_star' => 25, 'red_dwarf_remnant' => 5])
            : $random->pickWeighted(['O' => 0.04, 'B' => 0.2, 'A' => 0.7, 'F' => 3, 'G' => 7, 'K' => 14, 'M' => 75]);

        [$mass, $radius, $luminosity, $temperature] = match ($spectralType) {
            'O' => [$random->nextFloatBetween(16, 45), $random->nextFloatBetween(6, 15), $random->nextFloatBetween(30000, 700000), $random->nextInt(30000, 52000)],
            'B' => [$random->nextFloatBetween(2.1, 16), $random->nextFloatBetween(1.8, 6), $random->nextFloatBetween(25, 30000), $random->nextInt(10000, 30000)],
            'A' => [$random->nextFloatBetween(1.4, 2.1), $random->nextFloatBetween(1.4, 2.1), $random->nextFloatBetween(5, 25), $random->nextInt(7500, 10000)],
            'F' => [$random->nextFloatBetween(1.04, 1.4), $random->nextFloatBetween(1.1, 1.4), $random->nextFloatBetween(1.5, 5), $random->nextInt(6000, 7500)],
            'G' => [$random->nextFloatBetween(0.8, 1.04), $random->nextFloatBetween(0.9, 1.1), $random->nextFloatBetween(0.6, 1.5), $random->nextInt(5200, 6000)],
            'K' => [$random->nextFloatBetween(0.45, 0.8), $random->nextFloatBetween(0.65, 0.9), $random->nextFloatBetween(0.08, 0.6), $random->nextInt(3700, 5200)],
            'M' => [$random->nextFloatBetween(0.08, 0.45), $random->nextFloatBetween(0.1, 0.65), $random->nextFloatBetween(0.0005, 0.08), $random->nextInt(2400, 3700)],
            'neutron_star' => [$random->nextFloatBetween(1.1, 2.3), $random->nextFloatBetween(0.000015, 0.000025), $random->nextFloatBetween(0.001, 0.05), $random->nextInt(250000, 900000)],
            default => [$random->nextFloatBetween(0.2, 1.3), $random->nextFloatBetween(0.008, 0.02), $random->nextFloatBetween(0.0001, 0.1), $random->nextInt(6000, 100000)],
        };

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
        $smallStar = in_array($spectralType, ['K', 'M'], true);
        $category = $random->pickWeighted($smallStar
            ? ['rocky' => 42, 'frozen' => 18, 'ocean' => 4, 'lava' => 6, 'dwarf' => 18, 'gas_giant' => 6, 'ice_giant' => 6]
            : ['rocky' => 28, 'frozen' => 14, 'ocean' => 3, 'lava' => 8, 'dwarf' => 12, 'gas_giant' => 22, 'ice_giant' => 13]);

        [$mass, $radius] = match ($category) {
            'gas_giant' => [$random->nextFloatBetween(20, 320), $random->nextFloatBetween(3.5, 11.5)],
            'ice_giant' => [$random->nextFloatBetween(8, 25), $random->nextFloatBetween(2.5, 4.5)],
            'dwarf' => [$random->nextFloatBetween(0.01, 0.2), $random->nextFloatBetween(0.15, 0.55)],
            default => [$random->nextFloatBetween(0.2, 6.0), $random->nextFloatBetween(0.45, 1.8)],
        };

        $atmosphere = !in_array($category, ['dwarf', 'lava'], true) && $random->nextFloat() > 0.25;
        $habitableBand = $axis > 0.45 && $axis < 2.2 && in_array($category, ['rocky', 'ocean'], true) && $atmosphere;
        $habitability = $habitableBand && $random->nextFloat() < 0.08 ? $random->nextFloatBetween(0.35, 0.92) : $random->nextFloatBetween(0.0, 0.18);

        return new Planet(
            $this->objectId($coordinates, 'planet', $index),
            null,
            $category,
            $this->round($mass),
            $this->round($radius),
            $atmosphere,
            $this->round(min(1.0, $habitability)),
            $this->resourceHints($random, $category),
            'Planetary body classified as ' . $category . '.',
        );
    }

    private function createAsteroid(DeterministicRandom $random, SectorCoordinates $coordinates, int $index): Asteroid
    {
        $composition = $random->pickWeighted(['iron' => 25, 'silicate' => 35, 'carbonaceous' => 22, 'ice' => 12, 'rare_metals' => 6]);
        $resources = match ($composition) {
            'iron' => ['iron', 'nickel'],
            'silicate' => ['silicates', 'magnesium'],
            'carbonaceous' => ['carbon', 'water_trace'],
            'ice' => ['water_ice', 'volatiles'],
            default => ['rare_metals', 'platinum_group'],
        };

        return new Asteroid(
            $this->objectId($coordinates, 'asteroid', $index),
            null,
            $composition,
            $resources,
            $random->pickWeighted(['small' => 55, 'medium' => 35, 'large' => 10]),
            $this->round($random->nextFloatBetween(0.000001, 0.02)),
            $this->round($random->nextFloatBetween(0.001, 0.2)),
            'Uncharted asteroid body.',
        );
    }

    private function createDustCloud(DeterministicRandom $random, SectorCoordinates $coordinates, int $index): DustCloud
    {
        return new DustCloud(
            $this->objectId($coordinates, 'dust', $index),
            null,
            $this->round($random->nextFloatBetween(0.05, 0.9)),
            $random->pickWeighted(['hydrogen' => 45, 'silicate_dust' => 25, 'ice_particles' => 20, 'metallic_dust' => 10]),
            $this->round($random->nextFloatBetween(0.05, 0.75)),
            $this->round($random->nextFloatBetween(0.1, 0.95)),
            $this->round($random->nextFloatBetween(0.001, 2.5)),
            $this->round($random->nextFloatBetween(0.5, 12.0)),
            'Diffuse cloud affecting sensors and navigation.',
        );
    }

    private function addWanderingAsteroids(SectorContent $sector, DeterministicRandom $random, SectorCoordinates $coordinates): void
    {
        $count = $random->nextInt(1, 5);
        for ($i = 0; $i < $count; $i++) {
            $sector->addObject($this->createAsteroid($random, $coordinates, $i));
        }
    }

    private function addBlackHoleRegion(SectorContent $sector, DeterministicRandom $random, SectorCoordinates $coordinates): void
    {
        $mass = $random->nextFloatBetween(3.0, 30.0);
        $radius = $mass * 2.95;
        $sector->addObject(new BlackHole(
            $this->objectId($coordinates, 'black-hole', 0),
            null,
            $this->round($mass),
            $this->round($radius),
            $random->nextFloat() < 0.42,
            $this->round($random->nextFloatBetween(0.8, 7.5)),
            'Dangerous compact object dominating the sector.',
        ));

        if ($random->nextFloat() < 0.55) {
            $this->addWanderingAsteroids($sector, $random, $coordinates);
        }
        if ($random->nextFloat() < 0.45) {
            $sector->addObject($this->createDustCloud($random, $coordinates, 0));
        }
    }

    private function createOrbit(DeterministicRandom $random, float $axis, float $starMass): OrbitDescriptor
    {
        $period = 365.25 * sqrt(($axis ** 3) / max(0.05, $starMass));

        return new OrbitDescriptor(
            $this->round($axis),
            $this->round($random->nextFloatBetween(0.0, 0.25)),
            $this->round($random->nextFloatBetween(0.0, 12.0)),
            $this->round($period),
            $this->round($random->nextFloatBetween(0.0, 360.0)),
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

        if ($random->nextFloat() < 0.22) {
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
        $seconds = hexdec(substr($seed, 0, 8)) % 31536000;
        return gmdate('c', 1704067200 + $seconds);
    }

    private function objectId(SectorCoordinates $coordinates, string $kind, int $index): string
    {
        return substr(hash('sha256', $coordinates->toKey() . ':' . $kind . ':' . $index), 0, 20);
    }

    private function round(float $value): float
    {
        return round($value, 6);
    }
}
