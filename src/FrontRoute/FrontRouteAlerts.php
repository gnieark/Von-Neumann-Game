<?php
namespace VonNeumannGame\FrontRoute;

class FrontRouteAlerts extends FrontRoute{
    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        return "";
    }
}
