<?php
namespace VonNeumannGame\FrontRoute;

use DateTimeImmutable;
use PDOException;
use VonNeumannGame\AppFactory;
use VonNeumannGame\Auth\AuthService;
use VonNeumannGame\Domain\Player;
use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

class FrontRouteAuthByPwd extends FrontRoute{
    private const SESSION_COOKIE = 'vn_session';

    public function handle(string $method, string $routePath, ?string $bearer, string $language): void
    {
        $translator = new Translator(Translator::normalize($language));

        if ($method === 'GET') {
            if ($this->currentPlayer($bearer) !== null) {
                $this->redirect('/');
                return;
            }

            echo $this->renderMainPage($this->renderPasswordAuth($translator), null, $translator->language());
            return;
        }

        if ($method !== 'POST') {
            $this->methodNotAllowed();
            return;
        }

        try {
            $auth = $this->authService();
            $player = $auth->authenticateWithPassword(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? '')
            );
        } catch (PDOException) {
            $this->setAuthenticationUnavailableStatus();
            echo $this->renderMainPage(
                $this->renderPasswordAuth($translator, $translator->get('passwordAuthUnavailable')),
                null,
                $translator->language()
            );
            return;
        }

        if ($player === null) {
            echo $this->renderMainPage(
                $this->renderPasswordAuth($translator, $translator->get('loginInvalid')),
                null,
                $translator->language()
            );
            return;
        }

        $this->issueSessionCookie($auth, $player, isset($_POST['remember']));
        $this->redirect('/');
    }

    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return $this->renderPasswordAuth($translator);
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

    private function renderPasswordAuth(Translator $translator, ?string $loginError = null): string
    {
        $passwordView = new TplBlock('passwordauthview');
        $passwordView->dontReplaceNonGivenVars();

        if ($loginError !== null) {
            $passwordView->addSubBlock((new TplBlock('loginerror'))->addVars([
                'message' => self::e($loginError),
            ]));
        }

        $template = file_get_contents($this->projectRoot() . '/templates/authbypwd.html');
        if ($template === false) {
            throw new \UnexpectedValueException('Cannot read password auth template');
        }

        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());

        return $tpl->applyTplStr($passwordView->applyTplStr($template, 'passwordauthview'));
    }

    private function currentPlayer(?string $bearer): ?Player
    {
        if ($bearer === null) {
            return null;
        }

        $factory = $this->factory();

        return $factory
            ->authService($factory->pdo(initializeSchema: true))
            ->getPlayerFromBearerToken($bearer);
    }

    private function issueSessionCookie(AuthService $auth, Player $player, bool $remember = false): void
    {
        $session = $auth->createSessionForPlayer($player, $remember);
        $expiresAt = new DateTimeImmutable((string) $session['expiresAt']);
        setcookie(self::SESSION_COOKIE, (string) $session['token'], [
            'expires' => $remember ? $expiresAt->getTimestamp() : 0,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    protected function authService(): AuthService
    {
        $factory = $this->factory();

        return $factory->authService($factory->pdo(initializeSchema: true));
    }

    protected function setAuthenticationUnavailableStatus(): void
    {
        http_response_code(503);
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
