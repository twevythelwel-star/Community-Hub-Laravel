<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Account changes, refunds, billing settings and bulk exports now ask for
 * the password again, once per config('auth.password_timeout'). A session
 * left signed in on the gatehouse or office PC could otherwise change roles,
 * refund money or walk off with the resident ledger.
 */
class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    /** Inertia's own headers, for a submission made from a dashboard page. */
    private const INERTIA = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

    private function admin(): User
    {
        return User::factory()->role(UserRole::SystemAdmin)->create(['password' => 'Correct-Horse-9']);
    }

    public function test_every_sensitive_action_requires_it(): void
    {
        $protected = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('password.confirm', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->getName())
            ->sort()->values()->all();

        $this->assertSame([
            'dashboard.access-log.export',
            'dashboard.billing.settings.update',
            'dashboard.billing.transactions.export',
            'dashboard.billing.transactions.refund',
            'dashboard.directory.properties.destroy',
            'dashboard.directory.properties.store',
            'dashboard.directory.staff.destroy',
            'dashboard.directory.staff.store',
            'dashboard.directory.staff.update',
            'dashboard.directory.users.store',
            'dashboard.directory.users.update',
            'dashboard.fundraising.donations.export',
            'dashboard.fundraising.donations.refund',
            'dashboard.safety.duress-pin',
            'dashboard.safety.duress-pin.clear',
        ], $protected);
    }

    public function test_an_unconfirmed_submission_is_refused_as_a_validation_error_and_changes_nothing(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create(['display_name' => 'Original Name']);

        $this->actingAs($this->admin())
            ->from('/dashboard/directory')
            ->patch("/dashboard/directory/users/{$resident->id}", ['display_name' => 'Changed'], self::INERTIA)
            ->assertRedirect('/dashboard/directory')
            ->assertSessionHasErrors(['password_confirmation' => RequirePasswordConfirmation::MESSAGE]);

        $this->assertSame('Original Name', $resident->fresh()->display_name);
    }

    public function test_once_confirmed_the_submission_goes_through(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/dashboard/confirm-password', ['password' => 'Correct-Horse-9'])
            ->assertOk()
            ->assertExactJson(['confirmed' => true]);

        $this->patch("/dashboard/directory/users/{$resident->id}", ['display_name' => 'Changed'], self::INERTIA)
            ->assertSessionHasNoErrors();

        $this->assertSame('Changed', $resident->fresh()->display_name);
    }

    public function test_a_wrong_password_does_not_confirm(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/dashboard/confirm-password', ['password' => 'not-it'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'That password is incorrect.']);

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_an_export_sends_you_to_confirm_and_then_on_to_the_download(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard/billing/export-transactions')
            ->assertRedirect('/dashboard/confirm-password');

        $this->get('/dashboard/confirm-password')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/ConfirmPassword'));

        // From the Inertia page: a full navigation, since a CSV is not a page.
        $this->post('/dashboard/confirm-password', ['password' => 'Correct-Horse-9'], self::INERTIA)
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/dashboard/billing/export-transactions'));

        $this->get('/dashboard/billing/export-transactions')->assertOk();
    }

    public function test_a_plain_form_post_is_redirected_to_where_it_was_going(): void
    {
        $this->actingAs($this->admin())->get('/dashboard/fundraising/export/donations');

        $this->post('/dashboard/confirm-password', ['password' => 'Correct-Horse-9'])
            ->assertRedirect(url('/dashboard/fundraising/export/donations'));
    }

    public function test_json_callers_get_laravels_423(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/dashboard/billing/settings', ['monthly_fee' => 100, 'currency' => 'JMD', 'due_day_of_month' => 1])
            ->assertStatus(423);
    }

    public function test_the_confirmation_lapses_after_the_configured_timeout(): void
    {
        config(['auth.password_timeout' => 600]);
        $admin = $this->admin();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time() - 300])
            ->get('/dashboard/billing/export-transactions')
            ->assertOk();

        $this->withSession(['auth.password_confirmed_at' => time() - 900])
            ->get('/dashboard/billing/export-transactions')
            ->assertRedirect('/dashboard/confirm-password');
    }

    public function test_permission_is_still_checked_first(): void
    {
        // A resident is refused outright, not invited to confirm a password.
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/billing/export-transactions')
            ->assertForbidden();
    }

    public function test_confirming_requires_a_signed_in_user(): void
    {
        $this->post('/dashboard/confirm-password', ['password' => 'x'])->assertRedirect('/login');
    }
}
