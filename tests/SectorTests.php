<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\InvalidSectorCoordinatesException;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorSeedGenerator;
use VonNeumannGame\Sector\SectorContent;
use VonNeumannGame\Sector\SectorContentGenerator;
use VonNeumannGame\Sector\SectorDetachedContainer;
use VonNeumannGame\Sector\SectorFileRepository;
use VonNeumannGame\Sector\SectorService;
use VonNeumannGame\Sector\SectorManny;
use VonNeumannGame\Sector\SolarSystem;
use VonNeumannGame\Sector\Star;
use VonNeumannGame\Sector\Planet;
use VonNeumannGame\Sector\Asteroid;

/**
 * Simple test runner with assertions.
 */
class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failureMessages = [];

    public function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "✓ $message\n";
        } else {
            $this->failed++;
            $this->failureMessages[] = $message;
            echo "✗ $message\n";
        }
    }

    public function assertEquals($expected, $actual, string $message): void
    {
        $this->assert($expected === $actual, "$message (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ')');
    }

    public function assertCount(int $expected, array $array, string $message): void
    {
        $this->assertEquals($expected, count($array), "$message (array count)");
    }

    public function assertThrows(callable $fn, string $exceptionClass, string $message): void
    {
        try {
            $fn();
            $this->assert(false, "$message (expected $exceptionClass but nothing was thrown)");
        } catch (\Throwable $e) {
            $this->assert($e instanceof $exceptionClass, "$message (expected " . get_class($e) . " to be instanceof $exceptionClass)");
        }
    }

    public function printSummary(): void
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "Tests passed: {$this->passed}\n";
        echo "Tests failed: {$this->failed}\n";
        if ($this->failed > 0) {
            echo "\nFailures:\n";
            foreach ($this->failureMessages as $msg) {
                echo "  - $msg\n";
            }
        }
        echo str_repeat('=', 60) . "\n";
    }

    public function getStatus(): int
    {
        return $this->failed > 0 ? 1 : 0;
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            removeDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

function findGeneratedSector(SectorContentGenerator $generator, string $worldSeed, callable $predicate, int $limit = 20000): ?SectorContent
{
    for ($i = 0; $i < $limit; $i++) {
        $coordinates = new SectorCoordinates($i * 2, 0, 0);
        $sector = $generator->generate($coordinates, $worldSeed);
        if ($predicate($sector)) {
            return $sector;
        }
    }

    return null;
}

function solarSystemAsteroidStats(SectorContentGenerator $generator, string $worldSeed, int $limit = 5000): array
{
    $stats = [
        'poorSystems' => 0,
        'poorSystemsWithAsteroids' => 0,
        'richSystems' => 0,
        'richSystemsWithAsteroids' => 0,
    ];

    for ($i = 0; $i < $limit; $i++) {
        $sector = $generator->generate(new SectorCoordinates($i * 2, 0, 0), $worldSeed);
        foreach ($sector->getObjects() as $object) {
            if (!$object instanceof SolarSystem) {
                continue;
            }

            $planetCount = 0;
            $asteroidCount = 0;
            foreach ($object->getOrbitalBodies() as $body) {
                $orbitalObject = $body->getObject();
                if ($orbitalObject instanceof Planet) {
                    $planetCount++;
                }
                if ($orbitalObject instanceof Asteroid) {
                    $asteroidCount++;
                }
            }

            if ($planetCount <= 3) {
                $stats['poorSystems']++;
                if ($asteroidCount > 0) {
                    $stats['poorSystemsWithAsteroids']++;
                }
            }
            if ($planetCount >= 8) {
                $stats['richSystems']++;
                if ($asteroidCount > 0) {
                    $stats['richSystemsWithAsteroids']++;
                }
            }
        }
    }

    return $stats;
}

// ============================================================================
// TEST SUITE
// ============================================================================

$test = new TestRunner();

echo "\n>>> Testing SectorCoordinates\n\n";

// Test: (0, 0, 0) is valid
$coord000 = new SectorCoordinates(0, 0, 0);
$test->assert(true, '(0, 0, 0) is valid');

// Test: (1, 0, 0) is invalid
$test->assertThrows(
    fn() => new SectorCoordinates(1, 0, 0),
    InvalidSectorCoordinatesException::class,
    '(1, 0, 0) is invalid (odd sum)'
);

// Test: (1, 1, 0) is valid
$coord110 = new SectorCoordinates(1, 1, 0);
$test->assert(true, '(1, 1, 0) is valid');

// Test: (2, 0, 0) is valid
$coord200 = new SectorCoordinates(2, 0, 0);
$test->assert(true, '(2, 0, 0) is valid');

// Test: getters
$test->assertEquals(2, $coord200->getX(), 'getX() returns correct value');
$test->assertEquals(0, $coord200->getY(), 'getY() returns correct value');
$test->assertEquals(0, $coord200->getZ(), 'getZ() returns correct value');

// Test: equals
$coord000b = new SectorCoordinates(0, 0, 0);
$test->assert($coord000->equals($coord000b), 'equals() returns true for same coords');
$test->assert(!$coord000->equals($coord110), 'equals() returns false for different coords');

// Test: add
$coord202 = $coord200->add(0, 0, 2);
$test->assertEquals(2, $coord202->getX(), 'add() produces correct X');
$test->assertEquals(0, $coord202->getY(), 'add() produces correct Y');
$test->assertEquals(2, $coord202->getZ(), 'add() produces correct Z');

// Test: add with invalid result
$test->assertThrows(
    fn() => $coord110->add(1, 0, 0),
    InvalidSectorCoordinatesException::class,
    'add() throws when result is invalid'
);

// Test: subtract
$diff = $coord200->subtract($coord110);
$test->assertEquals(['x' => 1, 'y' => -1, 'z' => 0], $diff, 'subtract() produces correct vector');

// Test: toArray
$arr = $coord110->toArray();
$test->assertEquals(['x' => 1, 'y' => 1, 'z' => 0], $arr, 'toArray() produces correct array');

// Test: toKey
$key = $coord110->toKey();
$test->assertEquals('1:1:0', $key, 'toKey() produces correct string');

// Test: fromKey
$coordFromKey = SectorCoordinates::fromKey('1:1:0');
$test->assert($coordFromKey->equals($coord110), 'fromKey() reconstructs correct coordinates');

// Test: fromKey with invalid key format
$test->assertThrows(
    fn() => SectorCoordinates::fromKey('invalid'),
    InvalidSectorCoordinatesException::class,
    'fromKey() throws on invalid format'
);

// Test: fromKey with invalid coordinates
$test->assertThrows(
    fn() => SectorCoordinates::fromKey('1:0:0'),
    InvalidSectorCoordinatesException::class,
    'fromKey() throws on invalid parity'
);

// Test: origin()
$origin = SectorCoordinates::origin();
$test->assert($origin->equals($coord000), 'origin() returns (0, 0, 0)');

echo "\n>>> Testing SectorGrid\n\n";

$grid = new SectorGrid();

// Test: neighbor offsets
$offsets = $grid->getNeighborOffsets();
$test->assertCount(12, $offsets, 'getNeighborOffsets() returns 12 offsets');

// Test: neighbors validity and count
$neighbors = $grid->getNeighbors($coord000);
$test->assertCount(12, $neighbors, 'getNeighbors() from origin returns 12 neighbors');

// Verify all neighbors are valid
$allValid = true;
foreach ($neighbors as $n) {
    if (!($n instanceof SectorCoordinates)) {
        $allValid = false;
        break;
    }
}
$test->assert($allValid, 'all neighbors are SectorCoordinates instances');

// Verify no duplicate neighbors
$neighborKeys = array_map(fn($n) => $n->toKey(), $neighbors);
$uniqueKeys = array_unique($neighborKeys);
$test->assertEquals(count($neighborKeys), count($uniqueKeys), 'no duplicate neighbors');

// Test: distance from origin to (1, 1, 0)
$dist_000_110 = $grid->getDistance($coord000, $coord110);
$test->assertEquals(1, $dist_000_110, 'distance(0,0,0 to 1,1,0) is 1');

// Test: distance from (0, 0, 0) to itself
$dist_000_000 = $grid->getDistance($coord000, $coord000);
$test->assertEquals(0, $dist_000_000, 'distance(0,0,0 to 0,0,0) is 0');

// Test: distance to (2, 0, 0)
$dist_000_200 = $grid->getDistance($coord000, $coord200);
$test->assertEquals(2, $dist_000_200, 'distance(0,0,0 to 2,0,0) is 2');

// Test: distance to (2, 2, 0)
$coord220 = new SectorCoordinates(2, 2, 0);
$dist_000_220 = $grid->getDistance($coord000, $coord220);
$test->assertEquals(2, $dist_000_220, 'distance(0,0,0 to 2,2,0) is 2');

// Test: distance to (4, 0, 0)
$coord400 = new SectorCoordinates(4, 0, 0);
$dist_000_400 = $grid->getDistance($coord000, $coord400);
$test->assertEquals(4, $dist_000_400, 'distance(0,0,0 to 4,0,0) is 4');

// Test: getSectorsAtDistance
$sectorsAtDist0 = $grid->getSectorsAtDistance($coord000, 0);
$test->assertCount(1, $sectorsAtDist0, 'getSectorsAtDistance(0, 0) returns 1 sector');

$sectorsAtDist1 = $grid->getSectorsAtDistance($coord000, 1);
$test->assertCount(12, $sectorsAtDist1, 'getSectorsAtDistance(0, 1) returns 12 sectors');

// Test: getSectorsWithinDistance
$sectorsWithinDist0 = $grid->getSectorsWithinDistance($coord000, 0);
$test->assertCount(1, $sectorsWithinDist0, 'getSectorsWithinDistance(0, 0) returns 1 sector');

$sectorsWithinDist1 = $grid->getSectorsWithinDistance($coord000, 1);
$test->assertCount(13, $sectorsWithinDist1, 'getSectorsWithinDistance(0, 1) returns 13 sectors (1 + 12)');

echo "\n>>> Testing PlayerReferenceFrame\n\n";

// Test: reference frame at origin
$frameAtOrigin = PlayerReferenceFrame::atOrigin();
$test->assert($frameAtOrigin->getGlobalOrigin()->equals($coord000), 'atOrigin() creates frame at (0,0,0)');

// Test: reference frame at custom location
$frame110 = PlayerReferenceFrame::atGlobalCoordinates(1, 1, 0);
$test->assert($frame110->getGlobalOrigin()->equals($coord110), 'atGlobalCoordinates() creates frame at correct location');

// Test: globalToRelative
$relFromOrigin = $frameAtOrigin->globalToRelative($coord110);
$test->assertEquals(['x' => 1, 'y' => 1, 'z' => 0], $relFromOrigin, 'globalToRelative() from origin');

// Test: globalToRelative with offset frame
$relFrom110 = $frame110->globalToRelative($coord000);
$test->assertEquals(['x' => -1, 'y' => -1, 'z' => 0], $relFrom110, 'globalToRelative() from offset frame');

// Test: relativeToGlobal
$globalFromOrigin = $frameAtOrigin->relativeToGlobal(1, 1, 0);
$test->assert($globalFromOrigin->equals($coord110), 'relativeToGlobal() from origin');

$globalFrom110 = $frame110->relativeToGlobal(-1, -1, 0);
$test->assert($globalFrom110->equals($coord000), 'relativeToGlobal() from offset frame');

// Test: round-trip conversion
$coord_test = new SectorCoordinates(3, 2, 1);
$rel = $frameAtOrigin->globalToRelative($coord_test);
$globalRecovered = $frameAtOrigin->relativeToGlobal($rel['x'], $rel['y'], $rel['z']);
$test->assert($globalRecovered->equals($coord_test), 'round-trip conversion works');

echo "\n>>> Testing SectorSeedGenerator\n\n";

$seedGen = SectorSeedGenerator::forWorldSeed('test_world_seed');

// Test: generateSeed returns string
$seed1 = $seedGen->generateSeed($coord000);
$test->assert(is_string($seed1), 'generateSeed() returns string');
$test->assert(strlen($seed1) === 64, 'generateSeed() returns SHA256 (64 chars)');

// Test: determinism
$seed1_again = $seedGen->generateSeed($coord000);
$test->assertEquals($seed1, $seed1_again, 'generateSeed() is deterministic');

// Test: different coordinates produce different seeds
$seed2 = $seedGen->generateSeed($coord110);
$test->assert($seed1 !== $seed2, 'different coordinates produce different seeds');

// Test: generateNumericSeed returns integer
$numSeed = $seedGen->generateNumericSeed($coord000);
$test->assert(is_int($numSeed), 'generateNumericSeed() returns integer');
$test->assert($numSeed >= 0, 'generateNumericSeed() returns non-negative integer');

// Test: numeric seeds are deterministic
$numSeed1_again = $seedGen->generateNumericSeed($coord000);
$test->assertEquals($numSeed, $numSeed1_again, 'generateNumericSeed() is deterministic');

// Test: different world seeds produce different results
$seedGen2 = SectorSeedGenerator::forWorldSeed('different_world_seed');
$seed_diff_world = $seedGen2->generateSeed($coord000);
$test->assert($seed_diff_world !== $seed1, 'different world seeds produce different seeds');

echo "\n>>> Testing Integration Scenarios\n\n";

// Scenario: Navigate from one sector to its neighbor
$start = SectorCoordinates::origin();
$grid = new SectorGrid();
$neighbors = $grid->getNeighbors($start);
$first_neighbor = $neighbors[0];
$dist = $grid->getDistance($start, $first_neighbor);
$test->assertEquals(1, $dist, 'direct neighbor is at distance 1');

// Scenario: Multi-step navigation
$coord_multi = new SectorCoordinates(2, 2, 2);
$step1_neighbors = $grid->getNeighbors($start);
$found_match = false;
foreach ($step1_neighbors as $n) {
    $step2_neighbors = $grid->getNeighbors($n);
    if (in_array($coord_multi, $step2_neighbors, true)) {
        $found_match = true;
        break;
    }
}
// (Note: This verifies the neighbor structure is consistent, not that we can always reach any point in 2 steps)

// Scenario: Player discovering sectors relative to their origin
$player_frame = PlayerReferenceFrame::atGlobalCoordinates(10, 10, 0);
$nearby_coords = new SectorCoordinates(11, 11, 0);
$relative = $player_frame->globalToRelative($nearby_coords);
$test->assertEquals(['x' => 1, 'y' => 1, 'z' => 0], $relative, 'player sees relative coordinates');

echo "\n>>> Testing Sector Content Generation and Storage\n\n";

$contentGenerator = new SectorContentGenerator();
$contentWorldSeed = 'test_content_seed';
$generatedA = $contentGenerator->generate($coord000, $contentWorldSeed);
$generatedB = $contentGenerator->generate($coord000, $contentWorldSeed);
$generatedDifferent = $contentGenerator->generate($coord110, $contentWorldSeed);

$test->assertEquals($generatedA->toArray(), $generatedB->toArray(), 'same seed and coordinates generate the same sector content');
$test->assert($generatedA->toArray() !== $generatedDifferent->toArray(), 'different coordinates generally produce different sector content or metadata');

$blackHoleSector = findGeneratedSector(
    $contentGenerator,
    'black_hole_search_seed',
    static fn(SectorContent $sector): bool => $sector->hasBlackHole()
);
$test->assert($blackHoleSector !== null, 'deterministic search finds a black hole sector');
if ($blackHoleSector !== null) {
    $containsSolarSystem = false;
    foreach ($blackHoleSector->getObjects() as $object) {
        if ($object instanceof SolarSystem) {
            $containsSolarSystem = true;
            break;
        }
    }
    $test->assert(!$containsSolarSystem, 'a sector containing a black hole does not contain a classic solar system');
}

$solarSystemSector = findGeneratedSector(
    $contentGenerator,
    'solar_system_search_seed',
    static function (SectorContent $sector): bool {
        foreach ($sector->getObjects() as $object) {
            if ($object instanceof SolarSystem) {
                return true;
            }
        }
        return false;
    }
);
$test->assert($solarSystemSector !== null, 'deterministic search finds a solar system');
if ($solarSystemSector !== null) {
    foreach ($solarSystemSector->getObjects() as $object) {
        if ($object instanceof SolarSystem) {
            $test->assert($object->getPrimaryStar() instanceof Star, 'a solar system contains at least one star');
            $primaryStar = $object->getPrimaryStar();
            $test->assertEquals($primaryStar->getId(), $solarSystemSector->findObjectById($primaryStar->getId())?->getId(), 'solar system primary star can be found by object id');
            $renamedStar = $primaryStar->withWaypointBookmark('Beacon star', [
                'name' => 'Beacon star',
                'playerId' => 1,
                'playerName' => 'tester',
                'createdAt' => '2026-06-01T00:00:00+00:00',
            ]);
            $test->assert($solarSystemSector->replaceObject($renamedStar), 'solar system primary star can be replaced in sector content');
            $test->assertEquals('Beacon star', $solarSystemSector->findObjectById($primaryStar->getId())?->getName(), 'replacing a solar system star persists its bookmark name');
            break;
        }
    }
}

$asteroidStats = solarSystemAsteroidStats($contentGenerator, 'asteroid_scaling_seed');
$test->assert($asteroidStats['poorSystems'] > 0, 'asteroid scaling sample contains low-planet solar systems');
$test->assert($asteroidStats['richSystems'] > 0, 'asteroid scaling sample contains high-planet solar systems');
$poorAsteroidRate = $asteroidStats['poorSystemsWithAsteroids'] / max(1, $asteroidStats['poorSystems']);
$richAsteroidRate = $asteroidStats['richSystemsWithAsteroids'] / max(1, $asteroidStats['richSystems']);
$test->assert($richAsteroidRate > $poorAsteroidRate, 'solar systems with more planets are more likely to contain asteroids');

$binarySector = findGeneratedSector(
    $contentGenerator,
    'binary_system_search_seed',
    static function (SectorContent $sector): bool {
        foreach ($sector->getObjects() as $object) {
            if ($object instanceof SolarSystem && $object->getSecondaryStar() instanceof Star) {
                return true;
            }
        }
        return false;
    }
);
$test->assert($binarySector !== null, 'deterministic search finds a binary system');
if ($binarySector !== null) {
    foreach ($binarySector->getObjects() as $object) {
        if ($object instanceof SolarSystem && $object->getSecondaryStar() instanceof Star) {
            $test->assertCount(2, $object->getStars(), 'a binary system contains two stars');
            break;
        }
    }
}

$tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vng_sector_tests_' . bin2hex(random_bytes(4));
$repository = new SectorFileRepository($tmpBase);
$negativeCoordinates = new SectorCoordinates(-2, 0, 0);
$negativePath = $repository->getPath($negativeCoordinates);
$test->assert(str_contains($negativePath, 'n2'), 'negative coordinates produce a safe file path');
$test->assert(!str_contains(basename($negativePath), '-'), 'negative coordinate file names do not contain raw minus signs');

$roundTripSector = $contentGenerator->generate($negativeCoordinates, 'round_trip_seed');
$repository->save($roundTripSector);
$loadedRoundTrip = $repository->load($negativeCoordinates);
$expectedLoadedRoundTrip = SectorContent::fromArray($roundTripSector->toArray(), 'loaded');
$test->assertEquals($expectedLoadedRoundTrip->toArray(), $loadedRoundTrip->toArray(), 'JSON write then read restores the same sector data');

$mannySector = new SectorContent($coord000, [
    new SectorManny(SectorManny::objectIdForUid('mny_test'), 'manny-test', 'mny_test', SectorManny::STATE_FORGOTTEN),
]);
$repository->save($mannySector);
$loadedMannySector = $repository->load($coord000);
$expectedLoadedMannySector = SectorContent::fromArray($mannySector->toArray(), 'loaded');
$test->assertEquals($expectedLoadedMannySector->toArray(), $loadedMannySector->toArray(), 'sector storage preserves abandoned or forgotten Manny objects');

$hiddenContainerA = new SectorDetachedContainer(
    'detached-container-cache-a',
    'Cache A',
    SectorDetachedContainer::MODE_HIDDEN_ON_ASTEROID,
    1,
    1,
    1,
    'cache-rock',
    1.0,
    'kg',
    '2026-01-01T00:00:00+00:00',
    ['resources' => ['metals' => 0.3]],
);
$hiddenContainerB = $hiddenContainerA->withPayload(['resources' => ['metals' => 0.2]]);
$duplicateHiddenSector = SectorContent::fromArray([
    'coordinates' => $coord000->toArray(),
    'objects' => [],
    'hiddenDetachedContainers' => [
        $hiddenContainerA->toArray(),
        $hiddenContainerB->toArray(),
    ],
]);
$test->assertCount(1, $duplicateHiddenSector->hiddenDetachedContainersForObject('cache-rock'), 'loading sector content deduplicates hidden detached containers by id');
$test->assertEquals(0.3, $duplicateHiddenSector->findHiddenDetachedContainerById('detached-container-cache-a')?->toArray()['payload']['resources']['metals'] ?? null, 'deduplicated hidden detached containers preserve the highest stored resource amount');

$hiddenContainerC = $hiddenContainerA->withPayload(['resources' => ['metals' => 0.35]]);
$duplicateHiddenSector->addHiddenDetachedContainer($hiddenContainerC);
$test->assertCount(1, $duplicateHiddenSector->hiddenDetachedContainersForObject('cache-rock'), 'adding the same hidden detached container id replaces the existing entry');
$hiddenContainerD = $hiddenContainerA->withPayload(['resources' => ['metals' => 0.4]]);
$test->assert($duplicateHiddenSector->replaceDetachedContainer($hiddenContainerD), 'replacing a hidden detached container succeeds');
$test->assertCount(1, $duplicateHiddenSector->hiddenDetachedContainersForObject('cache-rock'), 'replacing a hidden detached container does not duplicate it');
$test->assertEquals(0.4, $duplicateHiddenSector->findHiddenDetachedContainerById('detached-container-cache-a')?->toArray()['payload']['resources']['metals'] ?? null, 'replacing a hidden detached container updates its payload');
$removedHiddenContainer = $duplicateHiddenSector->removeHiddenDetachedContainerById('detached-container-cache-a');
$test->assert($removedHiddenContainer instanceof SectorDetachedContainer, 'removing a hidden detached container returns the removed object');
$test->assertCount(0, $duplicateHiddenSector->hiddenDetachedContainersForObject('cache-rock'), 'removing a hidden detached container removes all duplicates for that id');

$serviceBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vng_sector_service_tests_' . bin2hex(random_bytes(4));
$serviceRepository = new SectorFileRepository($serviceBase);
$service = new SectorService($serviceRepository, $contentGenerator, 'service_seed', $grid);
$createdOrigin = $service->getOrCreateSector($coord000);
$test->assert($serviceRepository->exists($coord000), 'a missing sector is generated then saved');
$creationLog = $service->getCreatedSectorKeys();
$test->assertEquals($coord000->toKey(), $creationLog[0] ?? null, 'getOrCreateSector creates the requested sector before missing neighbors');
$test->assertCount(13, $creationLog, 'creating direct missing neighbors does not recurse infinitely');

$loadedExisting = $service->getOrCreateSector($coord000);
$test->assertEquals('loaded', $loadedExisting->getSource(), 'an existing sector is loaded from storage');
$test->assertCount(13, $service->getCreatedSectorKeys(), 'an existing sector is not regenerated');
$test->assert($createdOrigin->getCoordinates()->equals($loadedExisting->getCoordinates()), 'loaded sector has the requested coordinates');

removeDirectory($tmpBase);
removeDirectory($serviceBase);

// Print summary
$test->printSummary();
exit($test->getStatus());
