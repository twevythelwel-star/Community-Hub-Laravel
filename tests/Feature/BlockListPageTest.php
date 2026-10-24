<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BlocklistEntry;
use App\Models\BlocklistRemovalRequest;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the split between reading and managing the blocklist, the redaction
 * applied to residents, and the removal-request flow that previously only
 * called console.log.
 */
class BlockListPageTest extends TestCase
{
    use RefreshDatabase;

    private function entry(array $overrides = []): BlocklistEntry
    {
        return BlocklistEntry::create(array_merge([
            'name' => 'Known Troublemaker',
            'reason' => 'Repeatedly causing disturbances at community events.',
            'photo_url' => 'https://picsum.photos/100?q=trouble',
            'date_added' => now()->subMonths(3),
            'added_by' => 'Elena Rostova',
        ], $overrides));
    }

    // ── Access ───────────────────────────────────────────────────────

    public function test_a_resident_can_now_open_the_block_list(): void
    {
        // The sidebar had always offered this page to residents while the route
        // required `manageBlocklist`, so the menu item returned 403.
        $this->entry();

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/block-list')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/BlockList')
                ->where('can.manage', false)
                ->where('can.requestRemoval', true)
                ->has('entries.data', 1)
            );
    }

    public function test_security_and_admins_can_manage(): void
    {
        foreach ([UserRole::Security, UserRole::Admin, UserRole::SystemAdmin] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/block-list')
                ->assertInertia(fn (Assert $page) => $page->where('can.manage', true));
        }
    }

    // ── Redaction ────────────────────────────────────────────────────

    public function test_a_resident_never_receives_the_reason_photo_or_author(): void
    {
        $this->entry();

        $response = $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/block-list');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('entries.data.0', fn (Assert $row) => $row
                ->hasAll(['id', 'name', 'dateAdded', 'expiryDate', 'isPermanent', 'inForce', 'myRequestStatus'])
                // The unflattering detail is omitted from the payload, not
                // merely hidden by the UI.
                ->missing('reason')
                ->missing('photoUrl')
                ->missing('addedBy')
            )
        );

        $response->assertDontSee('Repeatedly causing disturbances');
        $response->assertDontSee('Elena Rostova');
    }

    public function test_a_manager_receives_the_full_entry(): void
    {
        $this->entry();

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->get('/dashboard/block-list')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.reason', 'Repeatedly causing disturbances at community events.')
                ->where('entries.data.0.addedBy', 'Elena Rostova')
                ->where('entries.data.0.photoUrl', 'https://picsum.photos/100?q=trouble')
            );
    }

    public function test_the_visitor_shortlist_is_withheld_from_residents(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        Visitor::create([
            'name' => 'Someone Elses Guest', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now(), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
        ]);

        // It is a directory of every resident's guests.
        $this->actingAs($resident)
            ->get('/dashboard/block-list')
            ->assertInertia(fn (Assert $page) => $page->has('visitors', 0))
            ->assertDontSee('Someone Elses Guest');

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/block-list')
            ->assertInertia(fn (Assert $page) => $page->has('visitors', 1));
    }

    // ── Managing entries ─────────────────────────────────────────────

    public function test_a_resident_cannot_add_or_delete_an_entry(): void
    {
        $entry = $this->entry();
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)
            ->post('/dashboard/block-list', [
                'name' => 'Someone I Dislike', 'reason' => 'Personal grievance.',
            ])
            ->assertForbidden();

        $this->actingAs($resident)
            ->delete("/dashboard/block-list/{$entry->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('blocklist_entries', 1);
    }

    public function test_security_can_add_an_entry(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();

        $this->actingAs($guard)
            ->post('/dashboard/block-list', [
                'name' => 'Suspicious Vehicle Owner',
                'reason' => 'Vehicle seen loitering at odd hours.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('blocklist_entries', [
            'name' => 'Suspicious Vehicle Owner',
            'added_by_id' => $guard->id,
            'added_by' => $guard->display_name,
        ]);
    }

    public function test_a_temporary_block_cannot_expire_in_the_past(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post('/dashboard/block-list', [
                'name' => 'Already Lapsed',
                'reason' => 'Temporary measure.',
                'expiry_date' => now()->subWeek()->toDateString(),
            ])
            ->assertSessionHasErrors('expiry_date');
    }

    // ── Removal requests ─────────────────────────────────────────────

    public function test_a_resident_request_is_stored(): void
    {
        $entry = $this->entry();
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        // The dialog used to console.log and then claim the request had been
        // "sent to the administrators for review".
        $this->actingAs($resident)
            ->post("/dashboard/block-list/{$entry->id}/request-removal", [
                'reason' => 'This was a misunderstanding at the gate; he is my brother.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('blocklist_removal_requests', [
            'blocklist_entry_id' => $entry->id,
            'requested_by' => $resident->id,
            'status' => 'Pending',
        ]);
    }

    public function test_a_request_needs_a_substantive_reason(): void
    {
        $entry = $this->entry();

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post("/dashboard/block-list/{$entry->id}/request-removal", ['reason' => 'pls'])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('blocklist_removal_requests', 0);
    }

    public function test_requesting_twice_reopens_one_request_rather_than_stacking(): void
    {
        $entry = $this->entry();
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        foreach (['First explanation of the situation.', 'A clearer second explanation.'] as $reason) {
            $this->actingAs($resident)
                ->post("/dashboard/block-list/{$entry->id}/request-removal", ['reason' => $reason]);
        }

        $this->assertDatabaseCount('blocklist_removal_requests', 1);
        $this->assertDatabaseHas('blocklist_removal_requests', [
            'reason' => 'A clearer second explanation.',
            'status' => 'Pending',
        ]);
    }

    public function test_security_cannot_file_a_resident_request(): void
    {
        $entry = $this->entry();

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->post("/dashboard/block-list/{$entry->id}/request-removal", [
                'reason' => 'A guard should use the manage controls instead.',
            ])
            ->assertForbidden();
    }

    public function test_a_resident_sees_their_own_pending_request_on_the_row(): void
    {
        $entry = $this->entry();
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $other = User::factory()->role(UserRole::Homeowner)->create();

        BlocklistRemovalRequest::create([
            'blocklist_entry_id' => $entry->id,
            'requested_by' => $resident->id,
            'reason' => 'Already asked about this one.',
        ]);

        // Lets the UI disable the action rather than silently doing nothing.
        $this->actingAs($resident)
            ->get('/dashboard/block-list')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.myRequestStatus', 'Pending')
            );

        // Another resident's request is not theirs to see.
        $this->actingAs($other)
            ->get('/dashboard/block-list')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.myRequestStatus', null)
            );
    }

    public function test_pending_requests_reach_administrators(): void
    {
        $entry = $this->entry();
        $resident = User::factory()->role(UserRole::Homeowner)->create(['display_name' => 'Marcus Vance']);

        BlocklistRemovalRequest::create([
            'blocklist_entry_id' => $entry->id,
            'requested_by' => $resident->id,
            'reason' => 'He is my brother and was misidentified.',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/block-list')
            ->assertInertia(fn (Assert $page) => $page
                ->has('removalRequests', 1)
                ->where('removalRequests.0.requestedBy', 'Marcus Vance')
                ->where('removalRequests.0.entryName', 'Known Troublemaker')
            );

        // Residents do not see other people's requests.
        $this->actingAs($resident)
            ->get('/dashboard/block-list')
            ->assertInertia(fn (Assert $page) => $page->has('removalRequests', 0));
    }

    public function test_approving_a_request_removes_the_entry(): void
    {
        $entry = $this->entry();
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $request = BlocklistRemovalRequest::create([
            'blocklist_entry_id' => $entry->id,
            'requested_by' => $resident->id,
            'reason' => 'Misidentified at the gate.',
        ]);

        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)
            ->patch("/dashboard/block-list/requests/{$request->id}", ['status' => 'Approved'])
            ->assertSessionHasNoErrors();

        // Approving is what actually lifts the block.
        $this->assertDatabaseMissing('blocklist_entries', ['id' => $entry->id]);
    }

    public function test_declining_a_request_leaves_the_entry_in_place(): void
    {
        $entry = $this->entry();
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $request = BlocklistRemovalRequest::create([
            'blocklist_entry_id' => $entry->id,
            'requested_by' => $resident->id,
            'reason' => 'Please reconsider this entry.',
        ]);

        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)
            ->patch("/dashboard/block-list/requests/{$request->id}", [
                'status' => 'Declined',
                'reviewer_note' => 'Incident confirmed by two guards.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('blocklist_entries', ['id' => $entry->id]);
        $this->assertDatabaseHas('blocklist_removal_requests', [
            'id' => $request->id,
            'status' => 'Declined',
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_a_resident_cannot_review_their_own_request(): void
    {
        $entry = $this->entry();
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $request = BlocklistRemovalRequest::create([
            'blocklist_entry_id' => $entry->id,
            'requested_by' => $resident->id,
            'reason' => 'Approving this myself would be convenient.',
        ]);

        $this->actingAs($resident)
            ->patch("/dashboard/block-list/requests/{$request->id}", ['status' => 'Approved'])
            ->assertForbidden();

        $this->assertDatabaseHas('blocklist_entries', ['id' => $entry->id]);
    }

    public function test_the_review_route_is_not_shadowed_by_the_entry_route(): void
    {
        // PATCH /block-list/{blocklistEntry} would otherwise capture the literal
        // "requests" segment and fail model binding.
        $entry = $this->entry();
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $request = BlocklistRemovalRequest::create([
            'blocklist_entry_id' => $entry->id,
            'requested_by' => $resident->id,
            'reason' => 'Routing check.',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->patch("/dashboard/block-list/requests/{$request->id}", ['status' => 'Declined'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Declined', $request->fresh()->status);
    }

    // ── Blocklist enforcement still holds ────────────────────────────

    public function test_an_entry_added_here_blocks_a_visitor_registration(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->post('/dashboard/block-list', [
                'name' => 'Barred Guest',
                'reason' => 'Prior misconduct.',
            ]);

        // End-to-end: the page writes the row that VisitorController checks.
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/visitors', [
                'name' => 'barred guest',
                'type' => 'One-time',
                'expected_at' => now()->addHours(2)->toIso8601String(),
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('visitors', 0);
    }
}
