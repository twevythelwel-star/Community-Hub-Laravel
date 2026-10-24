<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every link the sidebar and the avatar menu offer must actually open.
 *
 * The nav, the route gates and the in-page role checks have drifted apart
 * repeatedly in this codebase, always in one of two directions: a menu item
 * that 403s when clicked, or a page reachable by URL that no menu offers. This
 * pins the first direction, which is the one a user meets as a broken link.
 *
 * Two defects this was written against, both in DashboardLayout's filter:
 *
 *   - Staff were short-circuited to a hardcoded [Profile, Gate Pass] before the
 *     `roles` arrays were consulted, so the deliberate addition of Staff to
 *     Notifications and Safety Alert — each carrying a comment explaining why
 *     Staff needed it — never reached a screen. Staff also had no link back to
 *     /dashboard, which is where sign-in lands them.
 *   - The Security branch recomputed the same filter and appended Profile "if
 *     missing", which it never was. A no-op guarding nothing.
 *
 * The map below is the contract. Change a `roles` array in
 * resources/js/Layouts/DashboardLayout.tsx and this must change with it.
 */
class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const ALL_ROLES = [
        'System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff',
    ];

    /**
     * Sidebar href => roles the menu offers it to.
     *
     * `/dashboard/map?tab=boundary` is the same route as `/dashboard/map`; the
     * tab is read client-side, so it is covered by the map entry.
     *
     * @return array<string, array<int, string>>
     */
    private const SIDEBAR = [
        '/dashboard' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff'],
        '/dashboard/map' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security'],
        '/dashboard/deals' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security'],
        '/dashboard/fundraising' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner'],
        '/dashboard/guidelines' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security'],
        '/dashboard/directory' => ['System Admin', 'Admin'],
        '/dashboard/renters' => ['System Admin', 'Admin', 'Homeowner'],
        '/dashboard/visitors' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security'],
        '/dashboard/gate-pass' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff'],
        '/dashboard/calendar' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner'],
        '/dashboard/notifications' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff'],
        '/dashboard/updates' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner'],
        '/dashboard/warnings' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff'],
        '/dashboard/block-list' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security'],
        '/dashboard/access-log' => ['System Admin', 'Admin', 'Security'],
        '/dashboard/billing' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner'],
        '/dashboard/changelog' => ['System Admin'],
        '/dashboard/review-feedback' => ['System Admin', 'Admin'],
        '/dashboard/feedback' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff'],
        '/dashboard/deactivation' => ['Homeowner', 'Temporary Homeowner'],
        '/dashboard/settings' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff'],
        '/dashboard/profile' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff'],
    ];

    /**
     * Avatar menu href => roles the menu offers it to, from
     * resources/js/components/user-nav.tsx. Entries with no role guard there
     * are offered to everyone.
     *
     * @return array<string, array<int, string>>
     */
    private const AVATAR_MENU = [
        '/dashboard/profile' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff'],
        '/dashboard/renters' => ['System Admin', 'Admin', 'Homeowner'],
        '/dashboard/billing' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner'],
        '/dashboard/settings' => ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner', 'Security', 'Staff'],
    ];

    /** @return array<string, array{string, array<int, string>}> */
    public static function sidebarProvider(): array
    {
        return array_map(
            fn (string $href, array $roles) => [$href, $roles],
            array_keys(self::SIDEBAR),
            self::SIDEBAR,
        );
    }

    /** @return array<string, array{string, array<int, string>}> */
    public static function avatarMenuProvider(): array
    {
        return array_map(
            fn (string $href, array $roles) => [$href, $roles],
            array_keys(self::AVATAR_MENU),
            self::AVATAR_MENU,
        );
    }

    #[DataProvider('sidebarProvider')]
    public function test_every_sidebar_link_opens_for_every_role_it_is_offered_to(string $href, array $roles): void
    {
        foreach ($roles as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'Active']);

            $response = $this->actingAs($user)->get($href);

            $this->assertSame(
                200,
                $response->baseResponse->getStatusCode(),
                "The sidebar offers {$href} to {$role}, but it answered "
                .$response->baseResponse->getStatusCode().'.',
            );
        }
    }

    #[DataProvider('avatarMenuProvider')]
    public function test_every_avatar_menu_link_opens_for_every_role_it_is_offered_to(string $href, array $roles): void
    {
        foreach ($roles as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'Active']);

            $response = $this->actingAs($user)->get($href);

            $this->assertSame(
                200,
                $response->baseResponse->getStatusCode(),
                "The avatar menu offers {$href} to {$role}, but it answered "
                .$response->baseResponse->getStatusCode().'.',
            );
        }
    }

    /**
     * Staff sign in and land on /dashboard. Before the filter was fixed their
     * menu was a hardcoded two items that did not include it, so navigating
     * anywhere left them with no way back to the page they started on.
     */
    public function test_staff_can_reach_the_dashboard_they_are_signed_in_to(): void
    {
        $staff = User::factory()->create(['role' => 'Staff', 'status' => 'Active']);

        $this->actingAs($staff)->get('/dashboard')->assertOk();
    }

    /**
     * Both were listed for Staff in the nav with a comment explaining why, and
     * both were unreachable because the Staff branch discarded the list.
     */
    public function test_staff_can_reach_the_notices_and_alerts_the_nav_lists_for_them(): void
    {
        $staff = User::factory()->create(['role' => 'Staff', 'status' => 'Active']);

        $this->actingAs($staff)->get('/dashboard/notifications')->assertOk();
        $this->actingAs($staff)->get('/dashboard/warnings')->assertOk();
    }

    /**
     * `manageBoundary` is isAdministrative(), so an Admin may publish a
     * boundary. The sidebar offered Boundary Manager to System Admin alone.
     */
    public function test_an_admin_can_reach_the_boundary_editor_the_nav_now_offers_them(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'status' => 'Active']);

        $this->actingAs($admin)->get('/dashboard/map')->assertOk();
        $this->actingAs($admin)->get('/dashboard/map/boundary')->assertOk();
    }

    /**
     * A Temporary Homeowner is billed like any other resident — that is what
     * `accessBilling` says and what the sidebar says. The avatar menu stopped
     * at Homeowner, so from there Payments did not exist for them.
     */
    public function test_a_temporary_homeowner_can_reach_billing_from_the_avatar_menu(): void
    {
        $tenant = User::factory()->create(['role' => 'Temporary Homeowner', 'status' => 'Active']);

        $this->actingAs($tenant)->get('/dashboard/billing')->assertOk();
    }

    /**
     * The other direction, and the one that matters for exposure: a page must
     * not admit a role the menu never offers it to.
     *
     * Seventeen of the twenty-two dashboard pages were open to every signed-in
     * user regardless of what the sidebar showed. Staff — who hold a gate pass
     * and nothing else — could reach eleven pages by typing the URL, including
     * the visitor book, the blocklist, the estate map and the app changelog.
     *
     * Route and menu are exactly equal now, so this asserts equality rather
     * than a subset in either direction.
     */
    #[DataProvider('sidebarProvider')]
    public function test_no_page_admits_a_role_its_menu_entry_withholds(string $href, array $roles): void
    {
        $withheld = array_values(array_diff(self::ALL_ROLES, $roles));

        if ($withheld === []) {
            // Offered to every role, so there is nothing for the route to be
            // looser than. Asserted rather than skipped so the case is visible.
            $this->assertCount(count(self::ALL_ROLES), $roles);

            return;
        }

        foreach ($withheld as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'Active']);

            $status = $this->actingAs($user)->get($href)->baseResponse->getStatusCode();

            $this->assertNotSame(
                200,
                $status,
                "The menu withholds {$href} from {$role}, but the route served it anyway.",
            );
        }
    }
}
