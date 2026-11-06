<?php

namespace App\Services\Buu;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
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
    private const CACHE_PREFIX = 'buu.oauth.';

    private const REFRESH_PREFIX = 'buu.oauth.refresh.';

    private const LOCK_PREFIX = 'buu.oauth.lock.';

    private const RENEW_BEFORE_SECONDS = 300;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Cached access token. Renewed (refresh_token grant, then password grant) when fewer than
     * RENEW_BEFORE_SECONDS remain; forceRefresh always requests a new password-grant token.
     *
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, expires_at?: int, token_type?: string}
     */
    public function getAccessToken(string $endpointKey, bool $forceRefresh = false): array
    {
        $cacheKey = self::CACHE_PREFIX.$endpointKey;

        if (! $forceRefresh) {
            $cached = $this->cachedToken($cacheKey);
            if ($cached !== null && $this->secondsLeft($cached) >= self::RENEW_BEFORE_SECONDS) {
                return $cached;
            }
        }

        try {
            return Cache::lock(self::LOCK_PREFIX.$endpointKey, 10)->block(5, function () use ($endpointKey, $cacheKey, $forceRefresh): array {
                if (! $forceRefresh) {
                    $current = $this->cachedToken($cacheKey);
                    if ($current !== null && $this->secondsLeft($current) >= self::RENEW_BEFORE_SECONDS) {
                        return $current;
                    }
                }

                return $this->renew($endpointKey, $forceRefresh);
            });
        } catch (LockTimeoutException) {
            return $this->renew($endpointKey, $forceRefresh);
        } catch (BuuApiException|ConnectionException $exception) {
            $stale = $forceRefresh ? null : $this->cachedToken($cacheKey);
            if ($stale !== null && $this->secondsLeft($stale) > 10) {
                Log::warning('BUU OAuth renewal failed, using token that is still valid', ['endpoint' => $endpointKey]);

                return $stale;
            }

            throw $exception;
        }
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, expires_at?: int, token_type?: string}
     */
    public function refreshToken(string $endpointKey, string $refreshToken): array
    {
        $endpoint = $this->endpoint($endpointKey);

        $token = $this->requestToken($endpoint['path'], [
            'client_id' => (string) config('buu.client_id'),
            'client_secret' => (string) config('buu.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return $this->remember($endpointKey, $token);
    }

    public function bearerToken(string $endpointKey, bool $forceRefresh = false): string
    {
        $token = $this->getAccessToken($endpointKey, $forceRefresh);

        return (string) ($token['access_token'] ?? '');
    }

    public function forgetCachedToken(string $endpointKey): void
    {
        Cache::forget(self::CACHE_PREFIX.$endpointKey);
        Cache::forget(self::REFRESH_PREFIX.$endpointKey);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, expires_at?: int, token_type?: string}
     */
    private function renew(string $endpointKey, bool $passwordGrantOnly): array
    {
        $refresh = $passwordGrantOnly ? null : Cache::get(self::REFRESH_PREFIX.$endpointKey);

        if (is_string($refresh) && $refresh !== '') {
            try {
                return $this->refreshToken($endpointKey, $refresh);
            } catch (BuuApiException|ConnectionException) {
                Log::info('BUU OAuth refresh_token grant failed, falling back to password grant', ['endpoint' => $endpointKey]);
                Cache::forget(self::REFRESH_PREFIX.$endpointKey);
            }
        }

        $endpoint = $this->endpoint($endpointKey);

        return $this->remember($endpointKey, $this->requestToken($endpoint['path'], [
            'client_id' => (string) config('buu.client_id'),
            'client_secret' => (string) config('buu.client_secret'),
            'grant_type' => 'password',
            'scope' => $endpoint['scope'],
            'provision_key' => $endpoint['provision_key'],
            'authenticated_userid' => (string) config('buu.authenticated_userid'),
        ]));
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, expires_at?: int, token_type?: string}|null
     */
    private function cachedToken(string $cacheKey): ?array
    {
        $cached = Cache::get($cacheKey);

        return is_array($cached) && ($cached['access_token'] ?? '') !== '' ? $cached : null;
    }

    /**
     * @param  array{expires_at?: int}  $token
     */
    private function secondsLeft(array $token): int
    {
        return (int) ($token['expires_at'] ?? 0) - time();
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
     * Stores the access token with an absolute expires_at; the refresh_token lives in its own
     * cache entry that outlives the access token so a renewal is still possible near expiry.
     *
     * @param  array{access_token: string, refresh_token?: string, expires_in?: int, token_type?: string}  $token
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, expires_at?: int, token_type?: string}
     */
    private function remember(string $endpointKey, array $token): array
    {
        $expiresIn = max(60, (int) ($token['expires_in'] ?? 7200));
        $token['expires_at'] = time() + $expiresIn;

        Cache::put(self::CACHE_PREFIX.$endpointKey, $token, $expiresIn);

        if (($token['refresh_token'] ?? '') !== '') {
            Cache::put(self::REFRESH_PREFIX.$endpointKey, $token['refresh_token'], $expiresIn + 86400);
        }

        return $token;
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
