<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\GatePass;
use App\Models\MusterRollCall;
use App\Models\MusterSession;
use App\Models\User;
use App\Services\Occupancy\OccupancyCensusQueryService;
use App\Support\Csv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The emergency roll call is only useful if it is right. It counted visitors
 * who had left weeks ago as inside, a second muster silently ended the first,
 * the default assembly point was invented, records could be edited after the
 * fact or invented outright, and the exports ran spreadsheet formulas.
 */
class MusterSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['occupancy.assembly_point' => null, 'occupancy.stale_after_hours' => 24]);
        $this->officer = User::factory()->role(UserRole::Security)->create();
    }

    private function checkedIn(string $holder, PassCategory $category, string $unit, \DateTimeInterface $at): GatePass
    {
        return GatePass::create([
            'pass_id' => 'GP-'.strtoupper(substr(md5($holder), 0, 8)),
            'category' => $category,
            'holder_name' => $holder,
            'property' => $unit,
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => $at,
        ]);
    }

    private function startMuster(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->officer)->postJson('/dashboard/occupancy/muster/start', $overrides + [
            'incident_type' => 'fire',
            'title' => 'Unit 14 fire',
            'assembly_point' => 'North Clubhouse Lawn',
        ]);
    }

    public function test_a_long_gone_visitor_is_not_counted_inside_but_is_on_the_roll_as_unverified(): void
    {
        $this->checkedIn('Fresh Visitor', PassCategory::Visitor, 'Unit 14', now()->subHours(2));
        $this->checkedIn('Gone Visitor', PassCategory::Visitor, 'Unit 14', now()->subDays(3));
        $this->checkedIn('Long-Stay Resident', PassCategory::Homeowner, 'Unit 14', now()->subDays(30));

        $inside = app(OccupancyCensusQueryService::class)->getAllCurrentOccupants()->pluck('name')->sort()->values()->all();
        $this->assertSame(['Fresh Visitor', 'Long-Stay Resident'], $inside, 'residents are never stale');

        $this->startMuster()->assertOk();

        $statuses = MusterRollCall::pluck('status', 'occupant_name')->all();
        $this->assertSame('missing', $statuses['Fresh Visitor']);
        $this->assertSame('unverified', $statuses['Gone Visitor'], 'not dropped: they may still be inside');
        $this->assertSame(1, MusterSession::first()->getCounts()['unverified']);
    }

    public function test_a_second_muster_is_refused_instead_of_ending_the_first(): void
    {
        $this->startMuster()->assertOk();
        $this->startMuster(['incident_type' => 'drill', 'title' => 'Drill'])
            ->assertStatus(409)
            ->assertJsonValidationErrors('muster');

        $this->assertSame(1, MusterSession::where('status', 'active')->count());
        $this->assertSame('Unit 14 fire', MusterSession::where('status', 'active')->value('title'));
    }

    public function test_the_assembly_point_is_named_or_configured_never_invented(): void
    {
        $this->startMuster(['assembly_point' => null])->assertUnprocessable()->assertJsonValidationErrors('assembly_point');
        $this->assertSame(0, MusterSession::count());

        config(['occupancy.assembly_point' => 'Gate 2 Car Park']);
        $this->startMuster(['assembly_point' => null])->assertOk();
        $this->assertSame('Gate 2 Car Park', MusterSession::value('assembly_point'));
    }

    public function test_a_long_contact_does_not_stop_the_muster(): void
    {
        $pass = $this->checkedIn('Long Email Guest', PassCategory::Visitor, 'Unit 14', now()->subHour());
        $pass->update(['metadata' => ['contact' => str_repeat('a', 80).'@example.org', 'vehicle' => str_repeat('V', 90)]]);

        $this->startMuster()->assertOk();

        $row = MusterRollCall::first();
        $this->assertSame(str_repeat('a', 80).'@example.org', $row->contact);
        $this->assertSame(64, mb_strlen($row->vehicle));
    }

    public function test_a_resolved_roll_call_cannot_be_changed(): void
    {
        $this->checkedIn('Pam Beesly', PassCategory::Visitor, 'Unit 14', now()->subHour());
        $this->startMuster()->assertOk();
        $session = MusterSession::first();
        $session->update(['status' => 'resolved', 'resolved_at' => now()]);

        $this->postJson('/dashboard/occupancy/muster/status', [
            'session_id' => $session->id,
            'occupant_id' => MusterRollCall::value('occupant_id'),
            'status' => 'safe',
        ])->assertUnprocessable()->assertJsonValidationErrors('occupant_id');

        $this->assertSame('missing', MusterRollCall::value('status'));
    }

    public function test_an_unknown_person_cannot_be_added_to_the_roll(): void
    {
        $this->startMuster()->assertOk();

        $this->postJson('/dashboard/occupancy/muster/status', [
            'session_id' => MusterSession::value('id'),
            'occupant_id' => 'pass_999999',
            'status' => 'safe',
        ])->assertUnprocessable()->assertJsonValidationErrors('occupant_id');

        $this->assertSame(0, MusterRollCall::count());
    }

    public function test_exports_do_not_run_spreadsheet_formulas(): void
    {
        $this->checkedIn('=HYPERLINK("http://evil.example","Click")', PassCategory::Visitor, 'Unit 14', now()->subHour());

        $roster = $this->actingAs($this->officer)->get('/dashboard/occupancy/export')->assertOk()->getContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil.example"",""Click"")"', $roster);

        $this->startMuster()->assertOk();
        $muster = $this->get('/dashboard/occupancy/muster/export?session_id='.MusterSession::value('id'))->getContent();
        $this->assertStringNotContainsString(',"=HYPERLINK', $muster);

        foreach (['=1+1', '+1', '-1', '@SUM(A1)'] as $formula) {
            $this->assertSame('"\''.$formula.'"', Csv::cell($formula));
        }
        $this->assertSame('"Plain name"', Csv::cell('Plain name'));
    }
}
