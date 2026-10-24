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

class ShortTermRentalAccessViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
    }

    public function test_short_term_rental_guest_receives_dedicated_my_stay_view_scoped_to_unit_14(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Unit 14',
        ]);

        $property = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => '14',
            'property_code' => 'UNIT-14',
            'street_address' => '14 Royal Palm Boulevard',
        ]);

        // Create short-term guest
        $guestUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Alice Guest',
            'lot' => 'Unit 14',
        ]);

        $renter = Renter::create([
            'user_id' => $guestUser->id,
            'name' => 'Alice Guest',
            'property_id' => $property->id,
            'lot' => 'Unit 14',
            'status' => 'Active',
            'stay_type' => 'Short-Term Rental / Airbnb',
            'lease_start' => Carbon::parse('2026-10-05'),
            'lease_end' => Carbon::parse('2026-10-09'),
        ]);

        // Homeowner creates domestic staff authorized for Unit 14
        DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'property_id' => $property->id,
            'name' => 'Maria Cleaning',
            'email' => 'maria@cleaners.com',
            'relationship' => 'Cleaner',
            'access_level' => 'Domestic Staff',
            'authorization_type' => 'domestic_staff',
            'duration_type' => 'recurring',
            'status' => 'active',
            'permissions' => ['gate_access'],
            'access_rules' => ['gate_access' => true],
        ]);

        $response = $this->actingAs($guestUser)->getJson('/dashboard/delegation');
        $response->assertOk();

        $data = $response->json();

        // 1. Page Title & Badging
        $this->assertEquals('Unit 14 — My Stay', $data['pageTitle']);
        $this->assertEquals('Unit 14', $data['unitLabel']);
        $this->assertEquals('Short-Term Guest Scope • Reservation Bounded', $data['scopeBadge']);
        $this->assertStringContainsString('Unit 14', $data['pageSubtitle']);

        // 2. Exactly 4 Designated Sections
        $this->assertCount(4, $data['sections']);
        $sectionKeys = array_column($data['sections'], 'key');
        $this->assertEquals(['my_stay', 'my_visitors', 'my_access', 'property_services'], $sectionKeys);

        // Confirm excluded sections are absent
        $this->assertNotContains('legacy_contacts', $sectionKeys);
        $this->assertNotContains('residents', $sectionKeys);
        $this->assertNotContains('long_term_occupants', $sectionKeys);

        // 3. Section 1: My Stay (Reservation details, dates, host name)
        $myStaySection = collect($data['sections'])->firstWhere('key', 'my_stay');
        $this->assertEquals('My Stay', $myStaySection['title']);
        $this->assertCount(1, $myStaySection['items']);
        $stayItem = $myStaySection['items'][0];
        $this->assertEquals('Alice Guest', $stayItem['name']);
        $this->assertEquals('Airbnb Guest', $stayItem['roleOrTitle']);
        $this->assertEquals('John Smith', $stayItem['permissions']['host']);
        $this->assertEquals('Unit 14', $stayItem['permissions']['property']);
        $this->assertFalse($stayItem['canManage']);

        // 4. Section 3: My Access (Dynamic QR & Gate clearance)
        $myAccessSection = collect($data['sections'])->firstWhere('key', 'my_access');
        $this->assertEquals('My Access', $myAccessSection['title']);
        $this->assertCount(1, $myAccessSection['items']);
        $accessItem = $myAccessSection['items'][0];
        $this->assertNotNull($accessItem['credential']['passId']);
        $this->assertEquals('GP-AIR', $accessItem['credential']['categoryCode']);
        $this->assertEquals('Main Gate & Guest Ingress Cleared', $accessItem['permissions']['gate_access']);

        // 5. Section 4: Property Services (Authorized staff/contractors for Unit 14, strictly read-only)
        $propertyServicesSection = collect($data['sections'])->firstWhere('key', 'property_services');
        $this->assertEquals('Property Services', $propertyServicesSection['title']);
        $this->assertCount(1, $propertyServicesSection['items']);
        $serviceItem = $propertyServicesSection['items'][0];
        $this->assertEquals('Maria Cleaning', $serviceItem['name']);
        $this->assertEquals('Authorized Property Service', $serviceItem['categoryLabel']);
        $this->assertFalse($serviceItem['canManage'], 'Property services must be read-only for short-term guests');

        // 6. Permissions Payload
        $permissions = $data['permissions'];
        $this->assertTrue($permissions['isShortTerm']);
        $this->assertFalse($permissions['isRenter']);
        $this->assertFalse($permissions['isHomeowner']);
        $this->assertEquals('2026-10-09', $permissions['checkoutDate']);
        $this->assertFalse($permissions['canSee']['ownLegacyContacts']);
        $this->assertFalse($permissions['canSee']['ownHousehold']);
        $this->assertFalse($permissions['canAct']['createPermanentCredentials']);
        $this->assertFalse($permissions['canAct']['authorizeStaff']);
        $this->assertFalse($permissions['canAct']['manageLegacyContacts']);
    }

    public function test_short_term_guest_sees_own_visitors_but_does_not_see_homeowner_legacy_contacts_or_unrelated_residents(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Unit 14',
        ]);

        $property = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => '14',
            'property_code' => 'UNIT-14',
            'street_address' => '14 Royal Palm Boulevard',
        ]);

        // Homeowner registers Legacy Contact
        DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'property_id' => $property->id,
            'name' => 'Attorney Sarah Jenkins',
            'email' => 'sarah.jenkins@lawfirm.com',
            'relationship' => 'Attorney / Legacy Contact',
            'access_level' => 'Legacy Delegate',
            'authorization_type' => 'legacy_contact',
            'duration_type' => 'manual_revocation',
            'status' => 'active',
            'permissions' => ['emergency_communications', 'gate_access'],
            'access_rules' => ['gate_access' => true],
        ]);

        // Homeowner registers a visitor of their own
        Visitor::create([
            'homeowner_id' => $homeowner->id,
            'name' => 'Homeowner Social Guest',
            'type' => 'Social',
            'status' => VisitorStatus::Expected,
            'expected_at' => Carbon::now(),
        ]);

        // Unrelated resident in Unit 15
        User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Robert Neighbor',
            'lot' => 'Unit 15',
        ]);

        // Short-term guest in Unit 14
        $guestUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Alice Guest',
            'lot' => 'Unit 14',
        ]);

        Renter::create([
            'user_id' => $guestUser->id,
            'name' => 'Alice Guest',
            'property_id' => $property->id,
            'lot' => 'Unit 14',
            'status' => 'Active',
            'stay_type' => 'Short-Term Rental / Airbnb',
            'lease_start' => Carbon::parse('2026-10-05'),
            'lease_end' => Carbon::parse('2026-10-09'),
        ]);

        // Guest registers Visitor 1 and Visitor 2
        Visitor::create([
            'homeowner_id' => $guestUser->id,
            'name' => 'Visitor 1 (David Clark)',
            'type' => 'Social',
            'status' => VisitorStatus::Expected,
            'expected_at' => Carbon::parse('2026-10-06 14:00:00'),
        ]);

        Visitor::create([
            'homeowner_id' => $guestUser->id,
            'name' => 'Visitor 2 (Emma Watson)',
            'type' => 'Social',
            'status' => VisitorStatus::Expected,
            'expected_at' => Carbon::parse('2026-10-07 10:00:00'),
        ]);

        $response = $this->actingAs($guestUser)->getJson('/dashboard/delegation');
        $response->assertOk();

        $data = $response->json();
        $allPeople = collect($data['authorizedPeople']);
        $names = $allPeople->pluck('name')->toArray();

        // 1. Guest sees their own visitors
        $this->assertContains('Visitor 1 (David Clark)', $names);
        $this->assertContains('Visitor 2 (Emma Watson)', $names);

        // 2. Guest DOES NOT see homeowner's Legacy Contacts
        $this->assertNotContains('Attorney Sarah Jenkins', $names);

        // 3. Guest DOES NOT see homeowner's personal visitors
        $this->assertNotContains('Homeowner Social Guest', $names);

        // 4. Guest DOES NOT see unrelated neighbors
        $this->assertNotContains('Robert Neighbor', $names);

        // Check My Visitors section specifically
        $myVisitorsSection = collect($data['sections'])->firstWhere('key', 'my_visitors');
        $this->assertCount(2, $myVisitorsSection['items']);
        $visitorNames = collect($myVisitorsSection['items'])->pluck('name')->toArray();
        $this->assertContains('Visitor 1 (David Clark)', $visitorNames);
        $this->assertContains('Visitor 2 (Emma Watson)', $visitorNames);
    }

    public function test_short_term_guest_cannot_create_unrestricted_permanent_credentials(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Unit 14',
        ]);

        $property = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => '14',
            'property_code' => 'UNIT-14',
            'street_address' => '14 Royal Palm Boulevard',
        ]);

        $guestUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Alice Guest',
            'lot' => 'Unit 14',
        ]);

        Renter::create([
            'user_id' => $guestUser->id,
            'name' => 'Alice Guest',
            'property_id' => $property->id,
            'lot' => 'Unit 14',
            'status' => 'Active',
            'stay_type' => 'Short-Term Rental / Airbnb',
            'lease_start' => Carbon::parse('2026-10-05'),
            'lease_end' => Carbon::parse('2026-10-09'),
        ]);

        $response = $this->actingAs($guestUser)->postJson('/dashboard/delegation', [
            'name' => 'Permanent Guest',
            'email' => 'permanent@example.com',
            'relationship' => 'Friend',
            'access_level' => 'Visitor',
            'duration_type' => 'manual_revocation', // Forbidden
            'access_rules' => ['gate_access' => true],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['duration_type']);
    }

    public function test_short_term_guest_credentials_are_clamped_to_checkout_date(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Unit 14',
        ]);

        $property = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => '14',
            'property_code' => 'UNIT-14',
            'street_address' => '14 Royal Palm Boulevard',
        ]);

        $guestUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Alice Guest',
            'lot' => 'Unit 14',
        ]);

        Renter::create([
            'user_id' => $guestUser->id,
            'name' => 'Alice Guest',
            'property_id' => $property->id,
            'lot' => 'Unit 14',
            'status' => 'Active',
            'stay_type' => 'Short-Term Rental / Airbnb',
            'lease_start' => Carbon::parse('2026-10-05'),
            'lease_end' => Carbon::parse('2026-10-09'),
        ]);

        // Attempt to create a pass expiring on Oct 20 (way after Oct 09 checkout)
        $response = $this->actingAs($guestUser)->postJson('/dashboard/delegation', [
            'name' => 'Weekend Friend',
            'email' => 'friend@example.com',
            'relationship' => 'Friend',
            'access_level' => 'Visitor',
            'duration_type' => 'custom',
            'starts_at' => '2026-10-06',
            'expires_at' => '2026-10-20',
            'access_rules' => ['gate_access' => true],
        ]);

        $response->assertStatus(201);

        $delegation = DelegatedAccess::where('email', 'friend@example.com')->first();
        $this->assertNotNull($delegation);
        // Assert expiration was clamped to checkout date (2026-10-09 end of day)
        $this->assertEquals('2026-10-09 23:59:59', $delegation->expires_at->toDateTimeString());
    }

    public function test_short_term_guest_cannot_modify_property_services_or_revoke_homeowner_staff(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Unit 14',
        ]);

        $property = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => '14',
            'property_code' => 'UNIT-14',
            'street_address' => '14 Royal Palm Boulevard',
        ]);

        $guestUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Alice Guest',
            'lot' => 'Unit 14',
        ]);

        Renter::create([
            'user_id' => $guestUser->id,
            'name' => 'Alice Guest',
            'property_id' => $property->id,
            'lot' => 'Unit 14',
            'status' => 'Active',
            'stay_type' => 'Short-Term Rental / Airbnb',
            'lease_start' => Carbon::parse('2026-10-05'),
            'lease_end' => Carbon::parse('2026-10-09'),
        ]);

        $homeownerStaff = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'property_id' => $property->id,
            'name' => 'Carlos Handyman',
            'email' => 'carlos@repairs.com',
            'relationship' => 'Contractor',
            'access_level' => 'Contractor',
            'authorization_type' => 'contractor',
            'duration_type' => '1_week',
            'status' => 'active',
            'permissions' => ['gate_access'],
            'access_rules' => ['gate_access' => true],
        ]);

        // Attempt to revoke homeowner staff -> 403
        $revokeResponse = $this->actingAs($guestUser)->postJson("/dashboard/delegation/{$homeownerStaff->id}/revoke");
        $revokeResponse->assertForbidden();

        // Attempt to update rules on homeowner staff -> 403
        $updateResponse = $this->actingAs($guestUser)->patchJson("/dashboard/delegation/{$homeownerStaff->id}/rules", [
            'access_rules' => ['gate_access' => false],
        ]);
        $updateResponse->assertForbidden();
    }

    public function test_short_term_guest_cannot_save_continuity_plan(): void
    {
        $guestUser = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'Alice Guest',
            'lot' => 'Unit 14',
        ]);

        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'lot' => 'Unit 14',
        ]);

        $property = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => '14',
            'property_code' => 'UNIT-14',
            'street_address' => '14 Royal Palm Boulevard',
        ]);

        Renter::create([
            'user_id' => $guestUser->id,
            'name' => 'Alice Guest',
            'property_id' => $property->id,
            'lot' => 'Unit 14',
            'status' => 'Active',
            'stay_type' => 'Short-Term Rental / Airbnb',
            'lease_start' => Carbon::parse('2026-10-05'),
            'lease_end' => Carbon::parse('2026-10-09'),
        ]);

        $response = $this->actingAs($guestUser)->postJson('/dashboard/delegation/continuity-plan', [
            'activation_conditions' => ['medical_incapacity'],
            'required_verification' => ['physician_certification'],
            'authorized_actions' => ['visitor_clearance'],
            'max_duration_days' => 30,
            'requires_admin_approval' => true,
            'notify_homeowner_on_trigger' => true,
            'notify_community_security' => true,
        ]);

        $response->assertForbidden();
    }
}
