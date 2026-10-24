<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\User;
use App\Models\Visitor;
use App\Services\AccessVisibilityService;
use App\Services\GatePassEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The visibility service matched properties by substring and trusted a
 * matching email; guest passes showed invented emergency details; and an
 * arrival window across midnight never opened.
 */
class AccessVisibilityAndGuestPassTest extends TestCase
{
    use RefreshDatabase;

    private function visibility(): AccessVisibilityService
    {
        return app(AccessVisibilityService::class);
    }

    private function visitor(array $attributes = []): Visitor
    {
        $host = User::factory()->role(UserRole::Homeowner)->create(['lot' => '14']);

        return Visitor::create($attributes + [
            'name' => 'Michael Chang', 'type' => 'One-time',
            'homeowner_id' => $host->id, 'homeowner_name' => $host->name,
            'status' => VisitorStatus::Expected, 'expected_at' => now()->addHours(2),
        ]);
    }

    public function test_property_access_is_exact_not_a_substring(): void
    {
        // Lot "1" was associated with Unit 14, Lot 10, Unit 21...
        $resident = User::factory()->role(UserRole::Homeowner)->create(['lot' => '1']);

        foreach (['Unit 1', 'Lot 1', '#1', '1'] as $own) {
            $this->assertTrue($this->visibility()->canSee($resident, 'property', null, $own), $own);
        }
        foreach (['Unit 14', 'Lot 10', 'Unit 21', '100'] as $other) {
            $this->assertFalse($this->visibility()->canSee($resident, 'property', null, $other), $other);
        }
    }

    public function test_scoped_queries_match_the_unit_however_it_is_written_and_nothing_else(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create(['lot' => '1']);
        foreach (['Unit 1', 'Unit 14', 'Lot 10', '%'] as $i => $property) {
            GatePass::create(['pass_id' => "GP-T-{$i}", 'category' => PassCategory::Visitor, 'holder_name' => "H{$i}", 'property' => $property, 'status' => PassStatus::Active]);
        }

        $visible = $this->visibility()->scopeAccessQuery(GatePass::query(), $resident)->pluck('property')->all();

        $this->assertSame(['Unit 1'], $visible);
    }

    public function test_a_delegation_shows_its_property_only_once_accepted_and_in_force(): void
    {
        $grantor = User::factory()->role(UserRole::Homeowner)->create(['lot' => '14']);
        $invitee = User::factory()->create(['email' => 'mary@example.com', 'lot' => null]);

        $delegation = DelegatedAccess::create([
            'grantor_user_id' => $grantor->id, 'name' => 'Mary', 'email' => 'mary@example.com',
            'relationship' => 'Daughter', 'access_level' => 'Property Delegate', 'status' => 'active',
            'approval_status' => 'approved', 'activation_method' => 'immediate', 'starts_at' => now()->subDay(),
        ]);

        $this->assertSame([], $this->visibility()->getUserAssociatedProperties($invitee), 'an email match alone, before accepting');

        $delegation->update(['delegate_user_id' => $invitee->id]);
        $this->assertContains('14', $this->visibility()->getUserAssociatedProperties($invitee->fresh()));

        $delegation->update(['approval_status' => 'pending']);
        $this->assertSame([], $this->visibility()->getUserAssociatedProperties($invitee->fresh()), 'not while pending');
    }

    public function test_an_unconfigured_visitor_gets_no_invented_details(): void
    {
        config(['visitor_pass.parking_instructions' => null, 'visitor_pass.community_rules' => [], 'visitor_pass.emergency_info' => []]);
        $visitor = $this->visitor();

        $this->assertSame('', $visitor->parkingInstructions());
        $this->assertSame([], $visitor->communityRules());
        $this->assertSame([], $visitor->emergencyInfo());
    }

    public function test_configured_details_reach_the_visitor(): void
    {
        config(['visitor_pass.emergency_info' => ['emergency_services' => '119 (Police) / 110 (Fire & Ambulance)', 'aed_location' => null]]);

        $this->assertSame(['emergency_services' => '119 (Police) / 110 (Fire & Ambulance)'], $this->visitor()->emergencyInfo());
    }

    public function test_a_visitors_own_details_take_precedence(): void
    {
        config(['visitor_pass.parking_instructions' => 'Estate default.']);

        $this->assertSame('Use the host driveway.', $this->visitor(['parking_instructions' => 'Use the host driveway.'])->parkingInstructions());
    }

    public function test_an_arrival_window_across_midnight_opens(): void
    {
        $visitor = $this->visitor(['arrival_window_start' => '22:00', 'arrival_window_end' => '02:00', 'expected_at' => now()->startOfDay()->addHours(20)]);

        [$from, $until] = app(GatePassEngine::class)->guestWindow($visitor);

        $this->assertTrue($until->greaterThan($from));
        $this->assertSame('04:00', $until->format('H:i'), 'two hours past a 02:00 end, the next day');
        $this->assertTrue($until->isSameDay($from->addDay()));
    }
}
