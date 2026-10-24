<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Jobs\SendVisitorPassNotification;
use App\Models\GatePass;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The gate pass over HTTP: scan and confirm, the lifecycle actions and who may
 * take them, guest pass issuance on registration, and the guest pass page.
 */
class GatePassEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));
        $this->guard = User::factory()->role(UserRole::Security)->create();
    }

    private function engine(): GatePassEngine
    {
        return app(GatePassEngine::class);
    }

    private function guestPass(PassCategory $category = PassCategory::Visitor, ?User $host = null): GatePass
    {
        $host ??= User::factory()->create();
        $visitor = Visitor::factory()->create([
            'type' => 'One-time',
            'expected_at' => now()->addMinutes(30),
            'homeowner_id' => $host->id,
            'homeowner_name' => $host->display_name,
        ]);

        return $this->engine()->issueGuestPass($visitor, $host, $category);
    }

    // ── Scan and confirm ──

    public function test_scan_then_confirm_checks_the_visitor_in(): void
    {
        $pass = $this->guestPass();

        $scan = $this->actingAs($this->guard)->postJson('/dashboard/gate-pass/scan', [
            'token' => $this->engine()->issueToken($pass)['token'],
            'gate' => 'GATE-01',
        ])->assertOk()->assertJson(['decision' => 'CHECK_IN'])->json();

        $this->actingAs($this->guard)
            ->postJson("/dashboard/gate-pass/scans/{$scan['scanId']}/confirm", ['accept' => true])
            ->assertOk()
            ->assertJson(['action' => 'CHECK_IN', 'passStatus' => 'CHECKED_IN']);

        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);
    }

    public function test_the_browser_cannot_choose_the_action(): void
    {
        $pass = $this->guestPass();
        $scan = $this->actingAs($this->guard)->postJson('/dashboard/gate-pass/scan', [
            'token' => $this->engine()->issueToken($pass)['token'],
        ])->json();

        // An extra "action" is ignored; the server applies its own decision.
        $this->actingAs($this->guard)
            ->postJson("/dashboard/gate-pass/scans/{$scan['scanId']}/confirm", ['accept' => true, 'action' => 'CHECK_OUT'])
            ->assertJson(['action' => 'CHECK_IN']);
    }

    public function test_the_old_client_trusted_endpoints_are_gone(): void
    {
        $pass = $this->guestPass();

        $this->actingAs($this->guard)->postJson('/dashboard/gate-pass/confirm-action', [
            'pass_id' => $pass->pass_id,
            'action' => 'CHECK_IN',
        ])->assertNotFound();

        $this->actingAs($this->guard)->getJson('/dashboard/gate-pass/sample-tokens')->assertNotFound();

        $this->assertSame(PassStatus::Issued, $pass->fresh()->status);
    }

    public function test_an_unknown_scan_cannot_be_confirmed(): void
    {
        $this->actingAs($this->guard)
            ->postJson('/dashboard/gate-pass/scans/'.fake()->uuid().'/confirm', ['accept' => true])
            ->assertStatus(409);
    }

    public function test_residents_cannot_scan_or_confirm(): void
    {
        $resident = User::factory()->create();

        $this->actingAs($resident)->postJson('/dashboard/gate-pass/scan', ['token' => 'x'])->assertForbidden();
        $this->actingAs($resident)
            ->postJson('/dashboard/gate-pass/scans/'.fake()->uuid().'/confirm', ['accept' => true])
            ->assertForbidden();
    }

    public function test_the_handheld_api_scans_and_confirms_the_same_way(): void
    {
        $pass = $this->guestPass();
        Sanctum::actingAs($this->guard, TokenAbility::forUser($this->guard));

        $scan = $this->postJson('/api/gate-pass/validate', [
            'token' => $this->engine()->issueToken($pass)['token'],
        ])->assertOk()->assertJson(['allowed' => true, 'decision' => 'CHECK_IN'])->json();

        $this->postJson("/api/gate-pass/scans/{$scan['scanId']}/confirm", ['accept' => true])
            ->assertJson(['action' => 'CHECK_IN']);

        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);
    }

    // ── Token endpoint ──

    public function test_no_code_is_minted_for_a_revoked_pass(): void
    {
        $resident = User::factory()->create();
        $this->engine()->issuePassFor($resident)->revoke($this->guard, 'Lost phone');

        $this->actingAs($resident)->getJson('/dashboard/gate-pass/token')
            ->assertStatus(423)
            ->assertJson(['status' => 'REVOKED']);
    }

    public function test_security_can_reissue_a_revoked_pass(): void
    {
        $resident = User::factory()->create();
        $this->engine()->issuePassFor($resident)->revoke($this->guard, 'Lost phone');

        $this->actingAs($this->guard)->post("/dashboard/gate-pass/reissue/{$resident->id}")->assertSessionHasNoErrors();

        $this->actingAs($resident)->getJson('/dashboard/gate-pass/token')->assertOk();
    }

    // ── Lifecycle actions ──

    public function test_security_approves_a_contractor_and_the_pass_is_then_sent(): void
    {
        Queue::fake();
        $pass = $this->guestPass(PassCategory::Contractor);

        $this->actingAs($this->guard)
            ->post("/dashboard/gate-pass/{$pass->id}/transition", ['status' => 'APPROVED'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PassStatus::Issued, $pass->fresh()->status);
        Queue::assertPushed(SendVisitorPassNotification::class);
    }

    public function test_a_host_can_cancel_their_own_guests_pass_and_nothing_else(): void
    {
        $host = User::factory()->create();
        $pass = $this->guestPass(PassCategory::Contractor, $host);

        $this->actingAs($host)
            ->post("/dashboard/gate-pass/{$pass->id}/transition", ['status' => 'APPROVED'])
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post("/dashboard/gate-pass/{$pass->id}/transition", ['status' => 'CANCELLED'])
            ->assertForbidden();

        $this->actingAs($host)
            ->post("/dashboard/gate-pass/{$pass->id}/transition", ['status' => 'CANCELLED'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PassStatus::Cancelled, $pass->fresh()->status);
    }

    public function test_check_in_cannot_be_set_by_hand(): void
    {
        $pass = $this->guestPass();

        $this->actingAs($this->guard)
            ->post("/dashboard/gate-pass/{$pass->id}/transition", ['status' => 'CHECKED_IN'])
            ->assertSessionHasErrors('status');

        $this->assertSame(PassStatus::Issued, $pass->fresh()->status);
    }

    public function test_rejecting_or_revoking_needs_a_reason_and_illegal_moves_are_refused(): void
    {
        $pass = $this->guestPass(PassCategory::Contractor);

        $this->actingAs($this->guard)
            ->post("/dashboard/gate-pass/{$pass->id}/transition", ['status' => 'REJECTED'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->guard)
            ->post("/dashboard/gate-pass/{$pass->id}/transition", ['status' => 'SUSPENDED', 'reason' => 'Not yet approved'])
            ->assertSessionHasErrors('status');

        $this->assertSame(PassStatus::Requested, $pass->fresh()->status);
    }

    // ── Visitors ──

    public function test_registering_a_visitor_issues_their_pass(): void
    {
        Queue::fake();
        $resident = User::factory()->create();

        $this->actingAs($resident)->post('/dashboard/visitors', [
            'name' => 'Liam Visitor',
            'contact' => 'liam@example.com',
            'type' => 'One-time',
            'expected_at' => now()->addHour()->toIso8601String(),
        ])->assertSessionHasNoErrors();

        $pass = Visitor::where('name', 'Liam Visitor')->sole()->gatePass;
        $this->assertSame(PassStatus::Issued, $pass->status);
        $this->assertSame(PassCategory::Visitor, $pass->category);
        Queue::assertPushed(SendVisitorPassNotification::class);
    }

    public function test_registering_a_contractor_waits_for_approval_before_sending_anything(): void
    {
        Queue::fake();
        $resident = User::factory()->create();

        $this->actingAs($resident)->post('/dashboard/visitors', [
            'name' => 'Pat Plumber',
            'contact' => 'pat@example.com',
            'type' => 'One-time',
            'expected_at' => now()->addHour()->toIso8601String(),
            'pass_category' => 'CONTRACTOR',
        ])->assertSessionHasNoErrors();

        $this->assertSame(PassStatus::Requested, Visitor::where('name', 'Pat Plumber')->sole()->gatePass->status);
        Queue::assertNotPushed(SendVisitorPassNotification::class);
    }

    public function test_the_visitors_page_shows_the_pass_and_the_moves_this_viewer_may_make(): void
    {
        $host = User::factory()->create();
        $pass = $this->guestPass(PassCategory::Contractor, $host);

        $this->actingAs($this->guard)->get('/dashboard/visitors')->assertInertia(fn ($page) => $page
            ->where('visitors.data.0.pass.status', 'REQUESTED')
            ->where('visitors.data.0.pass.category', 'CONTRACTOR')
            ->where('visitors.data.0.pass.actions', [
                ['status' => 'APPROVED', 'label' => 'Approve'],
                ['status' => 'REJECTED', 'label' => 'Reject'],
                ['status' => 'CANCELLED', 'label' => 'Cancel pass'],
            ]));

        $this->actingAs($host)->get('/dashboard/visitors')->assertInertia(fn ($page) => $page
            ->where('visitors.data.0.pass.actions', [['status' => 'CANCELLED', 'label' => 'Cancel pass']]));
    }

    public function test_the_manual_check_in_button_obeys_the_pass(): void
    {
        $pass = $this->guestPass();
        $pass->transitionTo(PassStatus::Suspended, $this->guard, 'Under review');

        $this->actingAs($this->guard)
            ->post("/dashboard/visitors/{$pass->visitor_id}/check-in")
            ->assertSessionHasErrors('visitor');

        $this->assertSame(PassStatus::Suspended, $pass->fresh()->status);
    }

    public function test_the_manual_check_in_button_moves_the_pass(): void
    {
        $pass = $this->guestPass();

        $this->actingAs($this->guard)->post("/dashboard/visitors/{$pass->visitor_id}/check-in")->assertSessionHasNoErrors();

        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);
    }

    // ── Guest pass page ──

    public function test_the_guest_pass_page_no_longer_sends_the_link_to_a_third_party(): void
    {
        $pass = $this->guestPass();

        $this->get(route('guest-pass.show', $pass->visitor->share_token))
            ->assertOk()
            ->assertDontSee('api.qrserver.com')
            ->assertSee('data:image/png;base64,', false);
    }

    public function test_the_live_code_is_a_valid_signed_token_image(): void
    {
        $pass = $this->guestPass();

        $this->getJson(route('guest-pass.code', $pass->visitor->share_token))
            ->assertOk()
            ->assertJson(['available' => true, 'status' => 'ISSUED'])
            ->assertJsonPath('qr', fn (string $qr) => str_starts_with($qr, 'data:image/png;base64,'));
    }

    public function test_a_contractor_awaiting_approval_sees_a_message_not_a_code(): void
    {
        $pass = $this->guestPass(PassCategory::Contractor);

        $this->getJson(route('guest-pass.code', $pass->visitor->share_token))
            ->assertJson(['available' => false, 'qr' => null])
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'approval'));
    }
}
