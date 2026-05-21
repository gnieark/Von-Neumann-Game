<?php

declare(strict_types=1);

namespace VonNeumannGame\Database;

use PDO;

final class DatabaseConnectionFactory
{
    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly string $projectRoot,
    ) {}

    public function create(): PDO
    {
        $pdo = match ($this->config->driver) {
            'sqlite' => $this->createSqliteConnection(),
            'mysql' => $this->createMysqlConnection(),
            default => throw new \InvalidArgumentException("Unsupported DB driver '{$this->config->driver}'"),
        };

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    public function initializeSchema(PDO $pdo): void
    {
        (new SchemaInitializer($this->config->driver))->initialize($pdo);
    }

    private function createSqliteConnection(): PDO
    {
        if ($this->config->path === null) {
            throw new \InvalidArgumentException('SQLite database path is required.');
        }

        $path = $this->absolutePath($this->config->path);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create database directory '$directory'");
        }

        return new PDO('sqlite:' . $path);
    }

    private function createMysqlConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config->host ?? 'localhost',
            $this->config->port,
            $this->config->database ?? '',
            $this->config->charset,
        );

        return new PDO($dsn, $this->config->username, $this->config->password);
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
}
