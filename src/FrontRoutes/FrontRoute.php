<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\View\TplBlock;


class FrontRoute{
    /*
    * Classe mère, sert à centraliser des méthodes pour les classes filles
    */

    private $availableRoutes = [];

    public function __construct()
    {
        
    }
    public function addAvailableRoute(string $method, string $uriPattern, string $routeClass): void
    {
        $this->availableRoutes[] = [
            'method' => $method,
            'uriPattern' => $uriPattern,
            'routeClass' => $routeClass
        ];
    }

    public function handle(string $method, string $routePath): void
    {

        if(isset($this->displayOnMainPage) && $this->displayOnMainPage === false){
            // Si la route n'est pas censée être affichée sur la page principale, on l'affiche seule
            echo $this->getContent($method, $routePath);
        }
        else{
            $tpl = new TplBlock("");




        }
        
    }

}