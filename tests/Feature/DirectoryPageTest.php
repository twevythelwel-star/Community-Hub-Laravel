<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the directory: who may open it, creating an account with credentials
 * that actually work, deactivation replacing the delete that had no endpoint,
 * and the privilege boundary around System Admin accounts.
 */
class DirectoryPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->role(UserRole::Admin)->create($attributes);
    }

    private function systemAdmin(array $attributes = []): User
    {
        return User::factory()->role(UserRole::SystemAdmin)->create($attributes);
    }

    // ── Access ───────────────────────────────────────────────────────

    public function test_administrators_can_open_the_directory(): void
    {
        foreach ([UserRole::Admin, UserRole::SystemAdmin] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/directory')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard/Directory')
                    ->where('canManageUsers', true)
                );
        }
    }

    public function test_everyone_else_is_refused(): void
    {
        foreach ([UserRole::Homeowner, UserRole::TemporaryHomeowner, UserRole::Security, UserRole::Staff] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/directory')
                ->assertForbidden();
        }
    }

    // ── Listing ──────────────────────────────────────────────────────

    public function test_deactivated_accounts_stay_listed_so_they_can_be_reactivated(): void
    {
        /*
         | The query used to be scoped to active(). Combined with deactivation
         | replacing delete, that would have made deactivating a one-way door:
         | the account vanishes from the only page that could restore it.
         */
        User::factory()->role(UserRole::Homeowner)->inactive()->create([
            'display_name' => 'Dormant Resident',
        ]);

        // The listing orders by display_name. Pinning the admin's name keeps
        // `residents.0` deterministic — leaving it to the factory's faker makes
        // this pass or fail on the random seed.
        $this->actingAs($this->admin(['display_name' => 'Zzz Admin']))
            ->get('/dashboard/directory')
            ->assertInertia(fn (Assert $page) => $page
                ->has('residents', 2)   // the dormant resident plus the acting admin
                ->where('residents.0.name', 'Dormant Resident')
                ->where('residents.0.status', 'Inactive')
            );
    }

    public function test_the_payload_marks_the_viewers_own_account(): void
    {
        $admin = $this->admin(['display_name' => 'Aaa Admin']);

        $this->actingAs($admin)
            ->get('/dashboard/directory')
            ->assertInertia(fn (Assert $page) => $page->where('residents.0.isSelf', true));
    }

    // ── Creating an account ──────────────────────────────────────────

    public function test_an_admin_creates_an_account_that_can_actually_sign_in(): void
    {
        /*
         | The form had no password fields while storeUser required them, so a
         | create could never have satisfied the server. Proving the credential
         | round-trips is the point of this test.
         */
        $this->actingAs($this->admin())
            ->post('/dashboard/directory/users', [
                'name' => 'Jane Doe',
                'email' => 'jane.doe@example.com',
                'role' => UserRole::Homeowner->value,
                'lot' => '42',
                'street' => 'Royal Palm Drive',
                'password' => 'Str0ng-Password!23',
                'password_confirmation' => 'Str0ng-Password!23',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $created = User::where('email', 'jane.doe@example.com')->firstOrFail();

        $this->assertSame('Active', $created->status);
        $this->assertSame('42', $created->lot);
        $this->assertNotSame('Str0ng-Password!23', $created->password, 'password must be hashed');
        $this->assertTrue(Hash::check('Str0ng-Password!23', $created->password));

        $this->post('/logout');
        $this->post('/login', [
            'email' => 'jane.doe@example.com',
            'password' => 'Str0ng-Password!23',
        ])->assertRedirect(route('dashboard.index'));

        $this->assertAuthenticatedAs($created->fresh());
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/dashboard/directory/users', [
                'name' => 'Jane Doe',
                'email' => 'jane.doe@example.com',
                'role' => UserRole::Homeowner->value,
                'password' => 'Str0ng-Password!23',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'jane.doe@example.com']);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin())
            ->post('/dashboard/directory/users', [
                'name' => 'Second Claim',
                'email' => 'taken@example.com',
                'role' => UserRole::Homeowner->value,
                'password' => 'Str0ng-Password!23',
                'password_confirmation' => 'Str0ng-Password!23',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_a_resident_cannot_create_an_account(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/directory/users', [
                'name' => 'Unauthorised',
                'email' => 'nope@example.com',
                'role' => UserRole::Homeowner->value,
                'password' => 'Str0ng-Password!23',
                'password_confirmation' => 'Str0ng-Password!23',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nope@example.com']);
    }

    // ── Editing and deactivation ─────────────────────────────────────

    public function test_deactivation_replaces_the_delete_that_had_no_endpoint(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($this->admin())
            ->patch("/dashboard/directory/users/{$resident->id}", ['status' => 'Inactive'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Inactive', $resident->fresh()->status);
        // The record survives, which is the whole point of not deleting.
        $this->assertDatabaseHas('users', ['id' => $resident->id]);
    }

    public function test_a_deactivated_account_can_be_reactivated(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->inactive()->create();

        $this->actingAs($this->admin())
            ->patch("/dashboard/directory/users/{$resident->id}", ['status' => 'Active'])
            ->assertRedirect();

        $this->assertSame('Active', $resident->fresh()->status);
    }

    public function test_editing_updates_the_display_name_and_address(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($this->admin())
            ->patch("/dashboard/directory/users/{$resident->id}", [
                'display_name' => 'Renamed Resident',
                'lot' => '7',
                'street' => 'Cypress Way',
            ])
            ->assertRedirect();

        $fresh = $resident->fresh();

        $this->assertSame('Renamed Resident', $fresh->display_name);
        $this->assertSame('7', $fresh->lot);
        $this->assertSame('Cypress Way', $fresh->street);
    }

    // ── Privilege boundaries ─────────────────────────────────────────

    public function test_an_admin_cannot_modify_a_system_admin(): void
    {
        /*
         | Without this, `manageUsers` was enough to deactivate the only account
         | that can grant System Admin, and then take the tier over. The page
         | guarded the seeded accounts with a hardcoded email allowlist, which
         | protected nothing once a request bypassed the UI.
         */
        $root = $this->systemAdmin(['display_name' => 'Root Operator']);

        $this->actingAs($this->admin())
            ->patch("/dashboard/directory/users/{$root->id}", ['status' => 'Inactive'])
            ->assertSessionHasErrors('role');

        $this->assertSame('Active', $root->fresh()->status);
        $this->assertSame('Root Operator', $root->fresh()->display_name);
    }

    public function test_a_system_admin_can_modify_another_system_admin(): void
    {
        $other = $this->systemAdmin();

        $this->actingAs($this->systemAdmin())
            ->patch("/dashboard/directory/users/{$other->id}", ['status' => 'Inactive'])
            ->assertRedirect();

        $this->assertSame('Inactive', $other->fresh()->status);
    }

    public function test_an_admin_cannot_promote_anyone_to_system_admin(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($this->admin())
            ->patch("/dashboard/directory/users/{$resident->id}", [
                'role' => UserRole::SystemAdmin->value,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(UserRole::Homeowner, $resident->fresh()->role);
    }

    public function test_a_resident_cannot_edit_anyone(): void
    {
        $other = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->patch("/dashboard/directory/users/{$other->id}", ['display_name' => 'Hijacked'])
            ->assertForbidden();
    }

    // ── Staff ────────────────────────────────────────────────────────

    public function test_a_resident_registers_staff_at_their_own_property(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create(['lot' => '42', 'street' => 'Royal Palm Drive']);
        $property = Property::create([
            'property_code' => 'LOT-42',
            'lot_number' => '42',
            'street_address' => '42 Royal Palm Drive',
            'owner_user_id' => $homeowner->id,
            'status' => 'occupied',
        ]);

        $this->actingAs($homeowner)
            ->post('/dashboard/directory/staff', [
                'name' => 'Devon Clarke',
                'job' => 'Gardener',
                'id_type' => 'Passport',
                'id_number' => 'A4471902',
                'id_expiry' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect();

        $staff = Staff::query()->sole();
        $this->assertSame('42, Royal Palm Drive', $staff->property_label);
        $this->assertSame($property->id, $staff->property_id);
        $this->assertSame($homeowner->id, $staff->added_by);

        $this->actingAs($this->admin())
            ->get('/dashboard/directory')
            ->assertInertia(fn (Assert $page) => $page
                ->where('staff.0.name', 'Devon Clarke')
                ->where('staff.0.property', '42, Royal Palm Drive')
            );
    }

    public function test_editing_a_staff_members_property_updates_its_label(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();
        $staff = Staff::create([
            'name' => 'Maria Garcia',
            'job' => 'Housekeeper',
            'id_type' => 'National ID',
            'id_number' => 'JM-8841-2290',
            'id_expiry' => now()->addYear()->toDateString(),
            'property_label' => 'Lot 42',
            'added_by' => $homeowner->id,
        ]);

        $this->actingAs($homeowner)
            ->patch("/dashboard/directory/staff/{$staff->id}", ['property' => 'Lot 7, Hibiscus Crescent'])
            ->assertRedirect();

        $this->assertSame('Lot 7, Hibiscus Crescent', $staff->fresh()->property_label);
    }
}
