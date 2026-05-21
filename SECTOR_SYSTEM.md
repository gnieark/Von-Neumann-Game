# Von Neumann Game - Sector System

## Overview

This module provides a complete sector coordinate system for the Von Neumann Game universe. The universe is divided into cells that behave as rhombic dodecahedra on a Face-Centered Cubic (FCC) lattice.

**Key concepts:**
- **Coordinates**: 3D integer coordinates (x, y, z) where x + y + z must be even (FCC parity)
- **Neighbors**: Each sector has exactly 12 direct neighbors (face-diagonal offsets)
- **Distance**: Chebyshev distance (max coordinate difference) represents grid steps
- **Reference Frames**: Players can have their own origin point for relative coordinate display
- **Procedural Generation**: Deterministic seeds can be generated from coordinates

## Architecture

### Classes

#### 1. `SectorCoordinates`
Immutable value object representing a 3D coordinate.

**Key methods:**
```php
// Creation
$coord = new SectorCoordinates(1, 1, 0);
$origin = SectorCoordinates::origin(); // (0, 0, 0)
$coord = SectorCoordinates::fromKey('1:1:0');

// Queries
$coord->getX();          // int
$coord->getY();          // int
$coord->getZ();          // int
$coord->toKey();         // string: "x:y:z"
$coord->toArray();       // array: ['x', 'y', 'z']
$coord->equals($other);  // bool

// Operations
$coord2 = $coord->add(1, 0, 0);      // new SectorCoordinates
$vector = $coord->subtract($other);  // ['x' => ..., 'y' => ..., 'z' => ...]
```

**Validation:**
- Constructor throws `InvalidSectorCoordinatesException` if x + y + z is odd
- `add()` throws if result has odd parity
- `fromKey()` throws on invalid format or parity

#### 2. `SectorGrid`
Provides grid topology and navigation.

**Key methods:**
```php
$grid = new SectorGrid();

// Neighbors
$neighbors = $grid->getNeighbors($coord);           // SectorCoordinates[]
$offsets = $grid->getNeighborOffsets();             // [[...], ...]

// Distances
$dist = $grid->getDistance($coordA, $coordB);       // int

// Exploration
$atDist = $grid->getSectorsAtDistance($center, 1);      // SectorCoordinates[]
$within = $grid->getSectorsWithinDistance($center, 1);  // SectorCoordinates[]
```

**Distance formula:**
Uses Chebyshev distance: `max(|dx|, |dy|, |dz|)`

This represents the minimum number of neighbor hops required to move from one sector to another.

#### 3. `PlayerReferenceFrame`
Manages coordinate transformation between global and relative (player-centric) coordinates.

**Key methods:**
```php
// Creation
$frame = PlayerReferenceFrame::atOrigin();
$frame = PlayerReferenceFrame::atGlobalCoordinates(10, 10, 0);

// Queries
$origin = $frame->getGlobalOrigin();

// Transformations
$relative = $frame->globalToRelative($globalCoord);      // ['x' => ..., ...]
$global = $frame->relativeToGlobal($relX, $relY, $relZ); // SectorCoordinates
```

**Semantics:**
- Relative coordinates represent the vector from frame origin to the sector
- Frame origin for player A is displayed as (0, 0, 0)
- A sector at global (12, 12, 0) appears as (2, 2, 0) to a player at (10, 10, 0)

**Future extensibility:**
- Currently implements pure translation
- Can be extended with rotation/permutation via composition or inheritance
- Interface remains stable for future enhancements

#### 4. `SectorSeedGenerator`
Generates deterministic procedural seeds from coordinates.

**Key methods:**
```php
$gen = SectorSeedGenerator::forWorldSeed('my_world_id_42');

$seed = $gen->generateSeed($coord);           // string (SHA256 hash)
$numSeed = $gen->generateNumericSeed($coord); // int (64-bit)
```

**Properties:**
- Deterministic: same coordinate always produces same seed
- World-specific: different world seeds produce different results
- Uses SHA256 for collision resistance

#### 5. `InvalidSectorCoordinatesException`
Exception thrown for invalid coordinates.

## Coordinate System Details

### FCC Lattice

The Face-Centered Cubic (FCC) lattice is defined by:
- Valid coordinates: x + y + z is **even**
- Examples: (0,0,0), (1,1,0), (2,0,0), (2,2,0)
- Invalid: (1,0,0), (2,1,0), (3,2,1)

### 12 Neighbors

Each sector has exactly 12 direct neighbors via face-diagonal offsets:
```
(±1, ±1,  0)   4 neighbors in XY plane
(±1,  0, ±1)   4 neighbors in XZ plane
( 0, ±1, ±1)   4 neighbors in YZ plane
```

All offsets maintain parity: neighbor sum is always even.

### Distance

Distance between two sectors is calculated as:
```
distance(A, B) = max(|B.x - A.x|, |B.y - A.y|, |B.z - A.z|)
```

This Chebyshev distance represents the minimum number of neighbor hops.

**Examples:**
- (0,0,0) to (1,1,0): distance = 1 (direct neighbor)
- (0,0,0) to (2,2,0): distance = 2 (2 hops)
- (0,0,0) to (2,0,0): distance = 2 (2 hops)

## Usage Examples

### Basic Coordinate Operations
```php
use VonNeumannGame\Sector\SectorCoordinates;

$origin = SectorCoordinates::origin();
$sector = new SectorCoordinates(1, 1, 0);

// Arithmetic
$neighbor = $sector->add(1, -1, 0);  // (2, 0, 0)

// Serialization
$key = $sector->toKey();             // "1:1:0"
$restored = SectorCoordinates::fromKey($key);
```

### Grid Navigation
```php
use VonNeumannGame\Sector\SectorGrid;

$grid = new SectorGrid();
$neighbors = $grid->getNeighbors($origin);
echo count($neighbors);  // 12

// Find distance
$dist = $grid->getDistance($origin, $sector);  // 1

// Explore
$nearby = $grid->getSectorsWithinDistance($origin, 2);
```

### Player-Centric Coordinates
```php
use VonNeumannGame\Sector\PlayerReferenceFrame;

$player = PlayerReferenceFrame::atGlobalCoordinates(100, 100, 0);

// Player sees relative coordinates
$globalSector = new SectorCoordinates(101, 101, 0);
$relative = $player->globalToRelative($globalSector);
// relative = ['x' => 1, 'y' => 1, 'z' => 0]

// Convert back
$global = $player->relativeToGlobal(1, 1, 0);
// global = SectorCoordinates(101, 101, 0)
```

### Procedural Generation
```php
use VonNeumannGame\Sector\SectorSeedGenerator;

$gen = SectorSeedGenerator::forWorldSeed('world_2024');

// Generate terrain seed
$seed = $gen->generateSeed($sector);
$numSeed = $gen->generateNumericSeed($sector);

// Use in procedural generation algorithm
$rng = mt_srand($numSeed);
```

## Testing

Run the comprehensive test suite:
```bash
php class/Tests.php
```

**Coverage includes:**
- Coordinate validation (parity, equality, serialization)
- Grid topology (12 neighbors, distances)
- Reference frame transformations
- Procedural seed generation
- Round-trip conversions
- Edge cases and error handling

## Design Principles

1. **Immutability**: `SectorCoordinates` instances cannot be modified
2. **Fail-fast**: Invalid coordinates throw exceptions immediately
3. **Type safety**: PHP 8.2 strict types throughout
4. **Testability**: No external dependencies, pure functions
5. **Extensibility**: Architecture prepared for future rotation/permutation
6. **Performance**: Efficient Chebyshev distance calculation
7. **Determinism**: Procedural generation uses stable hashing

## Future Extensions

The current architecture supports future enhancements:

- **Rotation/Permutation**: Extend `PlayerReferenceFrame` or create `RotatedReferenceFrame`
- **Chunking**: Group sectors into larger regions for optimization
- **Caching**: Memoize distance calculations for frequently-accessed pairs
- **Pathfinding**: Implement A* using Chebyshev distance heuristic
- **Persistence**: Use `toKey()` format for database storage
- **Serialization**: JSON representation via `toArray()`

## Files

```
class/
  ├── SectorCoordinates.php
  ├── SectorGrid.php
  ├── PlayerReferenceFrame.php
  ├── SectorSeedGenerator.php
  ├── InvalidSectorCoordinatesException.php
  └── Tests.php

public/
  └── examples.php
```

## Requirements

- PHP 8.2+
- No external dependencies
- No database required
- Built-in PHP functions only

## Notes

- Global coordinates are never displayed to the player; only relative coordinates are shown
- Each player/lineage has its own origin for coordinate display
- The FCC lattice ensures uniform neighborhood (12 neighbors per sector)
- Distance calculation is symmetric: distance(A, B) = distance(B, A)
- Procedural seeds are stable and reproducible for world generation
