<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\I18n\Translator;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../class/TplBlock.php';

const SESSION_COOKIE = 'vn_session';
const LANGUAGE_COOKIE = 'vn_lang';

$projectRoot = dirname(__DIR__);
$factory = new AppFactory($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['REQUEST_URI'] ?? '/';
$routePath = (string) (parse_url($path, PHP_URL_PATH) ?: '/');
$language = selectedLanguage();
$translator = new Translator($language);

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

if (isset($_GET['lang']) && in_array((string) $_GET['lang'], Translator::supportedLanguages(), true)) {
    $language = Translator::normalize((string) $_GET['lang']);
    setcookie(LANGUAGE_COOKIE, $language, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    redirect($routePath === '' ? '/' : $routePath);
    return;
}

if ($routePath === '/login' && $method === 'POST') {
    $pdo = $factory->pdo(initializeSchema: true);
    $auth = $factory->authService($pdo);
    $player = $auth->authenticateWithPassword((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($player === null) {
        renderHome($projectRoot, $translator, null, $translator->get('loginInvalid'));
        return;
    }

    $session = $auth->createSessionForPlayer($player);
    $expiresAt = new DateTimeImmutable((string) $session['expiresAt']);
    $remember = isset($_POST['remember']);
    setcookie(SESSION_COOKIE, (string) $session['token'], [
        'expires' => $remember ? $expiresAt->getTimestamp() : 0,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    redirect('/');
    return;
}

if ($routePath === '/logout' && $method === 'POST') {
    setcookie(SESSION_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    redirect('/');
    return;
}

if ($routePath !== '/' || $method !== 'GET') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return;
}

renderHome($projectRoot, $translator, currentPlayer($factory));

function renderHome(string $projectRoot, Translator $translator, ?Player $player, ?string $loginError = null): void
{
    $tpl = new TplBlock();
    $tpl->addVars([
        'pageTitle' => 'Von Neumann Game',
        'bodyClass' => $player === null ? 'is-guest' : 'is-authenticated',
        'authenticated' => $player === null ? '0' : '1',
        'language' => $translator->language(),
        'i18nJson' => json_encode($translator->jsMessages(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        'frSelected' => $translator->language() === 'fr' ? 'selected' : '',
        'enSelected' => $translator->language() === 'en' ? 'selected' : '',
    ]);
    $tpl->addPrefixedVars('t', $translator->allEscaped());

    if ($player === null) {
        $loginView = new TplBlock('loginview');
        if ($loginError !== null) {
            $loginView->addSubBlock((new TplBlock('loginerror'))->addVars([
                'message' => e($loginError),
            ]));
        }
        $tpl->addSubBlock($loginView);
    } else {
        $displayName = e($player->displayName ?? $player->username);
        $tpl->addSubBlock((new TplBlock('sessionbar'))->addVars([
            'displayName' => $displayName,
        ]));
        $tpl->addSubBlock((new TplBlock('consoleview'))->addVars([
            'displayName' => $displayName,
        ]));
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $tpl->applyTplFile($projectRoot . '/templates/home.html');
}

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

function currentPlayer(AppFactory $factory): ?Player
{
    $token = (string) ($_COOKIE[SESSION_COOKIE] ?? '');
    if ($token === '') {
        return null;
    }

    return $factory->authService($factory->pdo(initializeSchema: true))->getPlayerFromBearerToken('Bearer ' . $token);
}

function redirect(string $location): void
{
    header('Location: ' . $location, true, 303);
}

function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
