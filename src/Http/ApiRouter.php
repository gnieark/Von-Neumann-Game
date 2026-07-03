<?php

declare(strict_types=1);

namespace VonNeumannGame\Http;

final class ApiRouter
{
    /**
     * @param list<ApiRoute> $routes
     */
    public function __construct(private readonly array $routes) {}

    public function dispatch(ApiRouteContext $context): ApiResponse
    {
        $methodNotAllowed = false;
        foreach ($this->routes as $route) {
            if (!$route->matchesPath($context->path)) {
                continue;
            }
            if (!$route->allowsMethod($context->method)) {
                $methodNotAllowed = true;
                continue;
            }

            return $route->dispatch($context);
        }

        return $methodNotAllowed
            ? ApiResponse::error(405, 'method_not_allowed', 'Method not allowed')
            : ApiResponse::error(404, 'not_found', 'Endpoint not found');
    }
}
