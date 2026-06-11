<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\I18n\Translator;
use VonNeumannGame\Repository\NeumannProbeRepository;
use VonNeumannGame\FrontRoute\FrontRouteFactory;

use VonNeumannGame\View\TplBlock;

require_once __DIR__ . '/../vendor/autoload.php';

const SESSION_COOKIE = 'vn_session';
const LANGUAGE_COOKIE = 'vn_lang';
const ASSET_VERSION = '20260611-tutorials';

$projectRoot = dirname(__DIR__);
$factory = new AppFactory($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['REQUEST_URI'] ?? '/';
$routePath = (string) (parse_url($path, PHP_URL_PATH) ?: '/');


// Handle API requests 
if (str_starts_with($routePath, '/api/')) {
    $kernel = $factory->apiKernel();
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $body = file_get_contents('php://input') ?: '';
    $response = $kernel->handle($method, $path, $headers, $body);

    http_response_code($response->status);
    foreach ($response->headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode($response->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}


// Handle frontend routes
function selectedLanguage(): string
{
    if (isset($_GET['lang'])) {
        return Translator::normalize((string) $_GET['lang']);
    }
    if (isset($_COOKIE[LANGUAGE_COOKIE])) {
        return Translator::normalize((string) $_COOKIE[LANGUAGE_COOKIE]);
    }

    return Translator::normalize($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
}

function selectedBearer(): ?string
{
    $token = (string) ($_COOKIE[SESSION_COOKIE] ?? '');
    if ($token === '') {
        return null;
    }

    return 'Bearer ' . $token;
}

$language = selectedLanguage();
$bearer = selectedBearer();
$translator = new Translator($language);

$availableroutes = [
    'home' => [
        'name' => 'home',
        'methods' => ['GET', 'HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/$#',
        'linkUri' => '/',
        'routeClass' => 'FrontRouteHome',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,
    ],
    'i18n' => [
        'name' => 'i18n',
        'methods' => ['GET', 'HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/i18n$#',
        'linkUri' => '/i18n',
        'routeClass' => 'FrontRoutei18n',
        'displayOnMainMenu' => false,
        'displayOnFooter' => false,

    ],
    'about' => [
        'name' => htmlspecialchars($translator->get('aboutPageTitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'methods' => ['GET', 'HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/about$#',
        'linkUri' => '/about',
        'routeClass' => 'FrontRouteAbout',
        'displayOnMainMenu' => false,
        'displayOnFooter' => true,
    ],
    'auth' =>[
        'name' => 'auth',
        'methods' => ['GET', 'POST'],
        'needAuth' => false,
        'uriPattern' => '#^/auth/(provider/[^/]+|pseudo)$#',
        'linkUri' => '/authbypwd',
        'routeClass' => 'FrontRouteAuth',
        'displayOnMainMenu' => false,
        'displayOnFooter' => false,
    ],
    'authbypwd' =>[
        'name' => 'authbypwd',
        'methods' => ['GET', 'POST'],
        'needAuth' => false,
        'uriPattern' => '#^/authbypwd$#',
        'linkUri' => '/authbypwd',
        'routeClass' => 'FrontRouteAuthByPwd',
        'displayOnMainMenu' => false,
        'displayOnFooter' => false,
    ],
    'logout' =>[
        'name' => 'logout',
        'methods' => ['GET', 'POST'],
        'needAuth' => true,
        'uriPattern' => '#^/logout$#',
        'linkUri' => '/logout',
        'routeClass' => 'FrontRouteLogout',
        'displayOnMainMenu' => false,
        'displayOnFooter' => false,
    ],
    "changelog" =>[
        'name'  => 'changelog',
        'methods' => ['GET','HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/changelog$#',
        'linkUri' => '/changelog',
        'routeClass' => 'FrontRouteChangelog',
        'displayOnMainMenu' => false,
        'displayOnFooter' => false,   
    ],
    "stats" => [
        'name'  =>   htmlspecialchars($translator->get('statsFooterLink'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'methods' => ['GET','HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/stats$#',
        'linkUri' => '/stats',
        'routeClass' => 'FrontRouteStats',
        'displayOnMainMenu' => false,
        'displayOnFooter' => true,   
    ],
    "api-docs" => [
        'name'  => 'API Docs',
        'methods' => ['GET','HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/(api-docs|openapi\.yaml)$#',
        'linkUri' => '/api-docs',
        'routeClass' => 'FrontRouteApiDocs',
        'displayOnMainMenu' => false,
        'displayOnFooter' => true,   
    ],
    "Sensors" => [
        'name'  => 'Sensors and radars',
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/sensors$#',
        'linkUri' => '/sensors',
        'routeClass' => 'FrontRouteSensors',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Inventories" => [
        'name'  => 'Inventories',
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/inventories$#',
        'linkUri' => '/inventories',
        'routeClass' => 'FrontRouteInventories',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Mannys" => [
        'name'  => 'Mannies & printer',
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/mannies$#',
        'linkUri' => '/mannies',
        'routeClass' => 'FrontRouteMannies',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Movement" => [
        'name'  => 'Movement',
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/movement(?:/-?\d+/-?\d+/-?\d+)?$#',
        'linkUri' => '/movement',
        'routeClass' => 'FrontRouteMovement',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Messaging" => [
        'name'  => 'Messaging',
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/messaging$#',
        'linkUri' => '/messaging',
        'routeClass' => 'FrontRouteMessaging',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Alerts" => [
        'name'  => 'Alerts',
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/alerts$#',
        'linkUri' => '/alerts',
        'routeClass' => 'FrontRouteAlerts',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ]

];
$route = FrontRouteFactory::getRoute($availableroutes, $method, $routePath, $bearer, $language);
$route->handle($method, $routePath, $bearer, $language);
