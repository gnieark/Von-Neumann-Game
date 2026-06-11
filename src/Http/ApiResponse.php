<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

final class ApiResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = ['Content-Type' => 'application/json'],
    ) {}

    public static function error(int $status, string $code, string $message, array $details = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new self($status, ['error' => $error]);
    }
}
