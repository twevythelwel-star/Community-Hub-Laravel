<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Jobs\SendVisitorPassNotification;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VisitorPassResendAndExtendTest extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_can_resend_pass_via_sms(): void
    {
        Queue::fake();

        $host = User::factory()->create(['role' => UserRole::Homeowner]);
        $visitor = Visitor::create([
            'homeowner_id' => $host->id,
            'name' => 'Dwight Schrute',
            'type' => 'Visitor',
            'contact' => '+18765550199',
            'expected_at' => now()->addDay(),
            'status' => VisitorStatus::Expected,
            'notify_sms' => true,
        ]);

        $response = $this->actingAs($host)->post("/dashboard/visitors/{$visitor->id}/resend", [
            'channel' => 'sms',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Queue::assertPushed(SendVisitorPassNotification::class, function ($job) use ($visitor) {
            return $job->visitor->id === $visitor->id && $job->channels === ['sms'];
        });
    }

    public function test_homeowner_can_extend_visitor_pass(): void
    {
        $host = User::factory()->create(['role' => UserRole::Homeowner]);
        $expected = now()->subHour();
        $visitor = Visitor::create([
            'homeowner_id' => $host->id,
            'name' => 'Pam Beesly',
            'type' => 'Visitor',
            'contact' => '+18765550198',
            'expected_at' => $expected,
            'expired_at' => $expected,
            'status' => VisitorStatus::Expected,
        ]);

        $gatePass = app(GatePassEngine::class)->issueGuestPass($visitor, $host, PassCategory::Visitor);
        $gatePass->update([
            'status' => PassStatus::Expired,
            'valid_until' => $expected,
        ]);

        $response = $this->actingAs($host)->post("/dashboard/visitors/{$visitor->id}/extend", [
            'hours' => 4,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $visitor->refresh();
        $this->assertNull($visitor->expired_at);
        $this->assertTrue($visitor->expected_at->isFuture());

        $gatePass->refresh();
        $this->assertEquals(PassStatus::Active, $gatePass->status);
        $this->assertTrue($gatePass->valid_until->isFuture());
    }

    public function test_offline_pin_verifies_gate_pass(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $host = User::factory()->create(['role' => UserRole::Homeowner]);
        $visitor = Visitor::create([
            'homeowner_id' => $host->id,
            'name' => 'Jim Halpert',
            'type' => 'Visitor',
            'contact' => '+18765550197',
            'expected_at' => now()->addHour(),
            'status' => VisitorStatus::Expected,
        ]);

        $gatePass = app(GatePassEngine::class)->issueGuestPass($visitor, $host, PassCategory::Visitor);
        $gatePass->update([
            'status' => PassStatus::Active,
            'valid_from' => now()->subMinute(),
            'valid_until' => now()->addHours(2),
        ]);

        $pin = $gatePass->offline_pin;
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);

        $scanner = app(GateScanner::class);
        $result = $scanner->scan($pin, GateId::Gate01, $guard);

        $this->assertEquals('CHECK_IN', $result['decision']);
        $this->assertNotNull($result['scanId']);
    }
}
