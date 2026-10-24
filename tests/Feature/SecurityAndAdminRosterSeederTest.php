<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingSetting;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\User;
use App\Services\Billing\AssessmentBillingService;
use Database\Seeders\SecurityAndAdminRosterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityAndAdminRosterSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_ten_security_officers_and_eleven_admins_who_own_homes(): void
    {
        config(['auth.seed_password' => 'Roster-Seed-Only-77']);

        $this->seed(SecurityAndAdminRosterSeeder::class);

        $officers = User::where('role', UserRole::Security)->get();
        $admins = User::where('role', UserRole::Admin)->with('properties')->get();

        $this->assertCount(10, $officers);
        $this->assertCount(11, $admins);

        // Every Admin owns exactly one home, on record and distinct.
        $this->assertTrue($admins->every(fn (User $a) => $a->properties->count() === 1));
        $this->assertSame(11, Property::whereIn('owner_user_id', $admins->pluck('id'))->distinct()->count('lot_number'));

        // Everyone can sign in with the seed password and holds a gate pass.
        $everyone = $officers->concat($admins);
        $this->assertTrue($everyone->every(fn (User $u) => Hash::check('Roster-Seed-Only-77', $u->password)));
        $this->assertTrue($everyone->every(fn (User $u) => $u->gatePasses()->exists()));
        $this->assertTrue($everyone->every(fn (User $u) => $u->can('manageSecurity')));
        $this->assertTrue($admins->every(fn (User $u) => $u->can('manageUsers') && $u->can('accessHomeownerFunctions')));
        $this->assertTrue($officers->every(fn (User $u) => ! $u->can('manageUsers')));
    }

    public function test_their_homes_are_billed_hoa_dues(): void
    {
        BillingSetting::create(['monthly_fee_minor' => 500000, 'currency' => 'JMD', 'due_day_of_month' => 5]);
        $this->seed(SecurityAndAdminRosterSeeder::class);

        app(AssessmentBillingService::class)->generateMonthlyAssessments('2026-11');

        $this->assertSame(11, Invoice::whereHas('user', fn ($q) => $q->where('role', UserRole::Admin))->count());
        $this->assertSame(0, Invoice::whereHas('user', fn ($q) => $q->where('role', UserRole::Security))->count());
    }

    public function test_only_a_system_admin_manages_an_admin_homeowners_properties(): void
    {
        $this->confirmPassword();
        $this->seed(SecurityAndAdminRosterSeeder::class);
        $treasurer = User::where('email', 'michael.bennett@communityhub.org')->sole();
        $home = $treasurer->properties()->sole();

        $this->actingAs(User::where('email', 'patricia.lindo@communityhub.org')->sole())
            ->post("/dashboard/directory/users/{$treasurer->id}/properties", ['lot_number' => 'Lot 150'])
            ->assertSessionHasErrors('lot_number');
        $this->delete("/dashboard/directory/properties/{$home->id}")->assertSessionHasErrors('lot_number');
        $this->assertTrue($home->fresh()->owner->is($treasurer));

        $this->actingAs(User::factory()->role(UserRole::SystemAdmin)->create())
            ->post("/dashboard/directory/users/{$treasurer->id}/properties", ['lot_number' => 'Lot 150'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $treasurer->properties()->count());
    }

    public function test_re_running_it_creates_nothing_twice_and_keeps_changed_passwords(): void
    {
        $this->seed(SecurityAndAdminRosterSeeder::class);
        $officer = User::where('email', 'andre.campbell@apexguard.com')->sole();
        $officer->update(['password' => 'Changed-By-Officer-1']);

        $this->seed(SecurityAndAdminRosterSeeder::class);

        $this->assertSame(21, User::count());
        $this->assertSame(11, Property::count());
        $this->assertTrue(Hash::check('Changed-By-Officer-1', $officer->fresh()->password));
    }
}
