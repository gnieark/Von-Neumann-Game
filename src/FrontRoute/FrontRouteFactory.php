<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\FrontRoute\FrontRoute;
use VonNeumannGame\FrontRoute\FrontRoutei18n;



class FrontRouteFactory{
    /*
    * Classe qui sert à créer les routes
    */
  

    static public function getRoute(array $availableRoutes, string $method, string $routePath, ?string $bearer, string $language): FrontRoute
    {
        foreach($availableRoutes as $route){
            if(in_array($method, $route['methods']) && preg_match($route['uriPattern'], $routePath)){
                $routeClass = $route['routeClass'];

                if (!str_contains($routeClass, '\\')) {
                    $routeClass = __NAMESPACE__ . '\\' . $routeClass;
                }

                return new $routeClass();
            }
        }
        // Si aucune route ne correspond, on retourne une route 404
        return new FrontRoute404();
    }



}
