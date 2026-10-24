<?php

namespace Tests\Feature;

use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Twilio authenticates with either the account's Auth Token or an API key
 * (an SK... SID and its secret). The Account SID is in the URL either way.
 */
class TwilioCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.twilio.com/2010-04-01/Accounts/AC_test/Messages.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => null,
            'services.twilio.api_key' => null,
            'services.twilio.api_secret' => null,
            'services.twilio.from' => '+18765550000',
        ]);
    }

    /** Registered per test: the first matching fake wins, so setUp() cannot set a default. */
    private function twilioAccepts(): void
    {
        Http::fake([self::URL => Http::response(['sid' => 'SM1'], 201)]);
    }

    public function test_an_api_key_authenticates_in_place_of_the_auth_token(): void
    {
        config(['services.twilio.api_key' => 'SK_test', 'services.twilio.api_secret' => 'key-secret']);
        $this->twilioAccepts();

        $this->assertTrue(app(SmsService::class)->isConfigured());
        app(SmsService::class)->send('876-555-1234', 'Hi');

        Http::assertSent(fn (Request $r) => $r->url() === self::URL
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('SK_test:key-secret')));
    }

    public function test_the_auth_token_still_works(): void
    {
        config(['services.twilio.token' => 'auth-token']);
        $this->twilioAccepts();

        app(SmsService::class)->send('876-555-1234', 'Hi');

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('AC_test:auth-token')));
    }

    public function test_an_api_key_without_its_secret_is_not_enough(): void
    {
        config(['services.twilio.api_key' => 'SK_test']);

        $this->assertFalse(app(SmsService::class)->isConfigured());
    }

    public function test_the_test_command_names_what_is_missing_and_sends_nothing(): void
    {
        config(['services.twilio.sid' => null]);
        Http::fake();

        $this->artisan('messaging:test', ['phone' => '876-555-1234'])
            ->expectsOutputToContain('MISSING (TWILIO_SID)')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_the_test_command_sends_one_message_and_reports_its_sid(): void
    {
        config(['services.twilio.api_key' => 'SK_test', 'services.twilio.api_secret' => 'key-secret']);
        $this->twilioAccepts();

        $this->artisan('messaging:test', ['phone' => '876-555-1234'])
            ->expectsOutputToContain('API key')
            ->expectsOutputToContain('Message SID: SM1')
            ->doesntExpectOutputToContain('key-secret')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_the_test_command_reports_a_twilio_refusal(): void
    {
        config(['services.twilio.token' => 'wrong']);
        Http::fake([self::URL => Http::response(['code' => 20003, 'message' => 'Authenticate'], 401)]);

        $this->artisan('messaging:test', ['phone' => '876-555-1234'])
            ->expectsOutputToContain('error 20003')
            ->assertFailed();
    }
}
