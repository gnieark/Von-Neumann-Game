<?php
namespace VonNeumannGame\FrontRoute;

class FrontRouteLogout extends FrontRoute{
    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        return "";
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        return "";
    }
}
