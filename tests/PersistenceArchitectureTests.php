<?php

declare(strict_types=1);
require_once __DIR__ . '/Support/PersistenceArchitecture.php';
$errors = PersistenceArchitecture::audit(dirname(__DIR__));
foreach ($errors as $error) { fwrite(STDERR, $error . PHP_EOL); }
if ($errors !== []) { exit(1); }
echo "Persistence architecture: OK\n";
