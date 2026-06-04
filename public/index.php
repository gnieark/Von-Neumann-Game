<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

require_once __DIR__ . '/../vendor/autoload.php';

const SESSION_COOKIE = 'vn_session';
const LANGUAGE_COOKIE = 'vn_lang';
const ASSET_VERSION = '20260604-changelog';

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

if ($method === 'GET' && preg_match('#^/auth/provider/([^/]+)$#', $routePath, $matches) === 1) {
    handleOAuthProvider($factory, $projectRoot, $translator, strtolower(rawurldecode($matches[1])));
    return;
}

if ($routePath === '/auth/pseudo') {
    handleOAuthPseudo($factory, $projectRoot, $translator, $method);
    return;
}

if ($routePath === '/authbypwd') {
    handlePasswordAuth($factory, $projectRoot, $translator, $method);
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

if ($routePath === '/about' && in_array($method, ['GET', 'HEAD'], true)) {
    renderAbout($projectRoot, $translator, currentPlayer($factory));
    return;
}

if ($routePath === '/changelog' && in_array($method, ['GET', 'HEAD'], true)) {
    renderChangelog($projectRoot, $translator, currentPlayer($factory));
    return;
}

if ($routePath === '/api-docs' && in_array($method, ['GET', 'HEAD'], true)) {
    renderApiDocs($projectRoot, $translator, currentPlayer($factory));
    return;
}

if ($routePath === '/openapi.yaml' && in_array($method, ['GET', 'HEAD'], true)) {
    renderOpenApiSpec($projectRoot, $method);
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
        'metaDescription' => e($translator->get('homeMetaDescription')),
        'bodyClass' => $player === null ? 'is-login is-guest' : 'is-authenticated',
        'authenticated' => $player === null ? '0' : '1',
        'language' => $translator->language(),
        'assetVersion' => ASSET_VERSION,
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
        $oauthProviderLinks = oauthProviderLinks($projectRoot, $translator);
        if ($oauthProviderLinks !== []) {
            $oauthSection = new TplBlock('oauthsection');
            foreach ($oauthProviderLinks as $provider) {
                $oauthSection->addSubBlock((new TplBlock('oauthprovider'))->addVars([
                    'class' => e($provider['class']),
                    'label' => e($provider['label']),
                    'url' => e($provider['url']),
                ]));
            }
            $loginView->addSubBlock($oauthSection);
        } else {
            $loginView->addSubBlock(new TplBlock('oauthmissing'));
        }
        $tpl->addSubBlock($loginView);
    } else {
        $displayName = e($player->displayName ?? $player->username);
        addAuthenticatedUi($tpl, $displayName);
        $tpl->addSubBlock((new TplBlock('consoleview'))->addVars([
            'displayName' => $displayName,
        ]));
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $tpl->applyTplFile($projectRoot . '/templates/home.html');
}

function renderPasswordAuth(string $projectRoot, Translator $translator, ?string $loginError = null): void
{
    $tpl = new TplBlock();
    $tpl->addVars([
        'pageTitle' => 'Von Neumann Game',
        'metaDescription' => e($translator->get('homeMetaDescription')),
        'bodyClass' => 'is-guest',
        'authenticated' => '0',
        'language' => $translator->language(),
        'assetVersion' => ASSET_VERSION,
        'i18nJson' => json_encode($translator->jsMessages(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        'frSelected' => $translator->language() === 'fr' ? 'selected' : '',
        'enSelected' => $translator->language() === 'en' ? 'selected' : '',
    ]);
    $tpl->addPrefixedVars('t', $translator->allEscaped());

    $passwordView = new TplBlock('passwordauthview');
    if ($loginError !== null) {
        $passwordView->addSubBlock((new TplBlock('loginerror'))->addVars([
            'message' => e($loginError),
        ]));
    }
    $tpl->addSubBlock($passwordView);

    header('Content-Type: text/html; charset=utf-8');
    echo $tpl->applyTplFile($projectRoot . '/templates/home.html');
}

function handlePasswordAuth(AppFactory $factory, string $projectRoot, Translator $translator, string $method): void
{
    if ($method === 'GET') {
        if (currentPlayer($factory) !== null) {
            redirect('/');
            return;
        }

        renderPasswordAuth($projectRoot, $translator);
        return;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method not allowed';
        return;
    }

    $auth = $factory->authService($factory->pdo(initializeSchema: true));
    $player = $auth->authenticateWithPassword((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($player === null) {
        renderPasswordAuth($projectRoot, $translator, $translator->get('loginInvalid'));
        return;
    }

    issueSessionCookie($auth, $player, isset($_POST['remember']));
    header('Location: /', true, 303);
}

function renderAbout(string $projectRoot, Translator $translator, ?Player $player): void
{
    $tpl = new TplBlock();
    $tpl->addVars([
        'pageTitle' => 'Von Neumann Game - ' . $translator->get('aboutFooterLink'),
        'metaDescription' => e($translator->get('aboutMetaDescription')),
        'bodyClass' => $player === null ? 'is-guest' : 'is-authenticated',
        'authenticated' => $player === null ? '0' : '1',
        'language' => $translator->language(),
        'assetVersion' => ASSET_VERSION,
        'i18nJson' => json_encode($translator->jsMessages(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        'frSelected' => $translator->language() === 'fr' ? 'selected' : '',
        'enSelected' => $translator->language() === 'en' ? 'selected' : '',
    ]);
    $tpl->addPrefixedVars('t', $translator->allEscaped());
    $tpl->addSubBlock(new TplBlock('aboutview'));

    if ($player !== null) {
        addAuthenticatedUi($tpl, e($player->displayName ?? $player->username));
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $tpl->applyTplFile($projectRoot . '/templates/home.html');
}

function renderChangelog(string $projectRoot, Translator $translator, ?Player $player): void
{
    $path = $projectRoot . '/CHANGELOG.md';
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Changelog not found';
        return;
    }

    $tpl = new TplBlock();
    $tpl->addVars([
        'pageTitle' => 'Von Neumann Game - ' . $translator->get('changelogFooterLink'),
        'metaDescription' => e($translator->get('changelogMetaDescription')),
        'bodyClass' => $player === null ? 'is-guest' : 'is-authenticated',
        'authenticated' => $player === null ? '0' : '1',
        'language' => $translator->language(),
        'assetVersion' => ASSET_VERSION,
        'i18nJson' => json_encode($translator->jsMessages(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        'frSelected' => $translator->language() === 'fr' ? 'selected' : '',
        'enSelected' => $translator->language() === 'en' ? 'selected' : '',
    ]);
    $tpl->addPrefixedVars('t', $translator->allEscaped());
    $tpl->addSubBlock((new TplBlock('changelogview'))->addVars([
        'html' => renderMarkdownHtml((string) file_get_contents($path)),
    ]));

    if ($player !== null) {
        addAuthenticatedUi($tpl, e($player->displayName ?? $player->username));
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $tpl->applyTplFile($projectRoot . '/templates/home.html');
}

function renderApiDocs(string $projectRoot, Translator $translator, ?Player $player): void
{
    $tpl = new TplBlock();
    $tpl->addVars([
        'pageTitle' => 'Von Neumann Game - API',
        'metaDescription' => e($translator->get('apiDocsMetaDescription')),
        'bodyClass' => $player === null ? 'is-api-docs is-guest' : 'is-api-docs is-authenticated',
        'authenticated' => $player === null ? '0' : '1',
        'language' => $translator->language(),
        'assetVersion' => ASSET_VERSION,
        'i18nJson' => json_encode($translator->jsMessages(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        'frSelected' => $translator->language() === 'fr' ? 'selected' : '',
        'enSelected' => $translator->language() === 'en' ? 'selected' : '',
    ]);
    $tpl->addPrefixedVars('t', $translator->allEscaped());
    $tpl->addSubBlock(new TplBlock('swaggerassets'));
    $tpl->addSubBlock(new TplBlock('apidocsview'));

    if ($player !== null) {
        addAuthenticatedUi($tpl, e($player->displayName ?? $player->username));
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $tpl->applyTplFile($projectRoot . '/templates/home.html');
}

function renderOpenApiSpec(string $projectRoot, string $method): void
{
    $path = $projectRoot . '/docs/openapi.yaml';
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OpenAPI specification not found';
        return;
    }

    header('Content-Type: application/yaml; charset=utf-8');
    header('Cache-Control: no-store');
    if ($method !== 'HEAD') {
        readfile($path);
    }
}

function renderOAuthPseudo(string $projectRoot, Translator $translator, string $csrf, ?string $error = null, string $pseudonym = ''): void
{
    $tpl = new TplBlock();
    $tpl->addVars([
        'pageTitle' => 'Von Neumann Game',
        'metaDescription' => e($translator->get('homeMetaDescription')),
        'bodyClass' => 'is-guest',
        'authenticated' => '0',
        'language' => $translator->language(),
        'assetVersion' => ASSET_VERSION,
        'i18nJson' => json_encode($translator->jsMessages(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        'frSelected' => $translator->language() === 'fr' ? 'selected' : '',
        'enSelected' => $translator->language() === 'en' ? 'selected' : '',
    ]);
    $tpl->addPrefixedVars('t', $translator->allEscaped());

    $pseudoView = (new TplBlock('oauthpseudoview'))->addVars([
        'csrf' => e($csrf),
        'pseudonym' => e($pseudonym),
    ]);
    if ($error !== null) {
        $pseudoView->addSubBlock((new TplBlock('error'))->addVars([
            'message' => e($error),
        ]));
    }
    $tpl->addSubBlock($pseudoView);

    header('Content-Type: text/html; charset=utf-8');
    echo $tpl->applyTplFile($projectRoot . '/templates/home.html');
}

function handleOAuthProvider(AppFactory $factory, string $projectRoot, Translator $translator, string $providerName): void
{
    ensurePhpSession();

    try {
        $oauth = $factory->oauthService();
        $provider = $oauth->createProvider($providerName, absoluteUrl('/auth/provider/' . rawurlencode($providerName)));
    } catch (Throwable) {
        renderHome($projectRoot, $translator, null, $translator->get('oauthProviderUnavailable'));
        return;
    }

    if (isset($_GET['error'])) {
        unset($_SESSION['oauth2state'][$providerName]);
        renderHome($projectRoot, $translator, null, $translator->get('oauthLoginCancelled'));
        return;
    }

    if (!isset($_GET['code'])) {
        $authorizationUrl = $provider->getAuthorizationUrl($oauth->authorizationOptions($providerName));
        $_SESSION['oauth2state'][$providerName] = $provider->getState();
        $_SESSION['oauth_remember'][$providerName] = (string) ($_GET['remember'] ?? '') === '1';
        header('Location: ' . $authorizationUrl);
        return;
    }

    $expectedState = $_SESSION['oauth2state'][$providerName] ?? null;
    $actualState = isset($_GET['state']) ? (string) $_GET['state'] : '';
    unset($_SESSION['oauth2state'][$providerName]);
    if (!is_string($expectedState) || !hash_equals($expectedState, $actualState)) {
        renderHome($projectRoot, $translator, null, $translator->get('oauthStateInvalid'));
        return;
    }

    try {
        $token = $provider->getAccessToken('authorization_code', [
            'code' => (string) $_GET['code'],
        ]);
        $providerUserId = $oauth->subjectFromAccessToken($providerName, $token);
        $auth = $factory->authService($factory->pdo(initializeSchema: true));
        $player = $auth->authenticateWithExternal($providerName, $providerUserId);
        if ($player !== null) {
            unset($_SESSION['pending_oauth']);
            issueSessionCookie($auth, $player, oauthRememberChoice($providerName));
            redirect('/');
            return;
        }

        $_SESSION['pending_oauth'] = [
            'provider' => $providerName,
            'providerUserId' => $providerUserId,
            'csrf' => randomToken(),
            'remember' => oauthRememberChoice($providerName),
        ];
        redirect('/auth/pseudo');
    } catch (Throwable) {
        renderHome($projectRoot, $translator, null, $translator->get('oauthLoginFailed'));
    }
}

function handleOAuthPseudo(AppFactory $factory, string $projectRoot, Translator $translator, string $method): void
{
    ensurePhpSession();
    $pending = pendingOAuthIdentity();
    if ($pending === null) {
        redirect('/');
        return;
    }

    if ($method === 'GET') {
        renderOAuthPseudo($projectRoot, $translator, $pending['csrf']);
        return;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method not allowed';
        return;
    }

    $pseudonym = (string) ($_POST['pseudonym'] ?? '');
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals($pending['csrf'], $csrf)) {
        renderOAuthPseudo($projectRoot, $translator, $pending['csrf'], $translator->get('oauthStateInvalid'), $pseudonym);
        return;
    }

    $auth = $factory->authService($factory->pdo(initializeSchema: true));
    try {
        $player = $auth->registerPlayerWithExternalAuth($pseudonym, $pending['provider'], $pending['providerUserId']);
        unset($_SESSION['pending_oauth']);
        issueSessionCookie($auth, $player, (bool) ($pending['remember'] ?? false));
        redirect('/');
    } catch (InvalidArgumentException $exception) {
        $message = $exception->getMessage() === 'Pseudonym already exists.'
            ? $translator->get('pseudonymAlreadyUsed')
            : $translator->get('pseudonymInvalid');
        renderOAuthPseudo($projectRoot, $translator, $pending['csrf'], $message, $pseudonym);
    } catch (Throwable) {
        $player = $auth->authenticateWithExternal($pending['provider'], $pending['providerUserId']);
        if ($player !== null) {
            unset($_SESSION['pending_oauth']);
            issueSessionCookie($auth, $player, (bool) ($pending['remember'] ?? false));
            redirect('/');
            return;
        }

        renderOAuthPseudo($projectRoot, $translator, $pending['csrf'], $translator->get('oauthRegistrationFailed'), $pseudonym);
    }
}

/**
 * @return array<int, array{class: string, label: string, url: string}>
 */
function oauthProviderLinks(string $projectRoot, Translator $translator): array
{
    try {
        $oauth = new VonNeumannGame\Auth\OAuthService(
            VonNeumannGame\Auth\OAuthConfig::fromFile($projectRoot . '/config/oauth.json')
        );
    } catch (Throwable) {
        return [];
    }

    return array_map(static fn(string $provider): array => [
        'class' => $provider,
        'label' => match ($provider) {
            'google' => $translator->get('oauthLoginGoogle'),
            'discord' => $translator->get('oauthLoginDiscord'),
            default => $provider,
        },
        'url' => '/auth/provider/' . rawurlencode($provider),
    ], $oauth->availableProviders());
}

/**
 * @return array{provider: string, providerUserId: string, csrf: string}|null
 */
function pendingOAuthIdentity(): ?array
{
    $pending = $_SESSION['pending_oauth'] ?? null;
    if (!is_array($pending)
        || !isset($pending['provider'], $pending['providerUserId'], $pending['csrf'])
        || !is_string($pending['provider'])
        || !is_string($pending['providerUserId'])
        || !is_string($pending['csrf'])
    ) {
        return null;
    }

    return [
        'provider' => $pending['provider'],
        'providerUserId' => $pending['providerUserId'],
        'csrf' => $pending['csrf'],
        'remember' => (bool) ($pending['remember'] ?? false),
    ];
}

function oauthRememberChoice(string $providerName): bool
{
    $remember = (bool) ($_SESSION['oauth_remember'][$providerName] ?? false);
    unset($_SESSION['oauth_remember'][$providerName]);

    return $remember;
}

function addAuthenticatedUi(TplBlock $tpl, string $displayName): void
{
    $tpl->addSubBlock((new TplBlock('sessionbar'))->addVars([
        'displayName' => $displayName,
    ]));
    $tpl->addSubBlock(new TplBlock('apikeydialog'));
}

function renderMarkdownHtml(string $markdown): string
{
    $lines = preg_split('/\R/', $markdown) ?: [];
    $html = [];
    $paragraph = [];
    $listOpen = false;

    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if ($paragraph === []) {
            return;
        }

        $html[] = '<p>' . renderMarkdownInline(implode(' ', $paragraph)) . '</p>';
        $paragraph = [];
    };
    $closeList = static function () use (&$html, &$listOpen): void {
        if (!$listOpen) {
            return;
        }

        $html[] = '</ul>';
        $listOpen = false;
    };

    foreach ($lines as $line) {
        $line = rtrim($line);
        if (trim($line) === '') {
            $flushParagraph();
            $closeList();
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches) === 1) {
            $flushParagraph();
            $closeList();
            $level = strlen($matches[1]);
            $html[] = '<h' . $level . '>' . renderMarkdownInline(trim($matches[2])) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^-\s+(.+)$/', $line, $matches) === 1) {
            $flushParagraph();
            if (!$listOpen) {
                $html[] = '<ul>';
                $listOpen = true;
            }
            $html[] = '<li>' . renderMarkdownInline(trim($matches[1])) . '</li>';
            continue;
        }

        $paragraph[] = trim($line);
    }

    $flushParagraph();
    $closeList();

    return implode("\n", $html);
}

function renderMarkdownInline(string $text): string
{
    $segments = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    $html = '';
    foreach ($segments as $segment) {
        if (str_starts_with($segment, '`') && str_ends_with($segment, '`') && strlen($segment) >= 2) {
            $html .= '<code>' . e(substr($segment, 1, -1)) . '</code>';
            continue;
        }

        $html .= renderMarkdownLinks($segment);
    }

    return $html;
}

function renderMarkdownLinks(string $text): string
{
    if (preg_match_all('/\[([^\]]+)\]\(([^)\s]+)\)/', $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
        return e($text);
    }

    $html = '';
    $offset = 0;
    foreach ($matches[0] as $index => $match) {
        [$whole, $position] = $match;
        $html .= e(substr($text, $offset, $position - $offset));
        $label = $matches[1][$index][0];
        $url = $matches[2][$index][0];
        $html .= preg_match('#^(https?://|/)#', $url) === 1
            ? '<a href="' . e($url) . '">' . e($label) . '</a>'
            : e($whole);
        $offset = $position + strlen($whole);
    }

    return $html . e(substr($text, $offset));
}

function issueSessionCookie(VonNeumannGame\Auth\AuthService $auth, Player $player, bool $remember = false): void
{
    $session = $auth->createSessionForPlayer($player);
    $expiresAt = new DateTimeImmutable((string) $session['expiresAt']);
    setcookie(SESSION_COOKIE, (string) $session['token'], [
        'expires' => $remember ? $expiresAt->getTimestamp() : 0,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function ensurePhpSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isHttps(),
        'cookie_samesite' => 'Lax',
    ]);
}

function absoluteUrl(string $path): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return (isHttps() ? 'https' : 'http') . '://' . $host . $path;
}

function randomToken(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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
