<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$factory = new AppFactory(dirname(__DIR__));
$dbFactory = $factory->databaseFactory();
$pdo = $dbFactory->create();
$dbFactory->initializeSchema($pdo);

echo "Database schema initialized.\n";
