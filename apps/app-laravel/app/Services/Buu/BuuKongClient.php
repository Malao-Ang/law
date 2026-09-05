<?php

namespace App\Services\Buu;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Shared Kong HTTP helper: resolve endpoint config, attach Bearer, POST JSON/multipart.
 */
class BuuKongClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly BuuOAuthService $oauth,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function postJson(string $endpointKey, array $payload = []): array
    {
        $response = $this->send($endpointKey, function (PendingRequest $request, string $url) use ($payload) {
            return $request->asJson()->post($url, $payload);
        });

        return $this->decode($endpointKey, $response);
    }

    /**
     * Multipart / form-data POST (e.g. MinIO PutFile).
     *
     * @param  array<string, mixed>  $fields  scalar fields; file fields use ['name'=>..., 'contents'=>..., 'filename'=>...]
     * @return array<string, mixed>
     */
    public function postMultipart(string $endpointKey, array $fields): array
    {
        $response = $this->send($endpointKey, function (PendingRequest $request, string $url) use ($fields) {
            return $request->asMultipart()->post($url, $fields);
        });

        return $this->decode($endpointKey, $response);
    }

    /**
     * @param  callable(PendingRequest, string): Response  $send
     */
    private function send(string $endpointKey, callable $send): Response
    {
        $path = $this->path($endpointKey);
        $url = $this->apiUrl($path);
        $token = $this->oauth->bearerToken($endpointKey);

        $request = $this->http
            ->acceptJson()
            ->withToken($token)
            ->timeout((int) config('buu.timeout', 60))
            ->withOptions(['force_ip_resolve' => 'v4']);

        $response = $send($request, $url);

        // One retry on 401 with a fresh token
        if ($response->status() === 401) {
            $this->oauth->forgetCachedToken($endpointKey);
            $token = $this->oauth->bearerToken($endpointKey, forceRefresh: true);
            $request = $this->http
                ->acceptJson()
                ->withToken($token)
                ->timeout((int) config('buu.timeout', 60))
                ->withOptions(['force_ip_resolve' => 'v4']);
            $response = $send($request, $url);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $endpointKey, Response $response): array
    {
        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        if (! $response->successful()) {
            Log::warning('BUU Kong API call failed', [
                'endpoint' => $endpointKey,
                'status' => $response->status(),
                'body' => $json,
            ]);

            throw new BuuApiException(
                "BUU API \"{$endpointKey}\" failed: HTTP ".$response->status(),
                $response->status(),
                is_array($json) ? $json : null,
            );
        }

        return is_array($json) ? $json : [];
    }

    private function path(string $endpointKey): string
    {
        /** @var array<string, array{path?: string}> $all */
        $all = config('buu.endpoints', []);
        $path = (string) ($all[$endpointKey]['path'] ?? '');

        if ($path === '') {
            throw new BuuApiException("Unknown or unconfigured BUU endpoint: {$endpointKey}");
        }

        return $path;
    }

    private function apiUrl(string $apiPath): string
    {
        $domain = rtrim((string) config('buu.domain'), '/');
        $path = trim($apiPath, '/');

        return "{$domain}/{$path}";
    }
}
