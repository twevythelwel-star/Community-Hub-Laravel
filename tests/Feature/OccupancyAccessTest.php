<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\GatePass;
use App\Models\User;
use App\Services\Occupancy\OccupancyCategoryResolver;
use App\Services\Occupancy\OccupancyCensusQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Who's on property" is a list of which homes are occupied and which are
 * empty. unit() and both CSV exports had no check, and the page only
 * defaulted a resident to their own unit, so any signed-in account could
 * see who was inside any unit or download the whole roster.
 */
class OccupancyAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resident = User::factory()->role(UserRole::Homeowner)->create(['lot' => 'Lot 1']);

        foreach (['Unit 1' => 'Ana Own-Home', 'Unit 14' => 'Ben Neighbour', 'Unit 2' => 'Cy Elsewhere'] as $unit => $holder) {
            GatePass::create([
                'pass_id' => 'GP-'.str_replace(' ', '', $unit),
                'category' => PassCategory::Homeowner,
                'holder_name' => $holder,
                'property' => $unit,
                'status' => PassStatus::CheckedIn,
                'checked_in_at' => now()->subHour(),
            ]);
        }
    }

    /** @return list<string> */
    private function namesOnPage(User $user, string $query = ''): array
    {
        $occupants = $this->actingAs($user)->get('/dashboard/occupancy'.$query)->assertOk()
            ->viewData('page')['props']['occupants']['data'];

        return collect($occupants)->pluck('name')->sort()->values()->all();
    }

    public function test_a_resident_sees_only_their_own_unit_whatever_they_ask_for(): void
    {
        $this->assertSame(['Ana Own-Home'], $this->namesOnPage($this->resident));
        $this->assertSame(['Ana Own-Home'], $this->namesOnPage($this->resident, '?unit=Unit%202'));

        $props = $this->get('/dashboard/occupancy')->viewData('page')['props'];
        $this->assertSame([], $props['units'], 'the per-unit list shows which homes are empty');
        $this->assertSame('Unit 1', $props['userUnit']);
        $this->assertFalse($props['canManage']);
    }

    public function test_a_resident_without_a_unit_sees_no_one(): void
    {
        $this->assertSame([], $this->namesOnPage(User::factory()->role(UserRole::Homeowner)->create(['lot' => null])));
    }

    public function test_the_unit_drilldown_is_staff_or_your_own_unit(): void
    {
        $this->actingAs($this->resident)->getJson('/dashboard/occupancy/unit/Unit%2014')->assertForbidden();
        $this->getJson('/dashboard/occupancy/unit/Unit%201')->assertOk();

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->getJson('/dashboard/occupancy/unit/Unit%2014')
            ->assertOk()
            ->assertJsonPath('occupants.0.name', 'Ben Neighbour');
    }

    public function test_the_hierarchy_without_a_unit_of_your_own_is_empty_not_the_estate(): void
    {
        // A null scope is the whole estate to the service.
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create(['lot' => null]))
            ->getJson('/dashboard/occupancy/hierarchy')
            ->assertOk()
            ->assertExactJson(['success' => true, 'hierarchy' => []]);
    }

    public function test_only_security_and_administration_can_export(): void
    {
        foreach (['/dashboard/occupancy/export', '/dashboard/occupancy/muster/export'] as $uri) {
            $this->actingAs($this->resident)->get($uri)->assertForbidden();
        }

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/occupancy/export')
            ->assertOk();
    }

    public function test_staff_still_see_the_whole_estate(): void
    {
        $officer = User::factory()->role(UserRole::Security)->create();

        $this->assertSame(['Ana Own-Home', 'Ben Neighbour', 'Cy Elsewhere'], $this->namesOnPage($officer));
        $this->assertNotSame([], $this->get('/dashboard/occupancy')->viewData('page')['props']['units']);
    }

    public function test_a_unit_filter_matches_that_unit_exactly(): void
    {
        // It was a substring match: "Unit 1" also returned Unit 14.
        $names = app(OccupancyCensusQueryService::class)->getAllCurrentOccupants(unitFilter: 'Unit 1')->pluck('name')->all();

        $this->assertSame(['Ana Own-Home'], $names);

        $service = app(OccupancyCategoryResolver::class);
        foreach (['Unit 14', 'Lot 14', '#14', '14', '14, Royal Palm Way'] as $label) {
            $this->assertSame('Unit 14', $service->normalizeUnit($label), $label);
        }
        $this->assertSame('Common Grounds', $service->normalizeUnit('Common Grounds'));
    }
}
