<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendVisitorPassNotification;
use App\Models\BlocklistEntry;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the registration form that previously did nothing, and the no-show
 * sweep that previously ran only in an open browser tab.
 */
class VisitorPageTest extends TestCase
{
    use RefreshDatabase;

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Liam Johnson',
            'contact' => 'liam@example.com',
            'vehicle' => 'Silver Corolla, ABC-123',
            'id_type' => 'National ID',
            'id_number' => 'JM-4471-9021',
            'type' => 'One-time',
            'expected_at' => now()->addHours(3)->toIso8601String(),
            'date_range' => now()->addHours(3)->format('Y-m-d'),
        ], $overrides);
    }

    // ── Registration ─────────────────────────────────────────────────

    public function test_registration_persists_every_field_the_form_collects(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)
            ->post('/dashboard/visitors', $this->registrationPayload())
            ->assertSessionHasNoErrors();

        // All five detail fields were visible in the original form but had no
        // state and no submit handler, so none of them were ever saved.
        $this->assertDatabaseHas('visitors', [
            'name' => 'Liam Johnson',
            'contact' => 'liam@example.com',
            'vehicle' => 'Silver Corolla, ABC-123',
            'id_type' => 'National ID',
            'id_number' => 'JM-4471-9021',
            'type' => 'One-time',
            'status' => 'Expected',
            'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);
    }

    public function test_visitor_registration_dispatches_notification_job(): void
    {
        Queue::fake();

        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)
            ->post('/dashboard/visitors', $this->registrationPayload([
                'notify_email' => true,
                'notify_sms' => false,
                'notify_whatsapp' => false,
            ]))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendVisitorPassNotification::class, function ($job) {
            return $job->channels === ['email'];
        });
    }

    public function test_visitor_registration_dispatches_sms_notification(): void
    {
        Queue::fake();
        config([
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => 'token',
            'services.twilio.from' => '+18765550000',
        ]);

        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)
            ->post('/dashboard/visitors', $this->registrationPayload([
                'contact' => '876-555-1234',
                'notify_email' => false,
                'notify_sms' => true,
                'notify_whatsapp' => false,
            ]))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendVisitorPassNotification::class, function ($job) {
            return $job->channels === ['sms'];
        });
    }

    public function test_an_email_address_is_not_texted(): void
    {
        /*
         | `contact` holds an email or a phone number. This used to queue an SMS
         | to "liam@example.com", which the stub then reported as sent.
         */
        Queue::fake();
        config([
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => 'token',
            'services.twilio.from' => '+18765550000',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/visitors', $this->registrationPayload([
                'contact' => 'liam@example.com',
                'notify_email' => false,
                'notify_sms' => true,
            ]))
            ->assertSessionHasNoErrors();

        Queue::assertNotPushed(SendVisitorPassNotification::class);
    }

    public function test_sms_is_not_queued_without_a_twilio_account(): void
    {
        Queue::fake();
        config(['services.twilio.sid' => null, 'services.twilio.token' => null]);

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/visitors', $this->registrationPayload([
                'contact' => '876-555-1234',
                'notify_email' => false,
                'notify_sms' => true,
                'notify_whatsapp' => true,
            ]))
            ->assertSessionHasNoErrors();

        Queue::assertNotPushed(SendVisitorPassNotification::class);
    }

    public function test_visitor_registration_with_no_contact_does_not_dispatch_notifications(): void
    {
        Queue::fake();

        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)
            ->post('/dashboard/visitors', $this->registrationPayload([
                'contact' => null,
                'notify_email' => true,
                'notify_sms' => true,
                'notify_whatsapp' => true,
            ]))
            ->assertSessionHasNoErrors();

        Queue::assertNotPushed(SendVisitorPassNotification::class);
    }

    public function test_registration_requires_a_name_and_a_type(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/visitors', $this->registrationPayload(['name' => '', 'type' => '']))
            ->assertSessionHasErrors(['name', 'type']);

        $this->assertDatabaseCount('visitors', 0);
    }

    public function test_a_clearance_cannot_be_booked_in_the_past(): void
    {
        // A past date is almost always a typo, and the no-show sweep would
        // expire it immediately, which looks like the save silently failed.
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/visitors', $this->registrationPayload([
                'expected_at' => now()->subDays(2)->toIso8601String(),
            ]))
            ->assertSessionHasErrors('expected_at');

        $this->assertDatabaseCount('visitors', 0);
    }

    public function test_a_recurring_clearance_keeps_its_date_range(): void
    {
        $range = now()->format('Y-m-d').' - '.now()->addDays(60)->format('Y-m-d');

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/visitors', $this->registrationPayload([
                'type' => 'Recurring',
                'date_range' => $range,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('visitors', ['type' => 'Recurring', 'date_range' => $range]);
    }

    public function test_security_cannot_register_visitors_for_residents(): void
    {
        // Security holds manageSecurity but not registerVisitors: admitting a
        // guest and pre-clearing one are different authorities.
        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->post('/dashboard/visitors', $this->registrationPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('visitors', 0);
    }

    // ── Page payload ─────────────────────────────────────────────────

    public function test_a_resident_only_receives_their_own_visitors(): void
    {
        $mine = User::factory()->role(UserRole::Homeowner)->create();
        $theirs = User::factory()->role(UserRole::Homeowner)->create();

        Visitor::create([
            'name' => 'My Guest', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->addHour(), 'homeowner_id' => $mine->id,
            'homeowner_name' => $mine->display_name,
        ]);

        Visitor::create([
            'name' => 'Their Guest', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->addHour(), 'homeowner_id' => $theirs->id,
            'homeowner_name' => $theirs->display_name,
        ]);

        $response = $this->actingAs($mine)->get('/dashboard/visitors');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Visitors')
            ->where('canManage', false)
            ->where('canRegister', true)
            ->has('visitors.data', 1)
            ->where('visitors.data.0.name', 'My Guest')
        );

        $response->assertDontSee('Their Guest');
    }

    public function test_security_sees_the_whole_gate_queue_for_today(): void
    {
        // Pin the clock to mid-day: after 23:00 "an hour from now" is tomorrow.
        $this->travelTo(now()->setTime(12, 0));

        $resident = User::factory()->role(UserRole::Homeowner)->create();

        Visitor::create([
            'name' => 'Today Guest', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->addHour(), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);

        Visitor::create([
            'name' => 'Next Week Guest', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->addWeek(), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->get('/dashboard/visitors')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManage', true)
                ->has('visitors.data', 1)
                ->where('visitors.data.0.name', 'Today Guest')
            );
    }

    public function test_the_payload_never_carries_a_visitor_id_number(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        Visitor::create([
            'name' => 'Documented Guest', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->addHour(), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
            'id_number' => 'JM-SECRET-9999',
        ]);

        // The table shows the ID *type*, never the number — that only belongs in
        // the ID modal, behind a deliberate action.
        $this->actingAs($resident)
            ->get('/dashboard/visitors')
            ->assertOk()
            ->assertDontSee('JM-SECRET-9999');
    }

    // ── No-show expiry ───────────────────────────────────────────────

    public function test_the_sweep_expires_visitors_past_the_grace_period(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $stale = Visitor::create([
            'name' => 'Never Arrived', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->subHours(13), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);

        $fresh = Visitor::create([
            'name' => 'Still Expected', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->subHours(2), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);

        $this->artisan('visitors:expire-no-shows')->assertSuccessful();

        $this->assertNotNull($stale->fresh()->expired_at);
        $this->assertNull($fresh->fresh()->expired_at);
    }

    public function test_the_sweep_marks_rather_than_deletes(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $stale = Visitor::create([
            'name' => 'Never Arrived', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->subHours(20), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);

        $this->artisan('visitors:expire-no-shows')->assertSuccessful();

        // The browser version removed the row from local state, losing the fact
        // that the visit was ever booked. The record must survive for audit.
        $this->assertDatabaseHas('visitors', ['id' => $stale->id, 'name' => 'Never Arrived']);
    }

    public function test_the_sweep_leaves_checked_in_visitors_alone(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $onSite = Visitor::create([
            'name' => 'Long Stay', 'type' => 'Recurring', 'status' => 'Checked In',
            'expected_at' => now()->subDays(3), 'checked_in_at' => now()->subDays(3),
            'homeowner_id' => $resident->id, 'homeowner_name' => $resident->display_name,
        ]);

        $this->artisan('visitors:expire-no-shows')->assertSuccessful();

        $this->assertNull($onSite->fresh()->expired_at);
        $this->assertSame('Checked In', $onSite->fresh()->status->value);
    }

    public function test_the_sweep_honours_a_custom_grace_period(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $visitor = Visitor::create([
            'name' => 'Three Hours Late', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->subHours(4), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);

        $this->artisan('visitors:expire-no-shows --hours=12')->assertSuccessful();
        $this->assertNull($visitor->fresh()->expired_at);

        $this->artisan('visitors:expire-no-shows --hours=2')->assertSuccessful();
        $this->assertNotNull($visitor->fresh()->expired_at);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $stale = Visitor::create([
            'name' => 'Never Arrived', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->subHours(20), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);

        $this->artisan('visitors:expire-no-shows --dry-run')->assertSuccessful();

        $this->assertNull($stale->fresh()->expired_at);
    }

    public function test_an_expired_clearance_is_flagged_to_the_gatehouse(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        Visitor::create([
            'name' => 'Never Arrived', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->subHours(20), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
            'expired_at' => now(),
        ]);

        $this->actingAs($resident)
            ->get('/dashboard/visitors')
            ->assertInertia(fn (Assert $page) => $page
                ->where('visitors.data.0.expired', true)
            );
    }

    public function test_an_expired_clearance_cannot_be_checked_in_at_the_gate(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $visitor = Visitor::create([
            'name' => 'Never Arrived', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->subHours(20), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
            'expired_at' => now(),
        ]);

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->post("/dashboard/visitors/{$visitor->id}/check-in")
            ->assertSessionHasErrors('visitor');

        $this->assertSame('Expected', $visitor->fresh()->status->value);
        $this->assertNull($visitor->fresh()->checked_in_at);
    }

    public function test_a_visitor_cannot_be_checked_in_twice(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $guard = User::factory()->role(UserRole::Security)->create();

        $visitor = Visitor::create([
            'name' => 'Repeat Entry', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now(), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);

        $this->actingAs($guard)->post("/dashboard/visitors/{$visitor->id}/check-in")
            ->assertSessionHasNoErrors();

        $this->actingAs($guard)->post("/dashboard/visitors/{$visitor->id}/check-in")
            ->assertSessionHasErrors('visitor');

        // Exactly one access-log entry, not two.
        $this->assertDatabaseCount('access_log_entries', 1);
    }

    public function test_homeowner_can_register_visitors_in_bulk(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $response = $this->actingAs($resident)->post('/dashboard/visitors/bulk', [
            'expected_at' => now()->addHour()->toDateTimeString(),
            'pass_category' => 'VISITOR',
            'visitors' => [
                ['name' => 'Alice Guest', 'contact' => '876-555-0101', 'vehicle' => '1234-AB'],
                ['name' => 'Bob Guest', 'contact' => '876-555-0102', 'vehicle' => '5678-CD'],
            ],
            'notify_email' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visitors', ['name' => 'Alice Guest', 'homeowner_id' => $resident->id]);
        $this->assertDatabaseHas('visitors', ['name' => 'Bob Guest', 'homeowner_id' => $resident->id]);
    }

    public function test_bulk_registration_filters_blocked_names(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        BlocklistEntry::create([
            'name' => 'Blocked Person',
            'reason' => 'Security restriction',
            'status' => 'ACTIVE',
            'date_added' => now(),
            'added_by' => 'Security Office',
        ]);

        $response = $this->actingAs($resident)->post('/dashboard/visitors/bulk', [
            'expected_at' => now()->addHour()->toDateTimeString(),
            'visitors' => [
                ['name' => 'Allowed Person', 'contact' => '876-555-0103'],
                ['name' => 'Blocked Person', 'contact' => '876-555-0104'],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visitors', ['name' => 'Allowed Person']);
        $this->assertDatabaseMissing('visitors', ['name' => 'Blocked Person']);
    }

    public function test_visitors_page_renders_dialog_state_props_for_homeowner(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)
            ->get('/dashboard/visitors')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Visitors')
                ->has('visitors')
                ->has('filters')
                ->has('tabCounts')
                ->has('securityStats')
                ->has('hostProperties')
                ->where('canRegister', true)
                ->where('canManage', false)
                ->where('graceHours', 12)
            );
    }

    public function test_visitors_page_renders_security_scanner_props_for_staff(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();

        $this->actingAs($guard)
            ->get('/dashboard/visitors')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Visitors')
                ->has('visitors')
                ->has('filters')
                ->has('tabCounts')
                ->has('securityStats')
                ->where('canManage', true)
            );
    }
}

