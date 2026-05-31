<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

final class MannyActionException extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
