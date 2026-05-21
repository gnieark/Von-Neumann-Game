<?php

declare(strict_types=1);

namespace VonNeumannGame\Database;

final class DatabaseConfig
{
    public function __construct(
        public readonly string $driver,
        public readonly ?string $path = null,
        public readonly ?string $host = null,
        public readonly int $port = 3306,
        public readonly ?string $database = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly string $charset = 'utf8mb4',
    ) {}

    public static function fromFile(string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Unable to read database config '$path'");
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Database config must be a JSON object.');
        }

        return new self(
            (string) $data['driver'],
            $data['path'] ?? null,
            $data['host'] ?? null,
            isset($data['port']) ? (int) $data['port'] : 3306,
            $data['database'] ?? null,
            $data['username'] ?? null,
            $data['password'] ?? null,
            $data['charset'] ?? 'utf8mb4',
        );
    }
}
