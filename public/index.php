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
const ASSET_VERSION = '20260714-probe-logbook-ui';

$projectRoot = dirname(__DIR__);
$factory = new AppFactory($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['REQUEST_URI'] ?? '/';
$routePath = (string) (parse_url($path, PHP_URL_PATH) ?: '/');


// Handle API requests 
if (str_starts_with($routePath, '/api/')) {
    $apiPdo = $factory->pdo(initializeSchema: true);
    refreshRememberedSessionCookie($factory, $apiPdo);
    $kernel = $factory->apiKernel($apiPdo);
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

function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function refreshRememberedSessionCookie(AppFactory $factory, ?PDO $pdo = null): void
{
    $token = (string) ($_COOKIE[SESSION_COOKIE] ?? '');
    if ($token === '') {
        return;
    }

    $pdo ??= $factory->pdo(initializeSchema: true);
    $refresh = $factory
        ->authService($pdo)
        ->refreshRememberedSessionFromBearerToken('Bearer ' . $token);

    if ($refresh === null) {
        return;
    }

    $expiresAt = new DateTimeImmutable((string) $refresh['expiresAt']);
    setcookie(SESSION_COOKIE, (string) $refresh['token'], [
        'expires' => $expiresAt->getTimestamp(),
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function translatedRouteName(Translator $translator, string $key): string
{
    return $translator->get($key);
}

$language = selectedLanguage();
$bearer = selectedBearer();
$translator = new Translator($language);

if ($routePath !== '/i18n' && isset($_GET['lang']) && in_array((string) $_GET['lang'], Translator::supportedLanguages(), true)) {
    $language = Translator::normalize((string) $_GET['lang']);
    setcookie(LANGUAGE_COOKIE, $language, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Location: ' . ($routePath === '' ? '/' : $routePath), true, 303);
    return;
}

refreshRememberedSessionCookie($factory);

$availableroutes = [
    'home' => [
        'name' => translatedRouteName($translator, 'tabProbe'),
        'methods' => ['GET', 'HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/(?:\d+)?$#',
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
        'name' => translatedRouteName($translator, 'aboutFooterLink'),
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
        'name' => translatedRouteName($translator, 'logout'),
        'methods' => ['GET', 'POST'],
        'needAuth' => true,
        'uriPattern' => '#^/logout$#',
        'linkUri' => '/logout',
        'routeClass' => 'FrontRouteLogout',
        'displayOnMainMenu' => false,
        'displayOnFooter' => false,
    ],
    "changelog" =>[
        'name'  => translatedRouteName($translator, 'changelogFooterLink'),
        'methods' => ['GET','HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/changelog$#',
        'linkUri' => '/changelog',
        'routeClass' => 'FrontRouteChangelog',
        'displayOnMainMenu' => false,
        'displayOnFooter' => true,
    ],
    "stats" => [
        'name'  => translatedRouteName($translator, 'statsFooterLink'),
        'methods' => ['GET','HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/stats$#',
        'linkUri' => '/stats',
        'routeClass' => 'FrontRouteStats',
        'displayOnMainMenu' => false,
        'displayOnFooter' => true,
    ],
    "api-docs" => [
        'name'  => translatedRouteName($translator, 'apiDocsFooterLink'),
        'methods' => ['GET','HEAD'],
        'needAuth' => false,
        'uriPattern' => '#^/(api-docs|openapi\.yaml)$#',
        'linkUri' => '/api-docs',
        'routeClass' => 'FrontRouteApiDocs',
        'displayOnMainMenu' => false,
        'displayOnFooter' => true,
    ],
    "Sensors" => [
        'name'  => translatedRouteName($translator, 'tabEnvironment'),
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/sensors(?:/\d+)?$#',
        'linkUri' => '/sensors',
        'routeClass' => 'FrontRouteSensors',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Inventories" => [
        'name'  => translatedRouteName($translator, 'tabSystems'),
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/inventories(?:/\d+)?$#',
        'linkUri' => '/inventories',
        'routeClass' => 'FrontRouteInventories',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Mannys" => [
        'name'  => translatedRouteName($translator, 'tabMannies'),
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/mannies(?:/\d+)?$#',
        'linkUri' => '/mannies',
        'routeClass' => 'FrontRouteMannies',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Movement" => [
        'name'  => translatedRouteName($translator, 'tabActions'),
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/movement(?:/\d+(?:/-?\d+/-?\d+/-?\d+)?|/-?\d+/-?\d+/-?\d+)?$#',
        'linkUri' => '/movement',
        'routeClass' => 'FrontRouteMovement',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "SCUT" => [
        'name'  => 'SCUT',
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/scut(?:/\d+)?$#',
        'linkUri' => '/scut',
        'routeClass' => 'FrontRouteScut',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,
    ],
    "Messaging" => [
        'name'  => translatedRouteName($translator, 'tabMessages'),
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/messaging(?:/\d+)?$#',
        'linkUri' => '/messaging',
        'routeClass' => 'FrontRouteMessaging',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Alerts" => [
        'name'  => translatedRouteName($translator, 'tabAlerts'),
        'methods' => ['GET','HEAD'],
        'needAuth' => true,
        'uriPattern' => '#^/alerts(?:/\d+)?$#',
        'linkUri' => '/alerts',
        'routeClass' => 'FrontRouteAlerts',
        'displayOnMainMenu' => true,
        'displayOnFooter' => false,   
    ],
    "Forum" => [
        'name'  => translatedRouteName($translator, 'tabForum'),
        'methods' => ['GET','POST'],
        'needAuth' => true,
        'uriPattern' => '#^/forum$#',
        'linkUri' => '/forum',
        'routeClass' => 'FrontRouteForum',
        'displayOnMainMenu' => false,
        'displayOnFooter' => true,
    ]

];
$route = FrontRouteFactory::getRoute($availableroutes, $method, $routePath, $bearer, $language);
$route->handle($method, $routePath, $bearer, $language);
