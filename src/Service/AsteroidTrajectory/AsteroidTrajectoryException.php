<?php

declare(strict_types=1);

namespace VonNeumannGame\Service\AsteroidTrajectory;

final class AsteroidTrajectoryException extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
