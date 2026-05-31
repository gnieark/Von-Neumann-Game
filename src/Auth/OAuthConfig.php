<?php

declare(strict_types=1);

namespace VonNeumannGame\Auth;

use VonNeumannGame\Domain\AuthProvider;

final class OAuthConfig
{
    /**
     * @param array<string, mixed> $providers
     */
    private function __construct(private readonly array $providers) {}

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self([]);
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return new self(is_array($data) ? $data : []);
    }

    /**
     * @return array<string>
     */
    public function providerNames(): array
    {
        return array_values(array_filter(
            AuthProvider::externalValues(),
            fn(string $provider): bool => $this->credentials($provider) !== null,
        ));
    }

    /**
     * @return array{clientId: string, clientSecret: string, redirectUri?: string}|null
     */
    public function credentials(string $provider): ?array
    {
        $provider = strtolower(trim($provider));
        $raw = $this->providers[$provider] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $raw = isset($raw['web']) && is_array($raw['web']) ? $raw['web'] : $raw;
        $clientId = $raw['clientId'] ?? $raw['client_id'] ?? null;
        $clientSecret = $raw['clientSecret'] ?? $raw['client_secret'] ?? null;
        if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
            return null;
        }

        $credentials = [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
        ];

        $redirectUri = $raw['redirectUri'] ?? $raw['redirect_uri'] ?? null;
        if (is_string($redirectUri) && $redirectUri !== '') {
            $credentials['redirectUri'] = $redirectUri;
        }

        return $credentials;
    }
}
