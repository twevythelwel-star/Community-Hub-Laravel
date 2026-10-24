<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateScannerKioskTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_guard_can_access_gate_scanner_kiosk(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);

        $response = $this->actingAs($guard)->get('/dashboard/gate-scanner');
        $response->assertOk();
    }

    public function test_homeowner_cannot_access_gate_scanner_kiosk(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($homeowner)->get('/dashboard/gate-scanner');
        $response->assertForbidden();
    }

    public function test_guard_can_scan_and_confirm_visitor_pass(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $host = User::factory()->create(['role' => UserRole::Homeowner]);
        $visitor = Visitor::create([
            'homeowner_id' => $host->id,
            'name' => 'Toby Flenderson',
            'type' => 'Visitor',
            'contact' => '+18765550205',
            'expected_at' => now()->addHour(),
            'status' => VisitorStatus::Expected,
        ]);

        $gatePass = app(GatePassEngine::class)->issueGuestPass($visitor, $host, PassCategory::Visitor);
        $tokenData = app(GatePassEngine::class)->issueToken($gatePass, GateId::Gate01);

        $scanResponse = $this->actingAs($guard)->postJson('/dashboard/gate-pass/scan', [
            'token' => $tokenData['token'],
            'gate' => 'GATE-01',
        ]);

        $scanResponse->assertOk();
        $scanResponse->assertJsonPath('decision', 'CHECK_IN');
        $scanId = $scanResponse->json('scanId');
        $this->assertNotNull($scanId);

        $confirmResponse = $this->actingAs($guard)->postJson("/dashboard/gate-pass/scans/{$scanId}/confirm", [
            'accept' => true,
        ]);
        $confirmResponse->assertOk();

        $visitor->refresh();
        $this->assertEquals(VisitorStatus::CheckedIn, $visitor->status);
    }
}
