<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\AppFactory;

class FrontRouteLogout extends FrontRoute{
    private const SESSION_COOKIE = 'vn_session';

    public function handle(string $method, string $routePath, ?string $bearer, string $language): void
    {
        $token = (string) ($_COOKIE[self::SESSION_COOKIE] ?? '');
        if ($token !== '') {
            $factory = new AppFactory(dirname(__DIR__, 2));
            $auth = $factory->authService($factory->pdo(initializeSchema: true));
            $auth->revokeSessionToken($token);
        }

        setcookie(self::SESSION_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        header('Location: /', true, 303);
    }

    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        return "";
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        return "";
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
