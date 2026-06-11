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

                //Add menus items
                foreach($availableRoutes as $route2){

                    if(isset($route2['displayOnMainMenu']) && $route2['displayOnMainMenu'] === true){
                        //(string $title, string $href, bool $active = false)
                        $active = $route['linkUri'] === $route2['linkUri']; 
                        $frontRoute->addLeftMenuItem(
                            new MenuLinkItem(
                            $route2['name'],
                            $route2['linkUri'],
                            $active
                        ));

                    }
                }



                return $frontRoute;
            }
        }




        // Si aucune route ne correspond, on retourne une route 404
        return new FrontRoute404();
    }



}
