<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\DelegatedAccess;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeownerUnitAccessViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_associated_with_unit_14_sees_only_unit_14_people_and_access_categories(): void
    {
        // 1. Setup Homeowner for Unit 14
        $unit14Owner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Eleanor Vance',
            'email' => 'eleanor.vance@unit14.test',
            'lot' => 'Unit 14',
            'street' => 'Palm Grove Boulevard',
        ]);

        $unit14Property = Property::create([
            'property_code' => 'UNIT-14',
            'owner_user_id' => $unit14Owner->id,
            'lot_number' => '14',
            'street_address' => 'Palm Grove Boulevard',
        ]);

        // Household member for Unit 14
        $unit14Household = Household::create([
            'primary_homeowner_id' => $unit14Owner->id,
            'property_number' => 'Unit 14',
            'name' => 'Vance Household',
            'address' => 'Unit 14 Palm Grove Boulevard',
        ]);

        $unit14HouseholdMember = HouseholdMember::create([
            'household_id' => $unit14Household->id,
            'name' => 'Julian Vance',
            'email' => 'julian.vance@unit14.test',
            'role_in_household' => 'spouse',
            'relationship_label' => 'Spouse',
            'pass_category' => 'HOMEOWNER',
            'status' => 'active',
            'permissions' => ['gate_access', 'pool_access'],
        ]);

        // Long-term renter for Unit 14
        $unit14LongTermRenter = Renter::create([
            'homeowner_id' => $unit14Owner->id,
            'name' => 'Arthur Pendelton',
            'contact' => 'arthur.renter@unit14.test',
            'stay_type' => 'Long-Term Lease',
            'status' => 'Active',
            'lease_start' => now()->subMonths(2),
            'lease_end' => now()->addMonths(10),
            'lot' => 'Unit 14',
        ]);

        // Short-term Airbnb renter for Unit 14
        $unit14ShortTermAirbnb = Renter::create([
            'homeowner_id' => $unit14Owner->id,
            'name' => 'Chloe Airbnb Guest',
            'contact' => 'chloe.airbnb@unit14.test',
            'stay_type' => 'Short-Term Airbnb',
            'status' => 'Active',
            'lease_start' => now()->subDay(),
            'lease_end' => now()->addDays(5),
            'lot' => 'Unit 14',
            'notes' => 'Airbnb Reservation #ABNB-99824',
        ]);

        // Visitor for Unit 14
        $unit14Visitor = Visitor::create([
            'homeowner_id' => $unit14Owner->id,
            'name' => 'Sophia Guest',
            'contact' => 'sophia.guest@unit14.test',
            'type' => 'One-time',
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHours(3),
        ]);
        app(GatePassEngine::class)->issueGuestPass($unit14Visitor, $unit14Owner, PassCategory::Visitor);

        // Staff (Domestic Staff / Caregiver) for Unit 14
        $unit14Staff = DelegatedAccess::create([
            'grantor_user_id' => $unit14Owner->id,
            'name' => 'Maria Housekeeper',
            'email' => 'maria.staff@unit14.test',
            'relationship' => 'Domestic Staff',
            'authorization_type' => 'domestic_staff',
            'access_level' => 'Domestic Staff',
            'status' => 'active',
            'permissions' => ['gate_access'],
        ]);
        $unit14Staff->issueDelegateGatePass(app(GatePassEngine::class), $unit14Owner);

        // Contractor for Unit 14
        $unit14Contractor = Visitor::create([
            'homeowner_id' => $unit14Owner->id,
            'name' => 'FixIt HVAC Technician',
            'contact' => '+18765551414',
            'type' => 'Contractor',
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addDay(),
        ]);
        app(GatePassEngine::class)->issueGuestPass($unit14Contractor, $unit14Owner, PassCategory::Contractor);

        // Legacy Contact for Unit 14
        $unit14LegacyContact = DelegatedAccess::create([
            'grantor_user_id' => $unit14Owner->id,
            'name' => 'Harrison Sterling Attorney',
            'email' => 'harrison.law@unit14.test',
            'relationship' => 'Attorney / Succession Delegate',
            'authorization_type' => 'legacy_contact',
            'access_level' => 'Legacy Delegate',
            'status' => 'active',
            'permissions' => ['emergency_communications', 'gate_access'],
        ]);
        $unit14LegacyContact->issueDelegateGatePass(app(GatePassEngine::class), $unit14Owner);

        // 2. Setup Homeowner for Unit 15 (Isolation Invariant verification)
        $unit15Owner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Montgomery Burns',
            'email' => 'burns@unit15.test',
            'lot' => 'Unit 15',
            'street' => 'Palm Grove Boulevard',
        ]);

        $unit15Household = Household::create([
            'primary_homeowner_id' => $unit15Owner->id,
            'property_number' => 'Unit 15',
            'name' => 'Burns Household',
        ]);

        $unit15HouseholdMember = HouseholdMember::create([
            'household_id' => $unit15Household->id,
            'name' => 'Waylon Smithers',
            'email' => 'smithers@unit15.test',
            'role_in_household' => 'spouse',
            'relationship_label' => 'Executive Household Aide',
            'pass_category' => 'HOMEOWNER',
            'status' => 'active',
        ]);

        $unit15Renter = Renter::create([
            'homeowner_id' => $unit15Owner->id,
            'name' => 'Unit 15 Leased Tenant',
            'contact' => 'tenant15@unit15.test',
            'stay_type' => 'Long-Term Lease',
            'status' => 'Active',
            'lease_start' => now()->subMonth(),
            'lease_end' => now()->addMonths(6),
            'lot' => 'Unit 15',
        ]);

        $unit15Visitor = Visitor::create([
            'homeowner_id' => $unit15Owner->id,
            'name' => 'Unit 15 Private Visitor',
            'contact' => 'visitor15@unit15.test',
            'type' => 'One-time',
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHours(1),
        ]);

        $unit15Contractor = Visitor::create([
            'homeowner_id' => $unit15Owner->id,
            'name' => 'Unit 15 Private Roofer',
            'contact' => 'roofer15@unit15.test',
            'type' => 'Contractor',
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addDays(2),
        ]);

        $unit15Legacy = DelegatedAccess::create([
            'grantor_user_id' => $unit15Owner->id,
            'name' => 'Unit 15 Secret Attorney',
            'email' => 'attorney15@unit15.test',
            'relationship' => 'Attorney',
            'authorization_type' => 'legacy_contact',
            'access_level' => 'Legacy Delegate',
            'status' => 'active',
        ]);

        // 3. Act as Homeowner of Unit 14 and retrieve People & Access
        $response = $this->actingAs($unit14Owner)->getJson('/dashboard/delegation');
        $response->assertOk();

        $data = $response->json();

        // 4. Verify Title & Unit Label
        $this->assertEquals('Unit 14 — People & Access', $data['pageTitle']);
        $this->assertEquals('Unit 14', $data['unitLabel']);

        // 5. Verify the 7 Categories are present and populated with Unit 14 members
        $this->assertArrayHasKey('homeownerSections', $data);
        $sections = collect($data['homeownerSections'])->keyBy('key');
        $this->assertTrue($sections->has('residents'));
        $this->assertTrue($sections->has('long_term_occupants'));
        $this->assertTrue($sections->has('short_term_rentals'));
        $this->assertTrue($sections->has('visitors'));
        $this->assertTrue($sections->has('staff'));
        $this->assertTrue($sections->has('contractors'));
        $this->assertTrue($sections->has('legacy_contacts'));

        // Check Residents: Homeowner Eleanor Vance + Household Member Julian Vance
        $residentsNames = collect($sections['residents']['items'])->pluck('name')->all();
        $this->assertContains('Eleanor Vance', $residentsNames);
        $this->assertContains('Julian Vance', $residentsNames);

        // Check Long-Term Guests / Renters: Arthur Pendelton
        $longTermNames = collect($sections['long_term_occupants']['items'])->pluck('name')->all();
        $this->assertContains('Arthur Pendelton', $longTermNames);

        // Check Short-Term Rental: Chloe Airbnb Guest
        $shortTermNames = collect($sections['short_term_rentals']['items'])->pluck('name')->all();
        $this->assertContains('Chloe Airbnb Guest', $shortTermNames);

        // Check Visitors: Sophia Guest
        $visitorNames = collect($sections['visitors']['items'])->pluck('name')->all();
        $this->assertContains('Sophia Guest', $visitorNames);

        // Check Staff: Maria Housekeeper
        $staffNames = collect($sections['staff']['items'])->pluck('name')->all();
        $this->assertContains('Maria Housekeeper', $staffNames);

        // Check Contractors: FixIt HVAC Technician
        $contractorNames = collect($sections['contractors']['items'])->pluck('name')->all();
        $this->assertContains('FixIt HVAC Technician', $contractorNames);

        // Check Legacy Contacts: Harrison Sterling Attorney
        $legacyNames = collect($sections['legacy_contacts']['items'])->pluck('name')->all();
        $this->assertContains('Harrison Sterling Attorney', $legacyNames);

        // 6. STRICT ISOLATION INVARIANT VERIFICATION:
        // Assert that zero personal information or records from Unit 15 are visible to Unit 14 Homeowner!
        $allPeopleNames = collect($data['authorizedPeople'])->pluck('name')->all();

        $this->assertNotContains('Montgomery Burns', $allPeopleNames);
        $this->assertNotContains('Waylon Smithers', $allPeopleNames);
        $this->assertNotContains('Unit 15 Leased Tenant', $allPeopleNames);
        $this->assertNotContains('Unit 15 Private Visitor', $allPeopleNames);
        $this->assertNotContains('Unit 15 Private Roofer', $allPeopleNames);
        $this->assertNotContains('Unit 15 Secret Attorney', $allPeopleNames);

        // Check all emails across all returned records to guarantee no leak
        $allEmails = collect($data['authorizedPeople'])->pluck('email')->filter()->all();
        foreach ($allEmails as $email) {
            $this->assertStringNotContainsString('unit15', $email, 'Privacy leak: Unit 15 email returned in Unit 14 view');
        }
    }
}
