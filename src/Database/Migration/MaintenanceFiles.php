<?php
declare(strict_types=1);
namespace VonNeumannGame\Database\Migration;

final class MaintenanceFiles
{
    public static function write(string $path, string $content): void
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) { throw new \RuntimeException('Cannot create maintenance directory.'); }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'x');
        if ($handle === false) { throw new \RuntimeException('Cannot create maintenance file.'); }
        try {
            chmod($temporary, is_file($path) ? fileperms($path) & 0777 : 0600);
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle) || !fsync($handle)) { throw new \RuntimeException('Cannot persist maintenance file.'); }
        } finally { fclose($handle); }
        if (!rename($temporary, $path)) { throw new \RuntimeException('Cannot publish maintenance file.'); }
        $directory = fopen(dirname($path), 'r');
        if ($directory !== false) { fsync($directory); fclose($directory); }
    }

    public static function json(string $path, array $value): void
    {
        self::write($path, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
    }

    public static function read(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Sorted relative paths. Symlinks are never traversed or rewritten. */
    public static function sectors(string $root): array
    {
        if (!is_dir($root . '/sectors')) { throw new \RuntimeException('Missing sectors directory.'); }
        $paths = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/sectors', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file->isLink()) { throw new \RuntimeException('Symlink in universe: ' . $file->getPathname()); }
            if ($file->isFile() && $file->getExtension() === 'json') { $paths[] = substr($file->getPathname(), strlen($root) + 1); }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    public static function at(array $data, string $pointer): mixed
    {
        foreach (self::parts($pointer) as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) { throw new \RuntimeException('Missing migration pointer: ' . $pointer); }
            $data = $data[$part];
        }
        return $data;
    }

    public static function put(array &$data, string $pointer, mixed $value): void
    {
        $cursor =& $data;
        foreach (self::parts($pointer) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) { throw new \RuntimeException('Missing migration pointer: ' . $pointer); }
            $cursor =& $cursor[$part];
        }
        $cursor = $value;
    }

    private static function parts(string $pointer): array
    {
        return $pointer === '' ? [] : array_map(static fn($p) => str_replace(['~1', '~0'], ['/', '~'], $p), explode('/', ltrim($pointer, '/')));
    }
}
