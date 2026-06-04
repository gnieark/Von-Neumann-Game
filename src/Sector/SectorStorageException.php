<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

final class SectorStorageException extends \RuntimeException
{
    public static function invalidJson(string $path, string $message): self
    {
        return new self("Invalid sector JSON in '$path': $message");
    }

    public static function writeFailed(string $path): self
    {
        return new self("Unable to write sector file '$path'");
    }
}
