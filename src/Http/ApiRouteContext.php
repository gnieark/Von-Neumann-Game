<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

final class ApiRouteContext
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @param list<string> $params
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly array $params = [],
    ) {}

    public function stringParam(int $index): string
    {
        return rawurldecode((string) ($this->params[$index] ?? ''));
    }

    public function intParam(int $index): int
    {
        return (int) ($this->params[$index] ?? 0);
    }
}
