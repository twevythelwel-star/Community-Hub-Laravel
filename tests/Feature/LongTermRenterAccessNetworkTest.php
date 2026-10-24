<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\DelegatedAccess;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LongTermRenterAccessNetworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
    }

    public function test_renter_receives_dedicated_my_access_network_view_scoped_to_unit_14(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Owner Smith',
            'lot' => 'Unit 14',
        ]);

        $property = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => '14',
            'property_code' => 'UNIT-14',
            'street_address' => '14 Royal Palm Boulevard',
        ]);

        $renterUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
        ]);

        $renter = Renter::create([
            'user_id' => $renterUser->id,
            'name' => 'Michael Brown',
            'property_id' => $property->id,
            'lot' => 'Unit 14',
            'status' => 'Active',
            'lease_start' => Carbon::parse('2026-10-01'),
            'lease_end' => Carbon::parse('2027-03-31'),
        ]);

        $response = $this->actingAs($renterUser)->getJson('/dashboard/delegation');
        $response->assertOk();

        $data = $response->json();

        // 1. Check Page Identity & Scoping
        $this->assertEquals('Unit 14 — My Access Network', $data['pageTitle']);
        $this->assertEquals('Unit 14', $data['unitLabel']);
        $this->assertStringContainsString('tenancy at Unit 14', $data['pageSubtitle']);
        $this->assertEquals('Tenant Access Scope • Bounded by Lease', $data['scopeBadge']);

        // 2. Check 5 Narrow Sections Configuration
        $this->assertCount(5, $data['sections']);
        $sectionKeys = array_column($data['sections'], 'key');
        $this->assertEquals(['residents', 'visitors', 'staff', 'contractors', 'legacy_contacts'], $sectionKeys);

        // Confirm Homeowner-only sections like short_term_rentals are NOT in active sections
        $this->assertNotContains('short_term_rentals', $sectionKeys);
        $this->assertNotContains('family_members', $sectionKeys);

        // 3. Check Granular Capability Permissions
        $permissions = $data['permissions'];
        $this->assertTrue($permissions['isRenter']);
        $this->assertFalse($permissions['isHomeowner']);
        $this->assertEquals('2027-03-31', $permissions['leaseEndDate']);

        // Can See
        $this->assertTrue($permissions['canSee']['ownHousehold']);
        $this->assertTrue($permissions['canSee']['ownVisitors']);
        $this->assertTrue($permissions['canSee']['propertyStaff']);
        $this->assertTrue($permissions['canSee']['propertyContractors']);
        $this->assertTrue($permissions['canSee']['ownLegacyContacts']);
        $this->assertFalse($permissions['canSee']['homeownerFinancials']);
        $this->assertFalse($permissions['canSee']['shortTermRentals']);
        $this->assertFalse($permissions['canSee']['propertyOwnership']);

        // Can Act
        $this->assertFalse($permissions['canAct']['removeHomeowner']);
        $this->assertFalse($permissions['canAct']['changePropertyOwnership']);
        $this->assertFalse($permissions['canAct']['viewHomeownerFinancials']);
        $this->assertFalse($permissions['canAct']['createPermanentCredentials']);
        $this->assertFalse($permissions['canAct']['modifyCommunitySettings']);
        $this->assertTrue($permissions['canAct']['preClearVisitor']);
        $this->assertTrue($permissions['canAct']['authorizeStaff']);
        $this->assertTrue($permissions['canAct']['authorizeContractor']);
    }

    public function test_renter_sees_authorized_property_staff_and_contractors_but_not_homeowner_legacy_contacts(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Unit 14',
        ]);

        $property14 = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => '14',
            'property_code' => 'UNIT-14',
        ]);
        $property15 = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => '15',
            'property_code' => 'UNIT-15',
        ]);

        $renterUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
        ]);

        Renter::create([
            'user_id' => $renterUser->id,
            'homeowner_id' => $homeowner->id,
            'property_id' => $property14->id,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
            'status' => 'Active',
            'lease_start' => Carbon::parse('2026-10-01'),
            'lease_end' => Carbon::parse('2027-03-31'),
        ]);

        // 1. Homeowner authorizes a cleaner for Unit 14 (Staff)
        $staff14 = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'property_id' => $property14->id,
            'name' => 'Maria Johnson',
            'email' => 'maria@example.com',
            'relationship' => 'Cleaner',
            'access_level' => 'Domestic Staff',
            'authorization_type' => 'domestic_staff',
            'status' => 'active',
        ]);

        // 2. Homeowner authorizes a plumber for Unit 14 (Contractor)
        $contractor14 = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'property_id' => $property14->id,
            'name' => 'ABC Plumbing',
            'email' => 'plumbing@example.com',
            'relationship' => 'Contractor',
            'access_level' => 'Contractor',
            'authorization_type' => 'contractor',
            'status' => 'active',
        ]);

        // 3. Homeowner authorizes a Legacy Contact for Unit 14 (Attorney/Emergency)
        $homeownerLegacy = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'property_id' => $property14->id,
            'name' => 'Sarah Smith (Homeowner Delegate)',
            'email' => 'sarah.smith@example.com',
            'relationship' => 'Family member',
            'access_level' => 'Legacy Delegate',
            'authorization_type' => 'legacy_contact',
            'status' => 'active',
        ]);

        // 4. Another unit's contractor (Unit 15)
        $contractor15 = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'property_id' => $property15->id,
            'name' => 'Unit 15 Electrician',
            'email' => 'electrician15@example.com',
            'relationship' => 'Contractor',
            'access_level' => 'Contractor',
            'authorization_type' => 'contractor',
            'status' => 'active',
        ]);

        // Request as renter
        $response = $this->actingAs($renterUser)->getJson('/dashboard/delegation');
        $response->assertOk();

        $data = $response->json();
        $people = collect($data['authorizedPeople']);

        // Renter SEES staff authorized for Unit 14
        $this->assertTrue($people->contains('name', 'Maria Johnson'));
        $staffItem = $people->firstWhere('name', 'Maria Johnson');
        $this->assertFalse($staffItem['canManage'], 'Renter cannot modify homeowner-granted staff');

        // Renter SEES contractor authorized for Unit 14
        $this->assertTrue($people->contains('name', 'ABC Plumbing'));
        $contractorItem = $people->firstWhere('name', 'ABC Plumbing');
        $this->assertFalse($contractorItem['canManage'], 'Renter cannot modify homeowner-granted contractor');

        // Renter DOES NOT SEE homeowner's legacy contacts!
        $this->assertFalse($people->contains('name', 'Sarah Smith (Homeowner Delegate)'));

        // Renter DOES NOT SEE other units (Unit 15)
        $this->assertFalse($people->contains('name', 'Unit 15 Electrician'));
    }

    public function test_renter_sees_own_household_and_own_visitors_and_own_legacy_contacts(): void
    {
        $renterUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
        ]);

        Renter::create([
            'user_id' => $renterUser->id,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
            'status' => 'Active',
            'lease_start' => Carbon::parse('2026-10-01'),
            'lease_end' => Carbon::parse('2027-03-31'),
        ]);

        // 1. Renter's invited visitor
        Visitor::create([
            'homeowner_id' => $renterUser->id,
            'name' => 'David Williams',
            'type' => 'Visitor',
            'status' => VisitorStatus::Expected,
            'expected_at' => Carbon::now()->addHours(2),
        ]);

        // 2. Renter's own personal legacy / emergency contact
        DelegatedAccess::create([
            'grantor_user_id' => $renterUser->id,
            'name' => 'Karen Brown (Sister)',
            'email' => 'karen.brown@example.com',
            'relationship' => 'Family member',
            'access_level' => 'Emergency Contact',
            'authorization_type' => 'legacy_contact',
            'status' => 'active',
        ]);

        $response = $this->actingAs($renterUser)->getJson('/dashboard/delegation');
        $response->assertOk();

        $data = $response->json();
        $people = collect($data['authorizedPeople']);

        // Renter's primary tenant card
        $this->assertTrue($people->contains('name', 'Michael Brown'));

        // Renter's visitor
        $this->assertTrue($people->contains('name', 'David Williams'));

        // Renter's legacy contact
        $this->assertTrue($people->contains('name', 'Karen Brown (Sister)'));
        $karen = $people->firstWhere('name', 'Karen Brown (Sister)');
        $this->assertTrue($karen['canManage']);
    }

    public function test_renter_cannot_create_unrestricted_permanent_credentials(): void
    {
        $renterUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
        ]);

        Renter::create([
            'user_id' => $renterUser->id,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
            'status' => 'Active',
            'lease_start' => Carbon::parse('2026-10-01'),
            'lease_end' => Carbon::parse('2027-03-31'),
        ]);

        // Renter attempts to create an indefinite permanent pass
        $response = $this->actingAs($renterUser)->postJson('/dashboard/delegation', [
            'name' => 'Permanent Friend',
            'email' => 'friend@example.com',
            'relationship' => 'Family/Friend',
            'access_level' => 'Limited Delegate',
            'duration_type' => 'manual_revocation', // Unrestricted permanent credential
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['duration_type']);
        $this->assertStringContainsString('Renters cannot create unrestricted permanent credentials', $response->json('errors.duration_type.0'));
    }

    public function test_renter_credential_expiration_is_clamped_to_lease_end(): void
    {
        $renterUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
        ]);

        Renter::create([
            'user_id' => $renterUser->id,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
            'status' => 'Active',
            'lease_start' => Carbon::parse('2026-10-01'),
            'lease_end' => Carbon::parse('2027-03-31'),
        ]);

        // Renter creates a credential with expiration far beyond lease end
        $response = $this->actingAs($renterUser)->postJson('/dashboard/delegation', [
            'name' => 'Guest Beyond Lease',
            'email' => 'beyond@example.com',
            'relationship' => 'Cleaner',
            'access_level' => 'Domestic Staff',
            'duration_type' => 'custom',
            'expires_at' => '2027-12-31', // Exceeds lease end of 2027-03-31
        ]);

        $response->assertCreated();

        $delegation = DelegatedAccess::where('email', 'beyond@example.com')->first();
        $this->assertNotNull($delegation);

        // Clamped to end of lease day
        $this->assertEquals('2027-03-31', $delegation->expires_at->toDateString());
    }

    public function test_renter_cannot_remove_homeowner_or_modify_continuity_plan(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Unit 14',
        ]);

        $renterUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Michael Brown',
            'lot' => 'Unit 14',
        ]);

        // 1. Attempt to save Emergency Continuity Plan (Homeowner only)
        $continuityResponse = $this->actingAs($renterUser)->postJson('/dashboard/delegation/continuity-plan', [
            'activation_conditions' => ['medical_emergency'],
            'required_verification' => ['physician_statement'],
            'authorized_actions' => ['estate_entry'],
            'max_duration_days' => 30,
            'requires_admin_approval' => true,
            'notify_homeowner_on_trigger' => true,
            'notify_community_security' => true,
        ]);
        $continuityResponse->assertForbidden();

        // 2. Attempt to create a delegation with Homeowner access level
        $homeownerDelegationAttempt = $this->actingAs($renterUser)->postJson('/dashboard/delegation', [
            'name' => 'Fake Homeowner',
            'email' => 'fake@example.com',
            'relationship' => 'Homeowner',
            'access_level' => 'Full Authorized Representative',
        ]);
        $homeownerDelegationAttempt->assertForbidden();
    }
}
