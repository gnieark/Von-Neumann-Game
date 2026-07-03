<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

final class ApiRoute
{
    /**
     * @param list<string> $methods
     * @param callable(ApiRouteContext): ApiResponse $handler
     */
    private function __construct(
        private readonly string $pathPattern,
        private readonly array $methods,
        private readonly mixed $handler,
        private readonly bool $regex,
    ) {}

    /**
     * @param list<string> $methods
     * @param callable(ApiRouteContext): ApiResponse $handler
     */
    public static function path(string $path, array $methods, callable $handler): self
    {
        return new self($path, $methods, $handler, false);
    }

    /**
     * @param list<string> $methods
     * @param callable(ApiRouteContext): ApiResponse $handler
     */
    public static function regex(string $pattern, array $methods, callable $handler): self
    {
        return new self($pattern, $methods, $handler, true);
    }

    public function matchesPath(string $path): bool
    {
        return $this->matchParams($path) !== null;
    }

    public function allowsMethod(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    public function dispatch(ApiRouteContext $context): ApiResponse
    {
        $params = $this->matchParams($context->path);
        if ($params === null) {
            return ApiResponse::error(404, 'not_found', 'Endpoint not found');
        }
        if (!$this->allowsMethod($context->method)) {
            return ApiResponse::error(405, 'method_not_allowed', 'Method not allowed');
        }

        $handler = $this->handler;

        return $handler(new ApiRouteContext(
            $context->method,
            $context->path,
            $context->query,
            $context->headers,
            $context->body,
            $params,
        ));
    }

    /**
     * @return list<string>|null
     */
    private function matchParams(string $path): ?array
    {
        if (!$this->regex) {
            return $path === $this->pathPattern ? [] : null;
        }

        if (preg_match($this->pathPattern, $path, $matches) !== 1) {
            return null;
        }
        array_shift($matches);

        return array_values(array_map(static fn(mixed $value): string => (string) $value, $matches));
    }
}
