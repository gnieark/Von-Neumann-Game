<?php
declare(strict_types=1);

// Local synthetic benchmark only: no application configuration or production connection.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Support/StorageTestPdo.php';
require_once __DIR__ . '/Support/CraftingBatchFixture.php';

$directory = sys_get_temp_dir() . '/vng-craft-benchmark-' . bin2hex(random_bytes(6));
$report = [];
try {
    foreach ([1, 10, 25, 50, 100] as $size) {
        $row = ['tasks' => $size];
        foreach (['sequential' => false, 'sharedInventory' => true] as $label => $optimized) {
            $raw = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $db = new StorageTestPdo($raw);
            (new \VonNeumannGame\Database\SchemaInitializer('sqlite'))->initialize($db);
            $f = craftingBatchFixture($db, $directory, 'benchmark', 2000, 1000);
            $actors = array_slice($f['actors'], 0, $size);
            $db->metrics->reset(); $start = hrtime(true);
            $f['mining']->withPreparedBatch($f['probe'], static function ($probe) use ($f, $actors): void {
                foreach ($actors as $manny) { $f['mining']->startCrafting($probe, $manny->uid, 'steel_plate'); }
            }, [], false, $optimized ? array_column($actors, 'uid') : []);
            $row[$label] = ['milliseconds' => round((hrtime(true) - $start) / 1e6, 2)] + $db->metrics->snapshot();
        }
        $report[] = $row;
    }
    echo json_encode(['driver' => 'sqlite:memory', 'mannies' => 2000, 'items' => 1000, 'results' => $report], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    if (is_dir($directory)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
}
