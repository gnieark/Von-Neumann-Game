<?php

declare(strict_types=1);

namespace VonNeumannGame\Auth;

use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Token\AccessToken;

final class MinimalGithubProvider extends Github
{
    /**
     * @return array<string>
     */
    protected function getDefaultScopes()
    {
        return [''];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchResourceOwnerDetails(AccessToken $token)
    {
        $request = $this->getAuthenticatedRequest(
            self::METHOD_GET,
            $this->getResourceOwnerDetailsUrl($token),
            $token,
        );
        $response = $this->getParsedResponse($request);

        if (!is_array($response)) {
            throw new \UnexpectedValueException(
                'Invalid response received from GitHub. Expected JSON.'
            );
        }

        return $response;
    }
}
