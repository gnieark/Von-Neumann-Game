<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\Config\JsonConfigLoader;
use VonNeumannGame\FrontRoute\FrontRoute;
use VonNeumannGame\FrontRoute\FrontRoutei18n;
use VonNeumannGame\FrontRoute\MenuLinkItem;



class FrontRouteFactory{
    /*
    * Classe qui sert à créer les routes
    */
  

    static public function getRoute(array $availableRoutes, string $method, string $routePath, ?string $bearer, string $language, ?string $projectRoot = null): FrontRoute
    {
        $projectRoot ??= dirname(__DIR__, 2);

        foreach($availableRoutes as $route){
            if(in_array($method, $route['methods']) && preg_match($route['uriPattern'], $routePath)){
                $routeClass = $route['routeClass'];

                if (!str_contains($routeClass, '\\')) {
                    $routeClass = __NAMESPACE__ . '\\' . $routeClass;
                }

                $frontRoute = new $routeClass();
                self::addMenuItems($frontRoute, $availableRoutes, $route, $projectRoot);

                return $frontRoute;
            }
        }




        // Si aucune route ne correspond, on retourne une route 404
        $frontRoute = new FrontRoute404();
        self::addMenuItems($frontRoute, $availableRoutes, null, $projectRoot);

        return $frontRoute;
    }

    private static function addMenuItems(FrontRoute $frontRoute, array $availableRoutes, ?array $activeRoute, string $projectRoot): void
    {

        //custom footer menu items from config
        foreach (self::additionalFooterMenuItems($projectRoot) as $menuItem) {
            $frontRoute->addFooterMenuItem($menuItem);
        }

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

    /**
     * @return array<MenuLinkItem>
     */
    private static function additionalFooterMenuItems(string $projectRoot): array
    {
        $config = (new JsonConfigLoader($projectRoot))->load('additionalsfooterlinks');
        $links = isset($config['links']) && is_array($config['links']) ? $config['links'] : $config;
        $menuItems = [];

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $title = $link['label'] ?? $link['title'] ?? $link['name'] ?? null;
            $href = $link['url'] ?? $link['href'] ?? $link['linkUri'] ?? null;
            if (!is_string($title) || trim($title) === '' || !is_string($href) || trim($href) === '') {
                continue;
            }

            $href = trim($href);
            if (!str_starts_with($href, '/') && filter_var($href, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $type = $link['type'] ?? null;
            $external = $type === 'external' || (filter_var($href, FILTER_VALIDATE_URL) !== false && !str_starts_with($href, '/'));
            $menuItems[] = new MenuLinkItem(trim($title), $href, false, $external);
        }

        return $menuItems;
    }


}
