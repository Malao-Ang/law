<?php

namespace App\Services\Buu;

use App\Services\MasterData\UnitStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

class UmsFacultyService
{
    public function __construct(private readonly HttpFactory $http) {}

    public function sync(UnitStore $units): array
    {
        $fallback = false;
        try {
            $rows = $this->faculties();
        } catch (BuuApiException|ConnectionException) {
            $rows = UnitStore::fallbackFaculties();
            $fallback = true;
        }
        $units->syncFaculties($rows, $fallback);

        return ['fallback' => $fallback, 'count' => count($rows), 'message' => $fallback
            ? 'ไม่สามารถดึงข้อมูลจาก UMS ได้ ใช้ข้อมูลคณะสำรองจาก faculty.json แล้ว'
            : 'ดึงข้อมูลคณะจาก UMS เรียบร้อยแล้ว'];
    }

    private function faculties(): array
    {
        $url = rtrim((string) config('buu.ums_faculty.domain'), '/').'/service-api/ums.GLFaculty';
        $response = $this->http->acceptJson()->timeout((int) config('buu.timeout', 60))
            ->withOptions(['force_ip_resolve' => 'v4'])->withToken($this->token())->get($url);
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());
            $response = $this->http->acceptJson()->timeout((int) config('buu.timeout', 60))
                ->withOptions(['force_ip_resolve' => 'v4'])->withToken($this->token())->get($url);
        }
        $json = $response->json();
        $rows = is_array($json) ? ($json['result'] ?? $json['data'] ?? $json) : null;
        if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows)) {
            throw new BuuApiException('ไม่สามารถดึงข้อมูลคณะจาก UMS ได้');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || trim((string) ($row['fac_id'] ?? '')) === '' || trim((string) ($row['fac_nameth'] ?? '')) === '') {
                throw new BuuApiException('รูปแบบข้อมูลคณะจาก UMS ไม่ถูกต้อง');
            }
        }

        return $rows;
    }

    private function token(): string
    {
        $key = $this->cacheKey();
        $cached = Cache::get($key);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) - time() > 300) {
            return $cached['access_token'];
        }
        $config = config('buu.ums_faculty');
        foreach (['domain', 'client_id', 'client_secret', 'provision_key', 'authenticated_userid'] as $field) {
            if (empty($config[$field])) {
                throw new BuuApiException('ยังไม่ได้ตั้งค่าการเชื่อมต่อข้อมูลคณะ UMS');
            }
        }
        $response = $this->http->acceptJson()->asJson()->timeout((int) config('buu.timeout', 60))
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->post(rtrim($config['domain'], '/').'/service-api/ums.GLFaculty/oauth2/token', [
                'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
                'grant_type' => 'password', 'scope' => 'read', 'provision_key' => $config['provision_key'],
                'authenticated_userid' => $config['authenticated_userid'],
            ]);
        $token = $response->json();
        if (! $response->successful() || ! is_array($token) || empty($token['access_token'])) {
            throw new BuuApiException('ไม่สามารถขอโทเคนข้อมูลคณะ UMS ได้');
        }
        $expires = max(1, (int) ($token['expires_in'] ?? 7200));
        Cache::put($key, ['access_token' => $token['access_token'], 'expires_at' => time() + $expires], $expires);

        return $token['access_token'];
    }

    private function cacheKey(): string
    {
        return 'ums.faculty.oauth.'.hash('sha256', json_encode(config('buu.ums_faculty')));
    }
}
