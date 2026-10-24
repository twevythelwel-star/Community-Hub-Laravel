<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The redesigned guest pass page shows only parking, rules and emergency
 * details that were actually configured. It used to fall back to invented
 * ones, a dialable 555 number and "911" among them.
 */
class GuestPassPageDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config([
            'visitor_pass.parking_instructions' => null,
            'visitor_pass.community_rules' => [],
            'visitor_pass.emergency_info' => [],
        ]);

        parent::tearDown();
    }

    private function visitor(array $attributes = []): Visitor
    {
        $host = User::factory()->role(UserRole::Homeowner)->create(['lot' => '14']);

        return Visitor::create($attributes + [
            'name' => 'Michael Chang',
            'type' => 'One-time',
            'share_token' => 'guest_pass_token_details_test_safe',
            'homeowner_id' => $host->id,
            'homeowner_name' => $host->name,
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHours(2),
        ]);
    }

    public function test_an_unconfigured_guest_pass_invents_nothing(): void
    {
        config(['visitor_pass.parking_instructions' => null, 'visitor_pass.community_rules' => [], 'visitor_pass.emergency_info' => []]);
        $visitor = $this->visitor();

        $this->assertSame('', $visitor->parkingInstructions());
        $this->assertSame([], $visitor->communityRules());
        $this->assertSame([], $visitor->emergencyInfo());

        $response = $this->get(route('guest-pass.show', ['token' => $visitor->share_token]))->assertOk();
        $response->assertDontSee('Parking Instructions')
            ->assertDontSee('Community Rules & Courtesy')
            ->assertDontSee('Emergency & Safety Assistance');

        $page = $response->getContent();
        foreach (['555', '911', 'Central Park', 'Grass Verge', '15 MPH', 'Area C'] as $invented) {
            $this->assertStringNotContainsString($invented, $page);
        }
    }

    public function test_the_guest_pass_shows_what_the_estate_configured(): void
    {
        config([
            'visitor_pass.parking_instructions' => 'Visitor bays by the clubhouse.',
            'visitor_pass.emergency_info' => ['security_phone' => '+1 876 555 0100', 'emergency_services' => '119 (Police) / 110 (Fire & Ambulance)'],
        ]);
        $visitor = $this->visitor();

        $this->get(route('guest-pass.show', ['token' => $visitor->share_token]))
            ->assertOk()
            ->assertSee('Visitor bays by the clubhouse.')
            ->assertSee('119 (Police) / 110 (Fire &amp; Ambulance)', false)
            ->assertSee('href="tel:+18765550100"', false);
    }
}
