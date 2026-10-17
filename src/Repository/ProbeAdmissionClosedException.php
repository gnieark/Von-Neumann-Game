<?php
declare(strict_types=1);
namespace VonNeumannGame\Repository;

/** The loss coordinator owns this probe; callers must not recreate its work. */
final class ProbeAdmissionClosedException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'probe_dead') { parent::__construct($message); }
}
