<?php

/**
 * Von Neumann Game - Sector System Loader
 * 
 * This file loads all classes for the sector coordinate system.
 * Include this file to get access to the sector classes.
 * 
 * Usage:
 *   require_once 'class/index.php';
 *   use VonNeumannGame\Sector\SectorCoordinates;
 */
declare(strict_types=1);

// Load all classes
require_once __DIR__ . '/InvalidSectorCoordinatesException.php';
require_once __DIR__ . '/SectorCoordinates.php';
require_once __DIR__ . '/SectorGrid.php';
require_once __DIR__ . '/PlayerReferenceFrame.php';
require_once __DIR__ . '/SectorSeedGenerator.php';
require_once __DIR__ . '/UniverseObjectType.php';
require_once __DIR__ . '/UniverseObject.php';
require_once __DIR__ . '/UniverseObjectFactory.php';
require_once __DIR__ . '/Star.php';
require_once __DIR__ . '/Planet.php';
require_once __DIR__ . '/Asteroid.php';
require_once __DIR__ . '/DustCloud.php';
require_once __DIR__ . '/BlackHole.php';
require_once __DIR__ . '/OrbitDescriptor.php';
require_once __DIR__ . '/OrbitingBody.php';
require_once __DIR__ . '/SolarSystem.php';
require_once __DIR__ . '/SectorStorageException.php';
require_once __DIR__ . '/DeterministicRandom.php';
require_once __DIR__ . '/SectorContent.php';
require_once __DIR__ . '/SectorFileRepository.php';
require_once __DIR__ . '/SectorContentGenerator.php';
require_once __DIR__ . '/SectorService.php';
