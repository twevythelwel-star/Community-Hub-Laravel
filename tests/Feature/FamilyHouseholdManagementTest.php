<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\HouseholdManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyHouseholdManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_can_view_household_dashboard_and_auto_initialize_account(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => '14',
            'street' => 'Royal Palm Way',
        ]);

        $response = $this->actingAs($homeowner)->get('/dashboard/household');
        $response->assertOk();

        // Verify household is initialized for Unit 14
        $this->assertDatabaseHas('households', [
            'primary_homeowner_id' => $homeowner->id,
            'property_number' => 'Unit 14',
        ]);

        // Verify John Smith is registered as head of household
        $this->assertDatabaseHas('occupants', [
            'type' => 'household_member',
            'name' => 'John Smith',
            'role_in_household' => 'homeowner',
            'pass_category' => PassCategory::Homeowner->value,
        ]);
    }

    public function test_homeowner_can_seed_and_manage_smith_household_with_all_five_personas(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => '14',
            'phone' => '+18765550101',
        ]);

        app(HouseholdManagementService::class)->seedExampleSmithHousehold($homeowner);
        $this->actingAs($homeowner);

        $household = Household::where('primary_homeowner_id', $homeowner->id)->first();
        $this->assertNotNull($household);

        // Verify all 5 members exist with appropriate role & credentials
        $members = $household->members()->get()->keyBy('name');
        $this->assertCount(5, $members);

        // 1. John Smith — Homeowner
        $john = $members['John Smith'];
        $this->assertEquals('homeowner', $john->role_in_household);
        $this->assertEquals(PassCategory::Homeowner->value, $john->pass_category);
        $this->assertTrue($john->hasPermission('manage_household'));
        $this->assertTrue($john->hasPermission('view_billing'));
        $this->assertTrue($john->hasPermission('gate_access_24_7'));
        $this->assertNotNull($john->gatePass);
        $this->assertEquals(PassStatus::Active, $john->gatePass->status);

        // 2. Mary Smith — Spouse
        $mary = $members['Mary Smith'];
        $this->assertEquals('spouse', $mary->role_in_household);
        $this->assertEquals(PassCategory::Homeowner->value, $mary->pass_category);
        $this->assertTrue($mary->hasPermission('manage_guests'));
        $this->assertTrue($mary->hasPermission('view_billing'));
        $this->assertTrue($mary->hasPermission('gate_access_24_7'));
        $this->assertNotNull($mary->gatePass);

        // 3. Alex Smith — Child
        $alex = $members['Alex Smith'];
        $this->assertEquals('child', $alex->role_in_household);
        $this->assertEquals(PassCategory::Renter->value, $alex->pass_category); // Dependent Squircle
        $this->assertTrue($alex->hasPermission('gate_access_24_7'));
        $this->assertFalse($alex->hasPermission('view_billing'));
        $this->assertFalse($alex->hasPermission('manage_guests'));
        $this->assertNotNull($alex->access_schedule);
        $this->assertTrue($alex->access_schedule['curfew_enabled']);

        // 4. James Smith — Long-term occupant
        $james = $members['James Smith'];
        $this->assertEquals('long_term_occupant', $james->role_in_household);
        $this->assertEquals(PassCategory::LongTermOccupant->value, $james->pass_category);
        $this->assertTrue($james->hasPermission('gate_access_24_7'));
        $this->assertTrue($james->hasPermission('receive_emergency_alerts'));
        $this->assertNotNull($james->valid_until);

        // 5. Maria Smith — Caregiver
        $maria = $members['Maria Smith'];
        $this->assertEquals('caregiver', $maria->role_in_household);
        $this->assertEquals(PassCategory::Delegate->value, $maria->pass_category);
        $this->assertTrue($maria->hasPermission('gate_access_scheduled'));
        $this->assertTrue($maria->hasPermission('receive_emergency_alerts'));
        $this->assertNotNull($maria->access_schedule);
        $this->assertContains('Monday', $maria->access_schedule['days']);
        $this->assertEquals('8:00 AM – 5:00 PM', $maria->access_schedule['hours']);
    }

    public function test_homeowner_can_add_custom_household_member(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Alice Walker',
            'lot' => '22',
        ]);

        $response = $this->actingAs($homeowner)->postJson('/dashboard/household/members', [
            'name' => 'Robert Walker',
            'email' => 'robert@example.com',
            'phone' => '+18765559988',
            'role_in_household' => 'caregiver',
            'relationship_label' => 'Private Nurse',
            'permissions' => ['gate_access_scheduled', 'receive_emergency_alerts'],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('occupants', [
            'type' => 'household_member',
            'name' => 'Robert Walker',
            'role_in_household' => 'caregiver',
            'pass_category' => PassCategory::Delegate->value,
        ]);

        $member = HouseholdMember::where('name', 'Robert Walker')->first();
        $this->assertNotNull($member->gate_pass_id);
        $this->assertEquals(PassStatus::Active, $member->gatePass->status);
        $this->assertEquals('Unit 22', $member->gatePass->property);
    }

    public function test_homeowner_can_update_member_permissions_and_remove_member(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => '14',
        ]);

        $service = app(HouseholdManagementService::class);
        $household = $service->getOrCreateHousehold($homeowner);
        $caregiver = $service->addMember($household, [
            'name' => 'Maria Smith',
            'role_in_household' => 'caregiver',
        ]);

        $gatePassId = $caregiver->gate_pass_id;

        // Update permissions
        $updateResp = $this->actingAs($homeowner)->patchJson("/dashboard/household/members/{$caregiver->id}", [
            'permissions' => ['gate_access_scheduled', 'receive_emergency_alerts', 'request_maintenance'],
            'status' => 'suspended',
        ]);
        $updateResp->assertOk();

        $caregiver->refresh();
        $this->assertEquals('suspended', $caregiver->status);
        // Suspended, so it can be lifted again; revoked would be final.
        $this->assertEquals(PassStatus::Suspended, $caregiver->gatePass->status);

        // Delete member
        $delResp = $this->actingAs($homeowner)->deleteJson("/dashboard/household/members/{$caregiver->id}");
        $delResp->assertOk();

        $this->assertDatabaseMissing('occupants', ['type' => 'household_member', 'id' => $caregiver->id]);
        $this->assertDatabaseHas('gate_passes', [
            'id' => $gatePassId,
            'status' => PassStatus::Revoked->value,
        ]);
    }
}
