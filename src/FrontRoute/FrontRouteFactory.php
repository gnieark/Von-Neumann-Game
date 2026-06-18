<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\FrontRoute\FrontRoute;
use VonNeumannGame\FrontRoute\FrontRoutei18n;
use VonNeumannGame\FrontRoute\MenuLinkItem;



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

                $frontRoute = new $routeClass();
                self::addMenuItems($frontRoute, $availableRoutes, $route);

                return $frontRoute;
            }
        }




        // Si aucune route ne correspond, on retourne une route 404
        $frontRoute = new FrontRoute404();
        self::addMenuItems($frontRoute, $availableRoutes, null);

        return $frontRoute;
    }

    private static function addMenuItems(FrontRoute $frontRoute, array $availableRoutes, ?array $activeRoute): void
    {
        //Add menus items
        foreach($availableRoutes as $route){

            if(isset($route['displayOnMainMenu']) && $route['displayOnMainMenu'] === true){
                //(string $title, string $href, bool $active = false)
                $active = $activeRoute !== null && $activeRoute['linkUri'] === $route['linkUri'];
                $frontRoute->addLeftMenuItem(
                    new MenuLinkItem(
                    $route['name'],
                    $route['linkUri'],
                    $active
                ));

            }
            if(isset($route['displayOnFooter']) && $route['displayOnFooter'] === true){
                $frontRoute->addFooterMenuItem(
                    new MenuLinkItem(
                    $route['name'],
                    $route['linkUri']
                ));

            }
        }
    }


}
