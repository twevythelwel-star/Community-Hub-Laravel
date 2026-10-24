<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossPropertyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected User $homeownerA;
    protected User $homeownerB;
    protected Property $propertyA;
    protected Property $propertyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->homeownerA = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Alice Homeowner',
            'lot' => '10A',
        ]);

        $this->homeownerB = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Bob Homeowner',
            'lot' => '20B',
        ]);

        $this->propertyA = Property::create([
            'property_code' => 'UNIT-10A',
            'lot_number' => '10A',
            'street' => 'Palmetto Way',
            'user_id' => $this->homeownerA->id,
        ]);

        $this->propertyB = Property::create([
            'property_code' => 'UNIT-20B',
            'lot_number' => '20B',
            'street' => 'Hibiscus Lane',
            'user_id' => $this->homeownerB->id,
        ]);
    }

    public function test_homeowner_cannot_modify_or_delete_another_properties_visitor(): void
    {
        $visitorB = Visitor::create([
            'homeowner_id' => $this->homeownerB->id,
            'property_id' => $this->propertyB->id,
            'name' => 'Bob Guest',
            'type' => 'Guest',
            'arrival_date' => now()->toDateString(),
            'expected_at' => now()->addHours(2),
            'status' => 'Expected',
        ]);

        // Homeowner A attempts to update Bob's visitor
        $this->actingAs($this->homeownerA)
            ->putJson("/dashboard/visitors/{$visitorB->id}", [
                'name' => 'Malicious Hijack',
            ])
            ->assertForbidden();

        // Homeowner A attempts to delete Bob's visitor
        $this->actingAs($this->homeownerA)
            ->delete("/dashboard/visitors/{$visitorB->id}")
            ->assertForbidden();

        // Homeowner A attempts to extend Bob's visitor pass
        $this->actingAs($this->homeownerA)
            ->post("/dashboard/visitors/{$visitorB->id}/extend")
            ->assertForbidden();

        // Homeowner A attempts to resend Bob's visitor pass
        $this->actingAs($this->homeownerA)
            ->post("/dashboard/visitors/{$visitorB->id}/resend")
            ->assertForbidden();
    }

    public function test_homeowner_cannot_modify_or_delete_another_households_member(): void
    {
        $householdB = Household::create([
            'name' => 'Household B',
            'property_number' => '20B',
            'address' => 'Hibiscus Lane 20B',
            'primary_homeowner_id' => $this->homeownerB->id,
        ]);

        $memberB = HouseholdMember::create([
            'household_id' => $householdB->id,
            'name' => 'Bob Daughter',
            'role_in_household' => 'child',
            'relationship_label' => 'Daughter',
            'pass_category' => PassCategory::Homeowner->value,
        ]);

        // Homeowner A attempts to update Bob's household member
        $this->actingAs($this->homeownerA)
            ->patchJson("/dashboard/household/members/{$memberB->id}", [
                'name' => 'Hacked Name',
            ])
            ->assertForbidden();

        // Homeowner A attempts to delete Bob's household member
        $this->actingAs($this->homeownerA)
            ->delete("/dashboard/household/members/{$memberB->id}")
            ->assertForbidden();
    }

    public function test_homeowner_cannot_modify_or_delete_another_properties_renter(): void
    {
        $renterB = Renter::create([
            'homeowner_id' => $this->homeownerB->id,
            'property_id' => $this->propertyB->id,
            'name' => 'Bob Tenant',
            'stay_type' => 'Long-term (Renter)',
            'lease_start' => now()->toDateString(),
            'lease_end' => now()->addYear()->toDateString(),
            'status' => 'Active',
            'lot' => '20B',
        ]);

        // Homeowner A attempts to update Bob's renter
        $this->actingAs($this->homeownerA)
            ->patchJson("/dashboard/renters/{$renterB->id}", [
                'name' => 'Hacked Renter',
            ])
            ->assertForbidden();

        // Homeowner A attempts to delete Bob's renter
        $this->actingAs($this->homeownerA)
            ->delete("/dashboard/renters/{$renterB->id}")
            ->assertForbidden();
    }

    public function test_homeowner_cannot_download_another_households_digital_wallet_pass(): void
    {
        $passB = GatePass::create([
            'pass_id' => 'GP-ISOLATION-99',
            'user_id' => $this->homeownerB->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => $this->homeownerB->name,
            'property' => $this->homeownerB->propertyLabel(),
            'access_zone' => PassCategory::Homeowner->defaultZone(),
            'designated_gate' => \App\Enums\GateId::Any,
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 100,
            'uses_count' => 0,
            'metadata' => [],
        ]);

        // Homeowner A attempts to download Bob's Apple Wallet .pkpass
        $this->actingAs($this->homeownerA)
            ->get("/dashboard/wallet/apple-pass/{$passB->pass_id}")
            ->assertForbidden();

        // Homeowner A attempts to fetch Bob's Google Wallet payload
        $this->actingAs($this->homeownerA)
            ->getJson("/dashboard/wallet/google-pass/{$passB->pass_id}")
            ->assertForbidden();

        // Homeowner A attempts to report Bob's pass as lost/stolen
        $this->actingAs($this->homeownerA)
            ->postJson('/dashboard/wallet/report-lost', [
                'pass_id' => $passB->pass_id,
            ])
            ->assertForbidden();
    }

    public function test_homeowner_cannot_view_or_pay_another_households_statement(): void
    {
        $invoiceB = Invoice::create([
            'user_id' => $this->homeownerB->id,
            'property_id' => $this->propertyB->id,
            'reference' => 'INV-2026-BOB-001',
            'amount_minor' => 50000,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'status' => 'Unpaid',
            'due_on' => now()->addDays(30),
        ]);

        // Homeowner A attempts to download Bob's invoice PDF
        $this->actingAs($this->homeownerA)
            ->get("/dashboard/billing/invoices/{$invoiceB->id}/pdf")
            ->assertForbidden();

        // Homeowner A attempts to settle Bob's invoice via administrative pay route
        $this->actingAs($this->homeownerA)
            ->post("/dashboard/billing/invoices/{$invoiceB->id}/pay")
            ->assertForbidden();
    }

    public function test_homeowner_cannot_modify_or_revoke_another_properties_delegated_access(): void
    {
        $delegationB = DelegatedAccess::create([
            'grantor_user_id' => $this->homeownerB->id,
            'name' => 'Bob Contractor',
            'email' => 'bob.contractor@example.com',
            'contact_phone' => '876-555-0199',
            'relationship' => 'contractor',
            'authorization_type' => 'contractor',
            'access_level' => 'restricted',
            'status' => 'active',
            'access_rules' => ['gate_access' => true],
        ]);

        // Homeowner A attempts to update rules on Bob's delegation
        $this->actingAs($this->homeownerA)
            ->patchJson("/dashboard/delegation/{$delegationB->id}/rules", [
                'access_rules' => ['gate_access' => false],
            ])
            ->assertForbidden();

        // Homeowner A attempts to revoke Bob's delegation
        $this->actingAs($this->homeownerA)
            ->postJson("/dashboard/delegation/{$delegationB->id}/revoke")
            ->assertForbidden();
    }
}
