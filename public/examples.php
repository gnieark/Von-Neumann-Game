<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector\Examples;

require_once __DIR__ . '/../class/SectorCoordinates.php';
require_once __DIR__ . '/../class/SectorGrid.php';
require_once __DIR__ . '/../class/PlayerReferenceFrame.php';
require_once __DIR__ . '/../class/SectorSeedGenerator.php';

use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorSeedGenerator;

echo "=== Von Neumann Game: Sector System Examples ===\n\n";

// Example 1: Creating coordinates
echo "Example 1: Creating sector coordinates\n";
echo "---------------------------------------\n";
$origin = SectorCoordinates::origin();
echo "Origin: " . $origin->toKey() . "\n";

$sector1 = new SectorCoordinates(1, 1, 0);
echo "Sector 1: " . $sector1->toKey() . "\n";

$sector2 = new SectorCoordinates(2, 2, 0);
echo "Sector 2: " . $sector2->toKey() . "\n";
echo "\n";

// Example 2: Coordinate operations
echo "Example 2: Coordinate operations\n";
echo "--------------------------------\n";
$sector3 = $sector1->add(1, 1, 0);
echo "Sector 1 + (1,1,0) = " . $sector3->toKey() . "\n";

$diff = $sector2->subtract($sector1);
echo "Sector 2 - Sector 1 = (" . $diff['x'] . ", " . $diff['y'] . ", " . $diff['z'] . ")\n";

$reconstructed = SectorCoordinates::fromKey($sector2->toKey());
echo "Reconstructed from key: " . $reconstructed->toKey() . "\n";
echo "\n";

// Example 3: Grid navigation
echo "Example 3: Grid navigation and distances\n";
echo "----------------------------------------\n";
$grid = new SectorGrid();
$neighbors = $grid->getNeighbors($origin);
echo "The origin has " . count($neighbors) . " direct neighbors.\n";
echo "First 3 neighbors of origin:\n";
for ($i = 0; $i < 3 && $i < count($neighbors); $i++) {
    $n = $neighbors[$i];
    $dist = $grid->getDistance($origin, $n);
    echo "  - " . $n->toKey() . " (distance: $dist)\n";
}
echo "\n";

// Example 4: Distance calculations
echo "Example 4: Distance calculations\n";
echo "-------------------------------\n";
echo "Distance from origin to (1,1,0): " . $grid->getDistance($origin, $sector1) . "\n";
echo "Distance from origin to (2,2,0): " . $grid->getDistance($origin, $sector2) . "\n";
echo "Distance from origin to (2,0,0): " . $grid->getDistance($origin, new SectorCoordinates(2, 0, 0)) . "\n";
echo "Distance from (1,1,0) to (2,2,0): " . $grid->getDistance($sector1, $sector2) . "\n";
echo "\n";

// Example 5: Explore sectors within distance
echo "Example 5: Sectors within distance\n";
echo "--------------------------------\n";
$withinDist1 = $grid->getSectorsWithinDistance($origin, 1);
echo "Sectors within distance 1 from origin: " . count($withinDist1) . "\n";
echo "  (includes origin + 12 neighbors)\n";
echo "\n";

// Example 6: Player reference frame
echo "Example 6: Player reference frame\n";
echo "-------------------------------\n";
$player = PlayerReferenceFrame::atGlobalCoordinates(10, 10, 0);
echo "Player is at global coordinates: " . $player->getGlobalOrigin()->toKey() . "\n";

$globalSector = new SectorCoordinates(12, 12, 0);
$relative = $player->globalToRelative($globalSector);
echo "A sector at global (12,12,0) appears at relative coordinates:\n";
echo "  (" . $relative['x'] . ", " . $relative['y'] . ", " . $relative['z'] . ")\n";

$backToGlobal = $player->relativeToGlobal($relative['x'], $relative['y'], $relative['z']);
echo "Converting back to global: " . $backToGlobal->toKey() . "\n";
echo "\n";

// Example 7: Procedural seed generation
echo "Example 7: Procedural seed generation\n";
echo "-----------------------------------\n";
$seedGen = SectorSeedGenerator::forWorldSeed('my_world_42');

$seed1 = $seedGen->generateSeed($origin);
echo "Seed for origin:\n";
echo "  Hash: " . substr($seed1, 0, 16) . "...\n";

$numSeed = $seedGen->generateNumericSeed($origin);
echo "  Numeric: $numSeed\n";

$seed2 = $seedGen->generateSeed($sector1);
echo "\nSeed for (1,1,0):\n";
echo "  Hash: " . substr($seed2, 0, 16) . "...\n";

$numSeed2 = $seedGen->generateNumericSeed($sector1);
echo "  Numeric: $numSeed2\n";
echo "\n";

// Example 8: Finding paths (conceptual)
echo "Example 8: Navigating the grid\n";
echo "-----------------------------\n";
$currentSector = $origin;
$targetSector = new SectorCoordinates(2, 2, 0);

$distance = $grid->getDistance($currentSector, $targetSector);
echo "Navigating from " . $currentSector->toKey() . " to " . $targetSector->toKey() . "\n";
echo "Minimum distance: $distance steps\n";

// Get neighbors and show next possible steps
$neighbors = $grid->getNeighbors($currentSector);
echo "\nAvailable moves from current sector:\n";
$closest = null;
$closestDist = PHP_INT_MAX;
foreach ($neighbors as $neighbor) {
    $distToTarget = $grid->getDistance($neighbor, $targetSector);
    $better = $distToTarget < $distance ? " (better)" : "";
    if ($distToTarget < $closestDist) {
        $closestDist = $distToTarget;
        $closest = $neighbor;
    }
    echo "  - " . $neighbor->toKey() . " → distance to target: $distToTarget$better\n";
}
echo "\nBest next step: " . $closest->toKey() . "\n";
