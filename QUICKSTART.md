# Sector System - Quick Start Guide

## Installation

No installation needed! All files are in PHP and require no external dependencies.

```bash
# Clone or check out the repository
cd /home/gnieark/vonneumanngame

# Verify everything works
php class/Tests.php        # Should show 50/50 tests passing
php public/examples.php    # Should show working examples
```

## 5-Minute Introduction

### 1. Import the Classes

```php
require_once 'class/index.php';

use VonNeumannGame\Sector\SectorCoordinates;
use VonNeumannGame\Sector\SectorGrid;
use VonNeumannGame\Sector\PlayerReferenceFrame;
use VonNeumannGame\Sector\SectorSeedGenerator;
```

### 2. Create Coordinates

```php
// Create the origin (0, 0, 0)
$origin = SectorCoordinates::origin();

// Create a sector at (1, 1, 0)
$sector = new SectorCoordinates(1, 1, 0);

// Serialize/deserialize
$key = $sector->toKey();                    // "1:1:0"
$restored = SectorCoordinates::fromKey($key);
```

### 3. Navigate the Grid

```php
$grid = new SectorGrid();

// Get the 12 neighbors of a sector
$neighbors = $grid->getNeighbors($origin);
echo count($neighbors);  // 12

// Calculate distance between two sectors
$distance = $grid->getDistance($origin, $sector);
echo $distance;  // 1
```

### 4. Use Player Reference Frames

```php
// Player is at global coordinates (100, 100, 0)
$player = PlayerReferenceFrame::atGlobalCoordinates(100, 100, 0);

// A sector at global (101, 101, 0) appears as relative (1, 1, 0)
$globalSector = new SectorCoordinates(101, 101, 0);
$relative = $player->globalToRelative($globalSector);
// $relative = ['x' => 1, 'y' => 1, 'z' => 0]

// Convert back
$global = $player->relativeToGlobal(1, 1, 0);
// $global = SectorCoordinates(101, 101, 0)
```

### 5. Generate Seeds for Procedural Generation

```php
$gen = SectorSeedGenerator::forWorldSeed('my_world');

// Generate a deterministic seed
$seed = $gen->generateSeed($sector);           // SHA256 hash
$numSeed = $gen->generateNumericSeed($sector); // 64-bit integer

// Same coordinates always produce same seed
$seed2 = $gen->generateSeed($sector);
assert($seed === $seed2);  // true
```

## Common Patterns

### Explore Around a Sector

```php
$grid = new SectorGrid();
$center = new SectorCoordinates(10, 10, 0);

// Get all sectors within distance 2
$nearby = $grid->getSectorsWithinDistance($center, 2);
echo count($nearby);  // 1 + 12 + ... = 49

// Get only those at distance 2
$distant = $grid->getSectorsAtDistance($center, 2);
echo count($distant);  // 30
```

### Find Shortest Path

```php
$grid = new SectorGrid();
$start = SectorCoordinates::origin();
$goal = new SectorCoordinates(5, 5, 0);

// Distance is the minimum steps needed
$steps = $grid->getDistance($start, $goal);
echo $steps;  // 5

// Find best first move
$neighbors = $grid->getNeighbors($start);
$best = null;
$bestDist = $steps;

foreach ($neighbors as $next) {
    $dist = $grid->getDistance($next, $goal);
    if ($dist < $bestDist) {
        $bestDist = $dist;
        $best = $next;
    }
}

echo "Move to: " . $best->toKey();
```

### Store Sectors in Database

```php
// Use toKey() as the primary key
$sector = new SectorCoordinates(1, 1, 0);
$key = $sector->toKey();  // "1:1:0"

// SQL query (pseudocode)
// INSERT INTO sectors (id, x, y, z, type) VALUES (?, ?, ?, ?, ?)
// Parameters: $key, $sector->getX(), $sector->getY(), $sector->getZ(), 'habitable'

// Retrieve and reconstruct
// $key = '1:1:0'
$restored = SectorCoordinates::fromKey($key);
```

### Handle Multiple Players

```php
// Player 1 at origin
$player1 = PlayerReferenceFrame::atOrigin();

// Player 2 at different location
$player2 = PlayerReferenceFrame::atGlobalCoordinates(1000, 1000, 0);

// Both see the same sector (1,1,0) differently
$sector = new SectorCoordinates(1, 1, 0);

$rel1 = $player1->globalToRelative($sector);  // [1, 1, 0]
$rel2 = $player2->globalToRelative($sector);  // [-999, -999, 0]
```

## Coordinate System Basics

### Valid Coordinates

The sum of coordinates must be **even**:
```
✓ (0, 0, 0)  sum = 0 (even)
✓ (1, 1, 0)  sum = 2 (even)
✓ (2, 2, 0)  sum = 4 (even)
✗ (1, 0, 0)  sum = 1 (odd)  → InvalidSectorCoordinatesException
✗ (2, 1, 0)  sum = 3 (odd)  → InvalidSectorCoordinatesException
```

### Distance

The distance between two sectors is the Chebyshev distance:
```
distance = max(|x2 - x1|, |y2 - y1|, |z2 - z1|)
```

Examples:
```
(0,0,0) → (1,1,0): distance = 1
(0,0,0) → (2,2,0): distance = 2
(0,0,0) → (2,0,0): distance = 2
(0,0,0) → (3,3,0): distance = 3
```

### 12 Neighbors

Each sector has exactly 12 neighbors, reachable via one step:
```
(±1, ±1,  0)   4 neighbors in XY plane
(±1,  0, ±1)   4 neighbors in XZ plane
( 0, ±1, ±1)   4 neighbors in YZ plane
```

## Error Handling

```php
try {
    // Invalid parity
    $bad = new SectorCoordinates(1, 0, 0);
} catch (InvalidSectorCoordinatesException $e) {
    echo $e->getMessage();  // "Invalid coordinates (1, 0, 0): sum must be even"
}

try {
    // Invalid key format
    $bad = SectorCoordinates::fromKey('not:valid:format');
} catch (InvalidSectorCoordinatesException $e) {
    echo $e->getMessage();  // "Invalid coordinate key format..."
}

try {
    // Operation resulting in invalid coordinates
    $sector = new SectorCoordinates(1, 1, 0);
    $bad = $sector->add(1, 0, 0);  // (2, 1, 0) has odd sum
} catch (InvalidSectorCoordinatesException $e) {
    echo $e->getMessage();
}
```

## Testing

Run all tests:
```bash
php class/Tests.php
```

Expected output:
```
Tests passed: 50
Tests failed: 0
```

Run examples:
```bash
php public/examples.php
```

## Next Steps

1. Read `SECTOR_SYSTEM.md` for detailed API documentation
2. Review `public/examples.php` for more complex patterns
3. Look at `class/Tests.php` to see usage patterns
4. Start building your game features on top of this foundation

## Support

- See `SECTOR_SYSTEM.md` for complete API reference
- See `IMPLEMENTATION_SUMMARY.md` for architecture overview
- Look at `public/examples.php` for working code
- Check `class/Tests.php` for usage patterns

## Key Takeaways

- ✅ **Immutable**: Coordinates can't change
- ✅ **Deterministic**: Same input = same output, always
- ✅ **Validated**: Invalid coordinates throw exceptions
- ✅ **Efficient**: O(1) for most operations
- ✅ **Extensible**: Ready for rotation/permutation
- ✅ **No dependencies**: Pure PHP 8.2+
