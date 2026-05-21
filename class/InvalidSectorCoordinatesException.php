<?php

declare(strict_types=1);

namespace VonNeumannGame\Sector;

class InvalidSectorCoordinatesException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function invalidParity(int $x, int $y, int $z): self
    {
        return new self("Invalid coordinates ($x, $y, $z): sum must be even (got " . ($x + $y + $z) . ')');
    }

    public static function invalidKey(string $key): self
    {
        return new self("Invalid coordinate key format: '$key'");
    }
}
