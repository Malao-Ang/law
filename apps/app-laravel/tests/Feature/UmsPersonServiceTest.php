<?php

namespace Tests\Feature;

use App\Services\Buu\UmsPersonService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UmsPersonServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buu.domain' => 'https://kong.test',
            'buu.client_id' => 'cid',
            'buu.client_secret' => 'csecret',
            'buu.endpoints.ums.person.provision_key' => 'pk',
        ]);
        Cache::flush();
    }

    public function test_title_prefixes_are_stripped_and_name_parts_split(): void
    {
        $service = app(UmsPersonService::class);

        $this->assertSame(['สมหญิง', 'ใจดี'], $service->queries('นางสาวสมหญิง ใจดี')[0]);
        $this->assertSame(['สมหญิง', ''], $service->queries('นางสาวสมหญิง ใจดี')[1]);
        $this->assertSame(['สมชาย', 'ใจดี'], $service->queries('ผศ.ดร. สมชาย ใจดี')[0]);
        $this->assertSame(['สมชาย', 'ใจดี'], $service->queries('นาย สมชาย  เก่ง ใจดี')[0]);
        $this->assertSame([['สมชาย', '']], $service->queries('สมชาย'));
        $this->assertSame([], $service->queries('  '));
    }

    public function test_falls_back_to_fname_only_and_matches_uslogin_case_insensitively(): void
    {
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), 'oauth2/token')) {
                return Http::response(['access_token' => 't', 'expires_in' => 7200]);
            }

            return $r['lname'] === ''
                ? Http::response(['status' => 'success', 'result' => [
                    ['uslogin' => 'other', 'fac_id' => '01'],
                    ['uslogin' => 'LIB06002', 'usid' => 'U9', 'psn_id' => 'P9', 'psn_fnameth' => 'สมชาย', 'psn_lnameth' => 'ใจดี', 'fac_id' => '02', 'fac_nameth' => 'สำนักงานอธิการบดี', 'dep_id' => '', 'psn_retire' => 'Y', 'liw_nameth' => 'ผอ.', 'psn_idcard' => '1111111111111'],
                ]])
                : Http::response(['status' => 'success', 'result' => []]);
        });

        $person = app(UmsPersonService::class)->findByLogin('lib06002', 'นายสมชาย ใจดี');

        $this->assertSame('U9', $person['usid']);
        $this->assertSame('02', $person['fac_id']);
        $this->assertNull($person['dep_id']);
        $this->assertTrue($person['retired']);
        $this->assertArrayNotHasKey('psn_idcard', $person);
        $this->assertStringNotContainsString('1111111111111', json_encode($person));
    }

    public function test_result_is_cached_per_username(): void
    {
        Http::fake([
            'kong.test/*/oauth2/token' => Http::response(['access_token' => 't', 'expires_in' => 7200]),
            'kong.test/service-api/ums.SPerson' => Http::response(['result' => [['uslogin' => 'a', 'fac_id' => '01']]]),
        ]);
        $service = app(UmsPersonService::class);

        $service->findByLogin('a', 'สมชาย ใจดี');
        $service->findByLogin('a', 'สมชาย ใจดี');

        Http::assertSentCount(2);
    }

    public function test_errors_are_swallowed_and_return_null(): void
    {
        Http::fake([
            'kong.test/*/oauth2/token' => Http::response(['access_token' => 't', 'expires_in' => 7200]),
            'kong.test/service-api/ums.SPerson' => Http::response('x', 500),
        ]);

        $this->assertNull(app(UmsPersonService::class)->findByLogin('a', 'สมชาย ใจดี'));
    }
}
