<?php
namespace VonNeumannGame\FrontRoute;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use VonNeumannGame\AppFactory;
use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

class FrontRouteAuth extends FrontRoute{
    private const SESSION_COOKIE = 'vn_session';

    public function handle(string $method, string $routePath, ?string $bearer, string $language): void
    {
        $translator = new Translator(Translator::normalize($language));

        if (preg_match('#^/auth/provider/([^/]+)$#', $routePath, $matches) === 1) {
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                $this->methodNotAllowed();
                return;
            }

            $content = $this->handleOAuthProvider($translator, strtolower(rawurldecode($matches[1])));
            if ($content !== null) {
                echo $this->renderMainPage($content, null, $translator->language());
            }
            return;
        }

        if ($routePath === '/auth/pseudo') {
            $content = $this->handleOAuthPseudo($translator, $method);
            if ($content !== null) {
                echo $this->renderMainPage($content, null, $translator->language());
            }
            return;
        }

        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
    }

    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        if ($routePath === '/auth/pseudo') {
            return $this->handleOAuthPseudo($translator, $method) ?? '';
        }

        return '';
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        return "Von Neumann Game";
    }

    public function getMetaDescription(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return self::e($translator->get('homeMetaDescription'));
    }

    private function handleOAuthProvider(Translator $translator, string $providerName): ?string
    {
        $this->ensurePhpSession();
        $factory = $this->factory();

        try {
            $oauth = $factory->oauthService();
            $provider = $oauth->createProvider($providerName, $this->absoluteUrl('/auth/provider/' . rawurlencode($providerName)));
        } catch (Throwable) {
            return $this->renderLoginView($translator, $translator->get('oauthProviderUnavailable'));
        }

        if (isset($_GET['error'])) {
            unset($_SESSION['oauth2state'][$providerName]);
            return $this->renderLoginView($translator, $translator->get('oauthLoginCancelled'));
        }

        if (!isset($_GET['code'])) {
            $authorizationUrl = $provider->getAuthorizationUrl($oauth->authorizationOptions($providerName));
            $_SESSION['oauth2state'][$providerName] = $provider->getState();
            $_SESSION['oauth_remember'][$providerName] = (string) ($_GET['remember'] ?? '') === '1';
            $this->redirect($authorizationUrl);
            return null;
        }

        $expectedState = $_SESSION['oauth2state'][$providerName] ?? null;
        $actualState = isset($_GET['state']) ? (string) $_GET['state'] : '';
        unset($_SESSION['oauth2state'][$providerName]);
        if (!is_string($expectedState) || !hash_equals($expectedState, $actualState)) {
            return $this->renderLoginView($translator, $translator->get('oauthStateInvalid'));
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
                $this->issueSessionCookie($auth, $player, $this->oauthRememberChoice($providerName));
                $this->redirect('/');
                return null;
            }

            $_SESSION['pending_oauth'] = [
                'provider' => $providerName,
                'providerUserId' => $providerUserId,
                'csrf' => $this->randomToken(),
                'remember' => $this->oauthRememberChoice($providerName),
            ];
            $this->redirect('/auth/pseudo');
            return null;
        } catch (Throwable) {
            return $this->renderLoginView($translator, $translator->get('oauthLoginFailed'));
        }
    }

    private function handleOAuthPseudo(Translator $translator, string $method): ?string
    {
        $this->ensurePhpSession();
        $pending = $this->pendingOAuthIdentity();
        if ($pending === null) {
            $this->redirect('/');
            return null;
        }

        if ($method === 'GET' || $method === 'HEAD') {
            return $this->renderOAuthPseudo($translator, $pending['csrf']);
        }

        if ($method !== 'POST') {
            $this->methodNotAllowed();
            return null;
        }

        $pseudonym = (string) ($_POST['pseudonym'] ?? '');
        $csrf = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals($pending['csrf'], $csrf)) {
            return $this->renderOAuthPseudo($translator, $pending['csrf'], $translator->get('oauthStateInvalid'), $pseudonym);
        }

        $factory = $this->factory();
        $auth = $factory->authService($factory->pdo(initializeSchema: true));
        try {
            $player = $auth->registerPlayerWithExternalAuth($pseudonym, $pending['provider'], $pending['providerUserId']);
            unset($_SESSION['pending_oauth']);
            $this->issueSessionCookie($auth, $player, (bool) ($pending['remember'] ?? false));
            $this->redirect('/?tutorial=context');
            return null;
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage() === 'Pseudonym already exists.'
                ? $translator->get('pseudonymAlreadyUsed')
                : $translator->get('pseudonymInvalid');
            return $this->renderOAuthPseudo($translator, $pending['csrf'], $message, $pseudonym);
        } catch (Throwable) {
            $player = $auth->authenticateWithExternal($pending['provider'], $pending['providerUserId']);
            if ($player !== null) {
                unset($_SESSION['pending_oauth']);
                $this->issueSessionCookie($auth, $player, (bool) ($pending['remember'] ?? false));
                $this->redirect('/');
                return null;
            }

            return $this->renderOAuthPseudo($translator, $pending['csrf'], $translator->get('oauthRegistrationFailed'), $pseudonym);
        }
    }

    private function renderLoginView(Translator $translator, ?string $error = null): string
    {
        $projectRoot = $this->projectRoot();
        $tplLoginview = new TplBlock("loginview");
        $tplLoginview->dontReplaceNonGivenVars();

        if ($error !== null) {
            $tplLoginview->addSubBlock((new TplBlock('loginerror'))->addVars([
                'message' => self::e($error),
            ]));
        }

        $oauthProviderLinks = $this->oauthProviderLinks($translator);
        if ($oauthProviderLinks !== []) {
            $oauthSection = new TplBlock('oauthsection');
            foreach ($oauthProviderLinks as $provider) {
                $oauthSection->addSubBlock((new TplBlock('oauthprovider'))->addVars([
                    'class' => self::e($provider['class']),
                    'label' => self::e($provider['label']),
                    'url' => self::e($provider['url']),
                ]));
            }
            $tplLoginview->addSubBlock($oauthSection);
        } else {
            $tplLoginview->addSubBlock(new TplBlock('oauthmissing'));
        }

        $template = file_get_contents($projectRoot . '/templates/loginview.html');
        if ($template === false) {
            throw new \UnexpectedValueException('Cannot read login view template');
        }

        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());

        return $tpl->applyTplStr($tplLoginview->applyTplStr($template, 'loginview'));
    }

    private function renderOAuthPseudo(Translator $translator, string $csrf, ?string $error = null, string $pseudonym = ''): string
    {
        $tplPseudo = (new TplBlock("oauthpseudoview"))->addVars([
            'csrf' => self::e($csrf),
            'pseudonym' => self::e($pseudonym),
        ]);
        $tplPseudo->dontReplaceNonGivenVars();

        if ($error !== null) {
            $tplPseudo->addSubBlock((new TplBlock('error'))->addVars([
                'message' => self::e($error),
            ]));
        }

        $template = file_get_contents($this->projectRoot() . '/templates/oauthpseudoview.html');
        if ($template === false) {
            throw new \UnexpectedValueException('Cannot read OAuth pseudonym template');
        }

        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());

        return $tpl->applyTplStr($tplPseudo->applyTplStr($template, 'oauthpseudoview'));
    }

    /**
     * @return array<int, array{class: string, label: string, url: string}>
     */
    private function oauthProviderLinks(Translator $translator): array
    {
        try {
            $oauth = $this->factory()->oauthService();
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(string $provider): array => [
            'class' => $provider,
            'label' => match ($provider) {
                'google' => $translator->get('oauthLoginGoogle'),
                'discord' => $translator->get('oauthLoginDiscord'),
                'github' => $translator->get('oauthLoginGitHub'),
                default => $provider,
            },
            'url' => '/auth/provider/' . rawurlencode($provider),
        ], $oauth->availableProviders());
    }

    /**
     * @return array{provider: string, providerUserId: string, csrf: string, remember: bool}|null
     */
    private function pendingOAuthIdentity(): ?array
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

    private function oauthRememberChoice(string $providerName): bool
    {
        $remember = (bool) ($_SESSION['oauth_remember'][$providerName] ?? false);
        unset($_SESSION['oauth_remember'][$providerName]);

        return $remember;
    }

    private function issueSessionCookie(AuthService $auth, Player $player, bool $remember = false): void
    {
        $session = $auth->createSessionForPlayer($player);
        $expiresAt = new DateTimeImmutable((string) $session['expiresAt']);
        setcookie(self::SESSION_COOKIE, (string) $session['token'], [
            'expires' => $remember ? $expiresAt->getTimestamp() : 0,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private function ensurePhpSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => $this->isHttps(),
            'cookie_samesite' => 'Lax',
        ]);
    }

    private function absoluteUrl(string $path): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return ($this->isHttps() ? 'https' : 'http') . '://' . $host . $path;
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function redirect(string $location): void
    {
        header('Location: ' . $location, true, 303);
    }

    private function methodNotAllowed(): void
    {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method not allowed';
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private function factory(): AppFactory
    {
        return new AppFactory($this->projectRoot());
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
