<?php

namespace Tests\Feature;

use App\Services\Buu\BuuApiException;
use App\Services\Buu\BuuOAuthService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BuuOAuthRenewalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buu.domain' => 'https://kong.test',
            'buu.client_id' => 'cid',
            'buu.client_secret' => 'csecret',
            'buu.endpoints.ldap.login.provision_key' => 'pk',
        ]);
        Cache::flush();
    }

    private function grantTypes(): array
    {
        $types = [];
        Http::assertSent(function (HttpRequest $r) use (&$types) {
            $types[] = $r['grant_type'];

            return true;
        });

        return $types;
    }

    public function test_fresh_token_is_cached_with_expires_at_and_reused(): void
    {
        Http::fake(['kong.test/*' => Http::response(['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 7200])]);
        $oauth = app(BuuOAuthService::class);

        $first = $oauth->getAccessToken('ldap.login');
        $second = $oauth->getAccessToken('ldap.login');

        $this->assertSame('a1', $second['access_token']);
        $this->assertEqualsWithDelta(time() + 7200, $first['expires_at'], 5);
        Http::assertSentCount(1);
        $this->assertSame('r1', Cache::get('buu.oauth.refresh.ldap.login'));
    }

    public function test_token_near_expiry_is_renewed_with_refresh_token_grant(): void
    {
        Http::fake(['kong.test/*' => Http::response(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 7200])]);
        Cache::put('buu.oauth.ldap.login', ['access_token' => 'a1', 'expires_at' => time() + 120], 3600);
        Cache::put('buu.oauth.refresh.ldap.login', 'r1', 3600);

        $token = app(BuuOAuthService::class)->getAccessToken('ldap.login');

        $this->assertSame('a2', $token['access_token']);
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'r1');
        $this->assertSame('r2', Cache::get('buu.oauth.refresh.ldap.login'));
    }

    public function test_failed_refresh_falls_back_to_password_grant(): void
    {
        Http::fake(function (HttpRequest $r) {
            if ($r['grant_type'] === 'refresh_token') {
                return Http::response(['error' => 'invalid_grant'], 400);
            }

            return Http::response(['access_token' => 'a3', 'refresh_token' => 'r3', 'expires_in' => 7200]);
        });
        Cache::put('buu.oauth.ldap.login', ['access_token' => 'a1', 'expires_at' => time() + 60], 3600);
        Cache::put('buu.oauth.refresh.ldap.login', 'r1', 3600);

        $token = app(BuuOAuthService::class)->getAccessToken('ldap.login');

        $this->assertSame('a3', $token['access_token']);
        $this->assertSame(['refresh_token', 'password'], $this->grantTypes());
    }

    public function test_expired_token_without_refresh_token_uses_password_grant(): void
    {
        Http::fake(['kong.test/*' => Http::response(['access_token' => 'a4', 'expires_in' => 7200])]);
        Cache::put('buu.oauth.ldap.login', ['access_token' => 'a1', 'expires_at' => time() - 5], 3600);

        $token = app(BuuOAuthService::class)->getAccessToken('ldap.login');

        $this->assertSame('a4', $token['access_token']);
        $this->assertSame(['password'], $this->grantTypes());
    }

    public function test_still_valid_token_is_used_when_renewal_fails(): void
    {
        Http::fake(['kong.test/*' => Http::response('down', 503)]);
        Cache::put('buu.oauth.ldap.login', ['access_token' => 'a1', 'expires_at' => time() + 200], 3600);

        $token = app(BuuOAuthService::class)->getAccessToken('ldap.login');

        $this->assertSame('a1', $token['access_token']);
    }

    public function test_renewal_failure_without_usable_token_throws(): void
    {
        Http::fake(['kong.test/*' => Http::response('down', 503)]);

        $this->expectException(BuuApiException::class);

        app(BuuOAuthService::class)->getAccessToken('ldap.login');
    }

    public function test_force_refresh_requests_new_password_grant_token(): void
    {
        Http::fake(['kong.test/*' => Http::response(['access_token' => 'a5', 'refresh_token' => 'r5', 'expires_in' => 7200])]);
        Cache::put('buu.oauth.ldap.login', ['access_token' => 'a1', 'expires_at' => time() + 7000], 3600);
        Cache::put('buu.oauth.refresh.ldap.login', 'r1', 3600);

        $token = app(BuuOAuthService::class)->getAccessToken('ldap.login', forceRefresh: true);

        $this->assertSame('a5', $token['access_token']);
        $this->assertSame(['password'], $this->grantTypes());
    }
}
