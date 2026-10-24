<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestPreRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_can_pre_register_guest_invitation_with_all_required_details(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');

        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Rachel Sterling',
            'display_name' => 'Rachel Sterling (Lot 42)',
            'lot' => '42',
            'street' => 'Hibiscus Boulevard',
            'phone' => '+18765551042',
        ]);

        $postData = [
            'name' => 'Sophia Martinez',
            'contact' => '+18765559876',
            'vehicle' => '9842-GH',
            'type' => 'One-time',
            'expected_at' => '2026-10-06 14:00:00',
            'arrival_window_start' => '14:00',
            'arrival_window_end' => '18:00',
            'parking_instructions' => 'Park in driveway of Lot 42 or designated visitor bay C-4. Do not park on grass.',
            'community_rules' => [
                'Estate speed limit strictly 15 MPH throughout community grounds.',
                'Quiet hours commence at 10:00 PM.',
                'Visitor parking is restricted to designated visitor bays or host driveway.',
            ],
            'emergency_info' => [
                'gatehouse_phone' => '+18765550000',
                'emergency_services' => '911 / 119',
                'medical_post' => 'North Gatehouse First Aid Bay',
            ],
            'notes' => 'Visiting for family dinner celebration.',
            'notify_email' => true,
        ];

        $response = $this->actingAs($homeowner)
            ->postJson('/dashboard/visitors', $postData);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
        ]);

        $visitor = Visitor::where('name', 'Sophia Martinez')->first();
        $this->assertNotNull($visitor);
        $this->assertEquals('+18765559876', $visitor->contact);
        $this->assertEquals('14:00', $visitor->arrival_window_start);
        $this->assertEquals('18:00', $visitor->arrival_window_end);
        $this->assertEquals('2:00 PM – 6:00 PM', $visitor->arrivalWindowLabel());
        $this->assertStringContainsString('Lot 42', $visitor->parking_instructions);
        $this->assertNotEmpty($visitor->share_token);

        // Gate Pass created with linked pre-registration metadata
        $pass = $visitor->gatePass;
        $this->assertNotNull($pass);
        $this->assertEquals(PassCategory::Visitor, $pass->category);
        $this->assertEquals($visitor->id, $pass->metadata['visitor_id']);
        $this->assertEquals('Rachel Sterling (Lot 42)', $pass->metadata['host_name']);
        $this->assertEquals('42, Hibiscus Boulevard', $pass->metadata['property']);
        $this->assertEquals('2:00 PM – 6:00 PM', $pass->metadata['arrival_window']);
    }

    public function test_guest_receives_digital_guest_pass_with_all_eight_components(): void
    {
        Carbon::setTestNow('2026-10-06 13:30:00');

        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Alexander Wright',
            'display_name' => 'Alexander Wright',
            'lot' => '104',
            'street' => 'Royal Palms Way',
            'phone' => '+18765550104',
        ]);

        $visitor = Visitor::create([
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
            'name' => 'Marcus Vance',
            'contact' => '+18765554321',
            'vehicle' => '8210-KM',
            'type' => 'One-time',
            'expected_at' => Carbon::parse('2026-10-06 14:00:00'),
            'status' => 'Expected',
            'arrival_window_start' => '14:00',
            'arrival_window_end' => '17:00',
            'parking_instructions' => 'Driveway parking at Lot 104 only. Display pass on vehicle dashboard.',
            'community_rules' => [
                'Strict 15 MPH speed limit monitored by radar.',
                'Quiet hours enforced after 10:00 PM.',
                'Pets must be kept on leash at all times.',
            ],
            'emergency_info' => [
                'gatehouse_phone' => '+1 (876) 555-0100',
                'emergency_dispatch' => 'Channel 1 VHF / Dial 911',
                'assembly_point' => 'Community Clubhouse Central Lawn',
            ],
            'notes' => 'Attending project review meeting.',
        ]);

        $engine = app(GatePassEngine::class);
        $pass = $engine->issueGuestPass($visitor, $homeowner);

        // Fetch Digital Guest Pass URL
        $url = route('guest-pass.show', ['token' => $visitor->share_token]);

        $response = $this->get($url);
        $response->assertOk();

        // 1. QR Code / Pass Token present
        $response->assertSee('gate-code');
        $response->assertSee($pass->pass_id);

        // 2. Property destination
        $response->assertSee('Lot 104');
        $response->assertSee('Royal Palms Way');

        // 3. Host resident
        $response->assertSee('Alexander Wright');

        // 4. Date
        $response->assertSee('Oct 6, 2026');

        // 5. Arrival window
        $response->assertSee('2:00 PM – 5:00 PM');

        // 6. Parking instructions
        $response->assertSee('Driveway parking at Lot 104 only');

        // 7. Community rules
        $response->assertSee('Strict 15 MPH speed limit');
        $response->assertSee('Quiet hours enforced');

        // 8. Emergency information
        $response->assertSee('+1 (876) 555-0100');
        $response->assertSee('Community Clubhouse Central Lawn');
    }

    public function test_security_scans_verifies_and_checks_in_pre_registered_guest(): void
    {
        Carbon::setTestNow('2026-10-06 14:15:00');

        $guard = User::factory()->create([
            'role' => UserRole::Security,
            'name' => 'Officer Miller',
        ]);

        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Elena Rostova',
            'lot' => '77',
            'street' => 'Palm Drive',
        ]);

        $visitor = Visitor::create([
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
            'name' => 'Dmitri Ivanov',
            'contact' => '+18765557788',
            'vehicle' => '4401-DX',
            'type' => 'One-time',
            'expected_at' => Carbon::parse('2026-10-06 14:00:00'),
            'status' => 'Expected',
            'arrival_window_start' => '13:00',
            'arrival_window_end' => '16:00',
            'parking_instructions' => 'Visitor Bay #8 next to Lot 77.',
            'community_rules' => ['Estate speed limit 15 mph.'],
            'emergency_info' => ['gatehouse_phone' => '+18765550100'],
        ]);

        $engine = app(GatePassEngine::class);
        $pass = $engine->issueGuestPass($visitor, $homeowner);

        $scanner = app(GateScanner::class);

        // Security scans the rotating QR payload
        $tokenData = $engine->issueToken($pass->fresh());
        $scanResult = $scanner->scan($tokenData['token'], GateId::Gate01, $guard);
        $report = $scanResult['report'];

        // Verify pre-registration details are surfaced to security guard
        $this->assertEquals('CHECK_IN', $scanResult['decision']);
        $this->assertEquals('Dmitri Ivanov', $report['userName']);
        $this->assertEquals('PRE-REGISTERED GUEST', $report['delegatedTitle']);
        $this->assertEquals('1:00 PM – 4:00 PM', $report['arrivalWindow']);
        $this->assertEquals('Visitor Bay #8 next to Lot 77.', $report['parkingInstructions']);
        $this->assertNotEmpty($report['communityRules']);
        $this->assertNotEmpty($report['emergencyInfo']);

        // Guard confirms check-in
        $confirmResult = $scanner->confirm($scanResult['scanId'], $guard);

        $this->assertEquals('CHECK_IN', $confirmResult['action']);
        $this->assertEquals('CHECKED_IN', $confirmResult['passStatus']);

        // Verify pass and visitor status updated to CheckedIn
        $freshPass = $pass->fresh();
        $this->assertEquals(PassStatus::CheckedIn, $freshPass->status);

        $freshVisitor = $visitor->fresh();
        $this->assertEquals(VisitorStatus::CheckedIn, $freshVisitor->status);
        $this->assertNotNull($freshVisitor->checked_in_at);

        // Verify live Digital Guest Pass now shows on-site checked-in badge
        $guestPassUrl = route('guest-pass.show', ['token' => $freshVisitor->share_token]);
        $response = $this->get($guestPassUrl);
        $response->assertOk();
        $response->assertSee('Access Granted — Currently On-Site');
    }
}
