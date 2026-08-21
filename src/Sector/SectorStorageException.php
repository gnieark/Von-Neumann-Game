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

    /** @param list<string> $references */
    public static function legacyDetachedContainerData(string $path, array $references): self
    {
        return new self(
            "Sector file '$path' still contains legacy detached-container JSON at "
            . implode(', ', $references)
            . '; run scripts/one-shot-scripts/cleanup-legacy-detached-container-json.php before starting the application.'
        );
    }
}
