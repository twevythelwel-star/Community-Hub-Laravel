<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the notice board: publishing (which used to be console.log), the
 * role targeting the schema always supported but no control ever set, and the
 * AI consent that used to live in localStorage.
 */
class NotificationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep every test on the deterministic stub path unless it says otherwise.
        config(['services.googleai.key' => null]);
    }

    private function notice(array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'title' => 'Community Pool Maintenance',
            'content' => 'The pool is closed from July 1st to July 3rd.',
            'author_name' => 'Elena Rostova',
            'target_roles' => null,
            'published_at' => now()->subDay(),
        ], $overrides));
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->role(UserRole::Admin)->create($attributes);
    }

    // ── Access ───────────────────────────────────────────────────────

    public function test_any_signed_in_user_can_read_the_notice_board(): void
    {
        $this->notice();

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Notifications')
                ->where('canBroadcast', false)
                ->has('notifications.data', 1)
            );
    }

    public function test_only_administrative_roles_may_broadcast(): void
    {
        foreach ([UserRole::Admin, UserRole::SystemAdmin] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/notifications')
                ->assertInertia(fn (Assert $page) => $page->where('canBroadcast', true));
        }

        foreach ([UserRole::Homeowner, UserRole::Security, UserRole::Staff] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/notifications')
                ->assertInertia(fn (Assert $page) => $page->where('canBroadcast', false));
        }
    }

    public function test_the_page_offers_every_role_as_an_audience(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard/notifications')
            ->assertInertia(fn (Assert $page) => $page
                ->has('roles', count(UserRole::cases()))
                ->where('roles.0', UserRole::SystemAdmin->value)
            );
    }

    // ── Targeting ────────────────────────────────────────────────────

    public function test_an_untargeted_notice_reaches_everyone(): void
    {
        $this->notice(['target_roles' => null]);

        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/notifications')
                ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1));
        }
    }

    public function test_a_targeted_notice_is_never_sent_to_other_roles(): void
    {
        $this->notice([
            'title' => 'Gatehouse shift change',
            'target_roles' => [UserRole::Security->value],
        ]);

        // Filtered server-side, so the content is not in the payload at all.
        $response = $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/notifications');

        $response->assertInertia(fn (Assert $page) => $page->has('notifications.data', 0));
        $response->assertDontSee('Gatehouse shift change');

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->get('/dashboard/notifications')
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications.data', 1)
                ->where('notifications.data.0.title', 'Gatehouse shift change')
            );
    }

    public function test_notices_are_listed_newest_first(): void
    {
        $this->notice(['title' => 'Older', 'published_at' => now()->subWeek()]);
        $this->notice(['title' => 'Newer', 'published_at' => now()->subHour()]);

        $this->actingAs($this->admin())
            ->get('/dashboard/notifications')
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data.0.title', 'Newer')
                ->where('notifications.data.1.title', 'Older')
            );
    }

    // ── Publishing ───────────────────────────────────────────────────

    public function test_publishing_stores_the_notice(): void
    {
        // The original handler called console.log and toasted "Notification Sent".
        $admin = $this->admin(['display_name' => 'Elena Rostova']);

        $this->actingAs($admin)
            ->post('/dashboard/notifications', [
                'title' => 'Annual HOA Meeting',
                'content' => 'July 15th at 7 PM in the clubhouse.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('notifications', [
            'title' => 'Annual HOA Meeting',
            'author_id' => $admin->id,
            'author_name' => 'Elena Rostova',
        ]);

        $this->assertDatabaseHas('activity_log_entries', [
            'user_id' => $admin->id,
            'action' => 'Published notice: Annual HOA Meeting',
        ]);
    }

    public function test_an_empty_audience_is_stored_as_null_not_an_empty_array(): void
    {
        /*
         * Notification::forRole() matches whereNull OR whereJsonContains. An
         * empty array satisfies neither, so a notice stored with [] would be
         * invisible to every role — including the admin who wrote it.
         */
        $this->actingAs($this->admin())
            ->post('/dashboard/notifications', [
                'title' => 'Everyone notice',
                'content' => 'Body.',
                'target_roles' => [],
            ])
            ->assertRedirect();

        $this->assertNull(Notification::first()->target_roles);

        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/notifications')
                ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1));
        }
    }

    public function test_a_chosen_audience_is_stored_and_deduplicated(): void
    {
        $this->actingAs($this->admin())
            ->post('/dashboard/notifications', [
                'title' => 'Staff only',
                'content' => 'Body.',
                'target_roles' => [
                    UserRole::Staff->value,
                    UserRole::Staff->value,
                    UserRole::Security->value,
                ],
            ])
            ->assertRedirect();

        $this->assertSame(
            [UserRole::Staff->value, UserRole::Security->value],
            Notification::first()->target_roles,
        );
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/dashboard/notifications', [
                'title' => 'Bad audience',
                'content' => 'Body.',
                'target_roles' => ['Groundskeeper'],
            ])
            ->assertSessionHasErrors('target_roles.0');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_title_and_content_are_required(): void
    {
        $this->actingAs($this->admin())
            ->post('/dashboard/notifications', [])
            ->assertSessionHasErrors(['title', 'content']);
    }

    public function test_a_resident_cannot_publish(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/notifications', [
                'title' => 'Unauthorised',
                'content' => 'Body.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('notifications', 0);
    }

    // ── AI drafting and consent ──────────────────────────────────────

    public function test_drafting_is_refused_without_recorded_consent(): void
    {
        /*
         * Consent used to be a localStorage key, so the server had no idea
         * whether it had been given and would have called Google regardless.
         */
        $response = $this->actingAs($this->admin(['ai_consent' => false]))
            ->postJson('/dashboard/notifications/suggest-audience', [
                'document' => 'A long announcement about the water supply.',
            ]);

        $response->assertForbidden()->assertJson(['reason' => 'ai_consent_required']);
    }

    public function test_drafting_returns_items_once_consent_is_recorded(): void
    {
        $response = $this->actingAs($this->admin(['ai_consent' => true]))
            ->postJson('/dashboard/notifications/suggest-audience', [
                'document' => 'A long announcement about the water supply.',
            ]);

        $response->assertOk()
            ->assertJsonPath('source', 'stub')
            ->assertJsonCount(4, 'notificationItems');
    }

    public function test_drafting_falls_back_to_the_stub_when_the_model_call_fails(): void
    {
        config(['services.googleai.key' => 'test-key']);
        Http::fake(['*' => Http::response([], 500)]);

        $this->actingAs($this->admin(['ai_consent' => true]))
            ->postJson('/dashboard/notifications/suggest-audience', [
                'document' => 'A long announcement.',
            ])
            ->assertOk()
            ->assertJsonPath('source', 'stub-fallback');
    }

    public function test_drafting_records_that_the_document_left_the_building(): void
    {
        $admin = $this->admin(['ai_consent' => true]);

        $this->actingAs($admin)
            ->postJson('/dashboard/notifications/suggest-audience', [
                'document' => 'A long announcement.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('activity_log_entries', [
            'user_id' => $admin->id,
            'action' => 'Sent a document to the AI drafting service',
        ]);
    }

    public function test_a_document_is_required(): void
    {
        $this->actingAs($this->admin(['ai_consent' => true]))
            ->postJson('/dashboard/notifications/suggest-audience', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');
    }

    public function test_a_resident_cannot_reach_the_drafting_endpoint_at_all(): void
    {
        // The gate refuses before consent is even considered.
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create(['ai_consent' => true]))
            ->postJson('/dashboard/notifications/suggest-audience', [
                'document' => 'A long announcement.',
            ])
            ->assertForbidden()
            ->assertJsonMissingPath('reason');
    }

    public function test_consent_is_recorded_on_the_account_not_the_browser(): void
    {
        $admin = $this->admin(['ai_consent' => false]);

        $this->actingAs($admin)
            ->post('/dashboard/profile/ai-consent', ['consent' => true])
            ->assertRedirect();

        // Survives a different session, which localStorage never did.
        $this->assertTrue($admin->fresh()->ai_consent);

        $this->actingAs($admin->fresh())
            ->get('/dashboard/notifications')
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.aiConsent', true));
    }

    public function test_the_page_says_whether_a_model_is_actually_configured(): void
    {
        // The original captioned the button "Powered by Google Gemini" while
        // calling a stub that never reached a model.
        $this->actingAs($this->admin())
            ->get('/dashboard/notifications')
            ->assertInertia(fn (Assert $page) => $page->where('aiEnabled', false));

        config(['services.googleai.key' => 'test-key']);

        $this->actingAs($this->admin())
            ->get('/dashboard/notifications')
            ->assertInertia(fn (Assert $page) => $page->where('aiEnabled', true));
    }
}
