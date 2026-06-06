<?php

declare(strict_types=1);

namespace VonNeumannGame\Auth;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Token\AccessToken;
use Wohali\OAuth2\Client\Provider\Discord;

final class OAuthService
{
    public function __construct(private readonly OAuthConfig $config) {}

    /**
     * @return array<string>
     */
    public function availableProviders(): array
    {
        return $this->config->providerNames();
    }

    public function createProvider(string $provider, string $defaultRedirectUri): AbstractProvider
    {
        $provider = strtolower(trim($provider));
        $credentials = $this->config->credentials($provider);
        if ($credentials === null) {
            throw new \InvalidArgumentException('OAuth provider is not configured.');
        }

        $options = [
            'clientId' => $credentials['clientId'],
            'clientSecret' => $credentials['clientSecret'],
            'redirectUri' => $credentials['redirectUri'] ?? $defaultRedirectUri,
        ];

        return match ($provider) {
            'google' => new Google($options),
            'discord' => new Discord($options),
            'github' => new MinimalGithubProvider($options),
            default => throw new \InvalidArgumentException('Unsupported OAuth provider.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizationOptions(string $provider): array
    {
        return match (strtolower(trim($provider))) {
            'google', 'discord' => ['scope' => ['openid']],
            'github' => ['scope' => ['']],
            default => [],
        };
    }

    public function subjectFromAccessToken(string $provider, AccessToken $token): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'github') {
            $oauthProvider = $this->createProvider($provider, '');
            $resourceOwner = $oauthProvider->getResourceOwner($token);
            $subject = (string) $resourceOwner->getId();
            if ($subject === '') {
                throw new \RuntimeException('The GitHub resource owner does not contain an ID.');
            }
            return $subject;
        }

        $idToken = $token->getValues()['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new \RuntimeException('No OpenID token was returned by the provider.');
        }

        $payload = $this->decodeIdTokenPayload($idToken);
        $this->assertAudienceMatches($provider, $payload);
        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] < time()) {
            throw new \RuntimeException('The OpenID token has expired.');
        }

        $subject = $payload['sub'] ?? null;
        if (!is_string($subject) || $subject === '') {
            throw new \RuntimeException('The OpenID token does not contain a subject.');
        }

        return $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeIdTokenPayload(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            throw new \RuntimeException('The OpenID token is malformed.');
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $json = base64_decode($payload, true);
        if ($json === false) {
            throw new \RuntimeException('The OpenID token payload is malformed.');
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('The OpenID token payload is invalid.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertAudienceMatches(string $provider, array $payload): void
    {
        $credentials = $this->config->credentials($provider);
        if ($credentials === null || !isset($payload['aud'])) {
            return;
        }

        $audience = $payload['aud'];
        $matches = is_array($audience)
            ? in_array($credentials['clientId'], $audience, true)
            : $audience === $credentials['clientId'];

        if (!$matches) {
            throw new \RuntimeException('The OpenID token audience does not match this application.');
        }
    }
}
