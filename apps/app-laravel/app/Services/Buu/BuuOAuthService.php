<?php

namespace App\Services\Buu;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * OAuth2 password / refresh_token against Kong per-endpoint token URLs.
 *
 * POST https://{domain}/{apiPath}/oauth2/token
 */
class BuuOAuthService
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, token_type?: string}
     */
    public function getAccessToken(string $endpointKey, bool $forceRefresh = false): array
    {
        $endpoint = $this->endpoint($endpointKey);
        $cacheKey = 'buu.oauth.'.$endpointKey;

        if (! $forceRefresh) {
            /** @var array{access_token: string, refresh_token?: string, expires_in?: int, token_type?: string}|null $cached */
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ($cached['access_token'] ?? '') !== '') {
                return $cached;
            }
        }

        $token = $this->requestToken($endpoint['path'], [
            'client_id' => (string) config('buu.client_id'),
            'client_secret' => (string) config('buu.client_secret'),
            'grant_type' => 'password',
            'scope' => $endpoint['scope'],
            'provision_key' => $endpoint['provision_key'],
            'authenticated_userid' => (string) config('buu.authenticated_userid'),
        ]);

        $this->remember($cacheKey, $token);

        return $token;
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, token_type?: string}
     */
    public function refreshToken(string $endpointKey, string $refreshToken): array
    {
        $endpoint = $this->endpoint($endpointKey);
        $cacheKey = 'buu.oauth.'.$endpointKey;

        $token = $this->requestToken($endpoint['path'], [
            'client_id' => (string) config('buu.client_id'),
            'client_secret' => (string) config('buu.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $this->remember($cacheKey, $token);

        return $token;
    }

    public function bearerToken(string $endpointKey, bool $forceRefresh = false): string
    {
        $token = $this->getAccessToken($endpointKey, $forceRefresh);

        return (string) ($token['access_token'] ?? '');
    }

    public function forgetCachedToken(string $endpointKey): void
    {
        Cache::forget('buu.oauth.'.$endpointKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, token_type?: string}
     */
    private function requestToken(string $apiPath, array $payload): array
    {
        $url = $this->tokenUrl($apiPath);

        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('buu.timeout', 60))
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('BUU OAuth token request failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new BuuApiException(
                'BUU OAuth token request failed: HTTP '.$response->status(),
                $response->status(),
                is_array($response->json()) ? $response->json() : null,
            );
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];
        $access = (string) ($json['access_token'] ?? '');

        if ($access === '') {
            throw new BuuApiException('BUU OAuth response missing access_token', $response->status(), $json);
        }

        /** @var array{access_token: string, refresh_token?: string, expires_in?: int, token_type?: string} $token */
        $token = [
            'access_token' => $access,
            'token_type' => isset($json['token_type']) ? (string) $json['token_type'] : 'bearer',
        ];

        if (isset($json['refresh_token']) && $json['refresh_token'] !== '') {
            $token['refresh_token'] = (string) $json['refresh_token'];
        }
        if (isset($json['expires_in'])) {
            $token['expires_in'] = (int) $json['expires_in'];
        }

        return $token;
    }

    /**
     * @param  array{access_token: string, refresh_token?: string, expires_in?: int, token_type?: string}  $token
     */
    private function remember(string $cacheKey, array $token): void
    {
        $ttl = max(60, ((int) ($token['expires_in'] ?? 7200)) - 120);
        Cache::put($cacheKey, $token, $ttl);
    }

    /**
     * @return array{path: string, scope: string, provision_key: string}
     */
    private function endpoint(string $endpointKey): array
    {
        /** @var array<string, array{path?: string, scope?: string, provision_key?: string}> $all */
        $all = config('buu.endpoints', []);
        $endpoint = $all[$endpointKey] ?? null;

        if ($endpoint === null) {
            throw new BuuApiException("Unknown BUU endpoint key: {$endpointKey}");
        }

        $path = (string) ($endpoint['path'] ?? '');
        $provision = (string) ($endpoint['provision_key'] ?? '');

        if ($path === '' || $provision === '') {
            throw new BuuApiException("BUU endpoint \"{$endpointKey}\" is not configured (path/provision_key).");
        }

        if ((string) config('buu.client_id') === '' || (string) config('buu.client_secret') === '') {
            throw new BuuApiException('BUU_CLIENT_ID / BUU_CLIENT_SECRET are not configured.');
        }

        return [
            'path' => $path,
            'scope' => (string) ($endpoint['scope'] ?? ''),
            'provision_key' => $provision,
        ];
    }

    private function tokenUrl(string $apiPath): string
    {
        $domain = rtrim((string) config('buu.domain'), '/');
        $path = trim($apiPath, '/');

        return "{$domain}/{$path}/oauth2/token";
    }
}
