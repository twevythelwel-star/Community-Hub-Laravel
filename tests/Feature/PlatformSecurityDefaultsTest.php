<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * Framework-level defaults the app had left unset: response security
 * headers, password strength, and table pruning.
 */
class PlatformSecurityDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_and_api_responses_carry_the_security_headers(): void
    {
        foreach (['/login', '/up', '/api/v1/health'] as $uri) {
            $response = $this->get($uri);

            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->assertHeader('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=()');
        }
    }

    public function test_hsts_is_sent_only_over_https_in_production(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';

        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_production_passwords_need_twelve_characters_mixed_case_and_a_number(): void
    {
        $this->app['env'] = 'production';

        $passes = fn (string $password) => Validator::make(['p' => $password], ['p' => Password::defaults()])->passes();

        $this->assertFalse($passes('password'), 'eight lowercase letters');
        $this->assertFalse($passes('longbutlowercase1'), 'no upper case');
        $this->assertFalse($passes('Short1Pass'), 'under twelve');
        $this->assertTrue($passes('Correct-Horse-9-Battery'));
    }

    public function test_development_keeps_the_eight_character_minimum(): void
    {
        $passes = fn (string $password) => Validator::make(['p' => $password], ['p' => Password::defaults()])->passes();

        $this->assertTrue($passes('password'));
        $this->assertFalse($passes('short'));
    }

    public function test_growing_tables_are_pruned_on_a_schedule(): void
    {
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command ?? '')->implode("\n");

        foreach (['queue:prune-failed', 'queue:prune-batches', 'activitylog:clean', 'horizon:snapshot'] as $command) {
            $this->assertStringContainsString($command, $scheduled, "{$command} should be scheduled");
        }
    }
}
