<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use RuntimeException;

class LinkedinOAuthService
{
    private const AUTH_URL = 'https://www.linkedin.com/oauth/v2/authorization';
    private const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    private Client $client;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly ?string $scopes
    ) {
        $this->client = new Client([
            'timeout' => 30,
        ]);
    }

    public function getAuthorizationUrl(string $state): string
    {
        $scopes = $this->normalizeScopes($this->scopes);
        if ($scopes === '') {
            $scopes = 'r_organization_social';
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $scopes,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTH_URL . '?' . $query;
    }

    public function exchangeCodeForToken(string $code): array
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->redirectUri === '') {
            throw new InvalidArgumentException('LinkedIn OAuth config is missing.');
        }

        try {
            $response = $this->client->post(self::TOKEN_URL, [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('LinkedIn token request failed.', 0, $exception);
        }

        $status = $response->getStatusCode();
        $payload = json_decode((string) $response->getBody(), true);
        if ($status >= 400 || !is_array($payload)) {
            throw new RuntimeException('LinkedIn token response error.');
        }

        return $this->enrichTokenPayload($payload);
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new InvalidArgumentException('LinkedIn OAuth config is missing.');
        }

        try {
            $response = $this->client->post(self::TOKEN_URL, [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('LinkedIn refresh token request failed.', 0, $exception);
        }

        $status = $response->getStatusCode();
        $payload = json_decode((string) $response->getBody(), true);
        if ($status >= 400 || !is_array($payload)) {
            throw new RuntimeException('LinkedIn refresh token response error.');
        }

        return $this->enrichTokenPayload($payload);
    }

    private function normalizeScopes(?string $scopes): string
    {
        if (!$scopes) {
            return '';
        }

        $scopes = trim($scopes);
        if ($scopes === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', $scopes) ?? $scopes;
    }

    private function enrichTokenPayload(array $payload): array
    {
        $payload['created_at'] = isset($payload['created_at']) ? (int) $payload['created_at'] : time();

        if (isset($payload['expires_in'])) {
            $payload['expires_at'] = $payload['created_at'] + (int) $payload['expires_in'];
        }

        if (isset($payload['refresh_token_expires_in'])) {
            $payload['refresh_expires_at'] = $payload['created_at'] + (int) $payload['refresh_token_expires_in'];
        }

        return $payload;
    }
}
