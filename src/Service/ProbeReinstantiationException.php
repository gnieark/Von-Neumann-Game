<?php

declare(strict_types=1);

namespace VonNeumannGame\Service;

final class ProbeReinstantiationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 409,
        public readonly string $errorCode = 'probe_reassignment_unavailable',
    ) {
        parent::__construct($message);
    }
}
