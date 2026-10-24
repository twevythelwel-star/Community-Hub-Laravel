<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A refused page must still give the user somewhere to go.
 *
 * Tightening the route gates turned "a page that renders an Access Denied card
 * inside the dashboard shell" into "a 403 from middleware". Without this the
 * second one is Laravel's stock error document: no sidebar, no menu, no link
 * home. In an app whose entire shell is the navigation, that is a dead end
 * reachable by one mistyped URL.
 *
 * Dashboard errors now render the Inertia `Error` page, which wraps
 * DashboardLayout, so the sidebar survives. Public Blade pages, which ship no
 * React bundle, get the branded views in resources/views/errors.
 */
class ErrorPageNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'Staff', 'status' => 'Active']);
    }

    // ── Inside the dashboard ─────────────────────────────────────────

    public function test_a_refused_dashboard_page_renders_the_in_app_error_page(): void
    {
        $this->actingAs($this->staff())
            ->get('/dashboard/changelog')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 403)
            );
    }

    public function test_a_missing_dashboard_page_renders_the_in_app_error_page(): void
    {
        $this->actingAs($this->staff())
            ->get('/dashboard/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 404)
            );
    }

    /**
     * The status has to survive the nicer rendering — a 403 that answers 200
     * would be a worse bug than the bare error page it replaced.
     */
    public function test_the_status_code_is_preserved(): void
    {
        foreach (['/dashboard/changelog', '/dashboard/fundraising', '/dashboard/map'] as $path) {
            $this->actingAs($this->staff())->get($path)->assertForbidden();
        }
    }

    /**
     * A missing record inside a page the role *may* open is still that role's
     * error to see, and still inside the shell.
     */
    public function test_a_missing_record_renders_in_app_for_a_permitted_role(): void
    {
        $homeowner = User::factory()->create(['role' => 'Homeowner', 'status' => 'Active']);

        $this->actingAs($homeowner)
            ->get('/dashboard/billing/invoices/999999/pdf')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('Error'));
    }

    /**
     * A controller's own wording is worth showing; the framework's generic
     * "This action is unauthorized." is vaguer than the copy already on the
     * page, so it is dropped in favour of the page's description.
     */
    public function test_a_generic_framework_message_is_not_shown(): void
    {
        $this->actingAs($this->staff())
            ->get('/dashboard/fundraising')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('message', null)
            );
    }

    /** A message a controller wrote deliberately does reach the page. */
    public function test_a_controller_written_message_is_shown(): void
    {
        $owner = User::factory()->create(['role' => 'Homeowner', 'status' => 'Active']);
        $intruder = User::factory()->create(['role' => 'Homeowner', 'status' => 'Active']);

        $visitor = Visitor::create([
            'name' => 'Delivery Driver',
            'type' => 'One-time',
            'status' => 'Expected',
            'expected_at' => now()->addDay(),
            'homeowner_id' => $owner->id,
            'homeowner_name' => $owner->display_name,
        ]);

        $this->actingAs($intruder)
            ->delete("/dashboard/visitors/{$visitor->id}")
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('message', 'You can only manage visitors you registered or are authorized to manage.')
            );
    }

    // ── Outside the dashboard ────────────────────────────────────────

    public function test_a_public_404_renders_the_branded_page_with_a_way_onward(): void
    {
        $this->get('/guest/pass/not-a-real-token/pdf')
            ->assertNotFound()
            ->assertSee('That page does not exist')
            ->assertSee('Go to sign in');
    }

    public function test_a_public_404_does_not_render_the_dashboard_shell(): void
    {
        // Public pages ship no React bundle; handing them the app shell would
        // be a regression, not an improvement.
        $this->get('/nonexistent-page')
            ->assertNotFound()
            ->assertDontSee('data-page', false);
    }

    /**
     * A guest who mistypes a dashboard URL is not shown an error at all — they
     * are sent to sign in, which is the navigation they actually need.
     */
    public function test_a_guest_is_redirected_to_sign_in_rather_than_shown_an_error(): void
    {
        $this->get('/dashboard/changelog')->assertRedirect('/login');
    }

    /**
     * The JSON API must keep answering JSON. The in-app page is scoped to
     * dashboard requests that are not expecting JSON.
     */
    public function test_the_api_still_returns_json_for_errors(): void
    {
        $this->getJson('/api/access-log')->assertUnauthorized();
    }
}
