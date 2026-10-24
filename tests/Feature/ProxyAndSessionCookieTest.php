<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LoadsConfigWithEnv;
use Tests\TestCase;

/**
 * Behind a TLS-terminating load balancer the app saw plain HTTP from the
 * balancer's address: redirects came out as http://, HSTS was never sent, and
 * every client shared one IP for rate limits and audit logs. The session
 * cookie, meanwhile, was not marked Secure anywhere.
 */
class ProxyAndSessionCookieTest extends TestCase
{
    use LoadsConfigWithEnv;
    use RefreshDatabase;

    private const PROXY = '10.1.2.3';

    private const CLIENT = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_proxy-probe', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
        ]);
    }

    /** A request that arrived at the balancer over HTTPS, forwarded from $from. */
    private function viaProxy(string $from, string $uri = '/_proxy-probe'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])->get($uri, [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => self::CLIENT,
        ]);
    }

    public function test_with_no_trusted_proxies_forwarded_headers_are_ignored(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->viaProxy(self::PROXY)->assertExactJson(['secure' => false, 'ip' => self::PROXY]);
    }

    public function test_a_listed_proxy_is_believed(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8, 172.16.0.0/12']);

        $this->viaProxy(self::PROXY)->assertExactJson(['secure' => true, 'ip' => self::CLIENT]);
    }

    public function test_an_unlisted_address_cannot_spoof_the_headers(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8']);

        $this->viaProxy('198.51.100.7')->assertExactJson(['secure' => false, 'ip' => '198.51.100.7']);
    }

    public function test_behind_a_trusted_proxy_production_sends_hsts(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8']);
        $this->app['env'] = 'production';

        $this->viaProxy(self::PROXY, '/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_trusted_proxies_come_from_the_environment_and_empty_means_none(): void
    {
        $this->assertSame('10.0.0.0/8,*', $this->configFileWith('trustedproxy.php', ['TRUSTED_PROXIES' => '10.0.0.0/8,*'])['proxies']);

        // Not '': an empty string would switch off Laravel's own Forge/Vapor/Cloud detection.
        $this->assertNull($this->configFileWith('trustedproxy.php', ['TRUSTED_PROXIES' => ''])['proxies']);
    }

    public function test_the_session_cookie_is_https_only_in_production_by_default(): void
    {
        $this->assertTrue($this->configFileWith('session.php', ['APP_ENV' => 'production'])['secure']);
        $this->assertFalse($this->configFileWith('session.php', ['APP_ENV' => 'local'])['secure']);
    }

    public function test_the_session_cookie_setting_can_be_overridden(): void
    {
        $this->assertFalse($this->configFileWith('session.php', ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false'])['secure']);
        $this->assertTrue($this->configFileWith('session.php', ['APP_ENV' => 'staging', 'SESSION_SECURE_COOKIE' => 'true'])['secure']);
    }
}
