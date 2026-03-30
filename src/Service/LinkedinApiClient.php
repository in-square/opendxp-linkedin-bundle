<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class LinkedinApiClient
{
    private const DEFAULT_POSTS_ENDPOINT = 'https://api.linkedin.com/rest/posts';

    private Client $client;

    public function __construct(
        private readonly ?string $apiVersion
    ) {
        $this->client = new Client([
            'timeout' => 30,
        ]);
    }

    public function fetchLatestPosts(string $organizationUrn, int $limit, string $accessToken): array
    {
        $response = $this->requestJson('GET', self::DEFAULT_POSTS_ENDPOINT, $accessToken, [
            'q' => 'author',
            'author' => $organizationUrn,
            'count' => $limit,
            'sortBy' => 'LAST_MODIFIED',
        ], [
            'X-RestLi-Method' => 'FINDER',
        ]);

        $elements = $response['elements'] ?? $response['data'] ?? $response['posts'] ?? [];
        if (!is_array($elements)) {
            return [];
        }

        return $elements;
    }

    public function downloadBinary(string $url, string $accessToken): ?array
    {
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => array_filter([
                    'Authorization' => $accessToken !== '' ? 'Bearer ' . $accessToken : null,
                    'Accept' => '*/*',
                ]),
                'http_errors' => false,
            ]);
        } catch (GuzzleException $exception) {
            return null;
        }

        if ($response->getStatusCode() >= 400) {
            return null;
        }

        return [
            'content' => (string) $response->getBody(),
            'content_type' => $response->getHeaderLine('Content-Type'),
        ];
    }

    public function resolveImageUrl(string $imageUrn, string $accessToken): ?string
    {
        try {
            $response = $this->requestJson(
                'GET',
                'https://api.linkedin.com/rest/images/' . $imageUrn,
                $accessToken
            );
        } catch (RuntimeException) {
            return null;
        }

        $url = $response['downloadUrl'] ?? $response['url'] ?? null;
        if (is_string($url) && str_starts_with($url, 'http')) {
            return $url;
        }

        return null;
    }

    private function requestJson(string $method, string $url, string $accessToken, array $query = [], array $headers = []): array
    {
        $baseHeaders = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
            'X-Restli-Protocol-Version' => '2.0.0',
        ];

        if ($this->apiVersion) {
            $baseHeaders['LinkedIn-Version'] = $this->apiVersion;
        }

        try {
            $response = $this->client->request($method, $url, [
                'headers' => array_merge($baseHeaders, $headers),
                'query' => $query,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('LinkedIn API request failed.', 0, $exception);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $payload = json_decode($body, true);

        if ($status >= 400 || !is_array($payload)) {
            $snippet = $body !== '' ? substr($body, 0, 2000) : 'empty';
            throw new RuntimeException(sprintf(
                'LinkedIn API response error. HTTP %d. Body: %s',
                $status,
                $snippet
            ));
        }

        return $payload;
    }
}
