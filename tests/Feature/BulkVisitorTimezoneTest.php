<?php

namespace Tests\Feature;

use App\Actions\Visitors\RegisterBulkVisitorsAction;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BulkVisitorTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_guest_passes_midnight_boundary_in_utc_minus_5(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        // 12:00 AM (midnight) local time in Jamaica (UTC-5)
        $response = $this->actingAs($resident)->post('/dashboard/visitors/bulk', [
            'expected_at' => '2026-10-15 00:00:00',
            'expected_date' => '2026-10-15',
            'expected_time' => '00:00',
            'timezone' => 'America/Jamaica',
            'pass_category' => 'VISITOR',
            'visitors' => [
                ['name' => 'Midnight Guest', 'contact' => '876-555-0199'],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $visitor = Visitor::where('name', 'Midnight Guest')->firstOrFail();

        // At 00:00 in America/Jamaica (UTC-5), the UTC persistence boundary MUST be 05:00:00
        $this->assertSame(
            '2026-10-15 05:00:00',
            (string) $visitor->getRawOriginal('expected_at')
        );
        $this->assertDatabaseHas('visitors', [
            'name' => 'Midnight Guest',
            'expected_at' => '2026-10-15 05:00:00',
        ]);
    }

    public function test_bulk_guest_passes_crossing_midnight_boundary_into_next_utc_day(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        // 11:30 PM local time in Jamaica (UTC-5) on Oct 15
        $response = $this->actingAs($resident)->post('/dashboard/visitors/bulk', [
            'expected_at' => '2026-10-15 23:30:00',
            'expected_date' => '2026-10-15',
            'expected_time' => '23:30',
            'timezone' => 'America/Jamaica',
            'pass_category' => 'VISITOR',
            'visitors' => [
                ['name' => 'Late Night Guest', 'contact' => '876-555-0198'],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $visitor = Visitor::where('name', 'Late Night Guest')->firstOrFail();

        // 23:30 in UTC-5 is 04:30 the NEXT DAY in UTC
        $this->assertSame(
            '2026-10-16 04:30:00',
            (string) $visitor->getRawOriginal('expected_at')
        );
        $this->assertDatabaseHas('visitors', [
            'name' => 'Late Night Guest',
            'expected_at' => '2026-10-16 04:30:00',
        ]);
    }

    public function test_bulk_guest_passes_across_various_utc_minus_timezones(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $scenarios = [
            // America/New_York during EDT (UTC-4 in July)
            [
                'tz' => 'America/New_York',
                'date' => '2026-07-04',
                'time' => '00:00',
                'name' => 'New York Midnight',
                'expected_utc' => '2026-07-04 04:00:00',
            ],
            // America/Los_Angeles during PDT (UTC-7 in July)
            [
                'tz' => 'America/Los_Angeles',
                'date' => '2026-07-04',
                'time' => '00:00',
                'name' => 'LA Midnight',
                'expected_utc' => '2026-07-04 07:00:00',
            ],
            // Pacific/Honolulu (UTC-10 always)
            [
                'tz' => 'Pacific/Honolulu',
                'date' => '2026-10-15',
                'time' => '00:00',
                'name' => 'Honolulu Midnight',
                'expected_utc' => '2026-10-15 10:00:00',
            ],
        ];

        foreach ($scenarios as $s) {
            $response = $this->actingAs($resident)->post('/dashboard/visitors/bulk', [
                'expected_at' => "{$s['date']} {$s['time']}:00",
                'expected_date' => $s['date'],
                'expected_time' => $s['time'],
                'timezone' => $s['tz'],
                'pass_category' => 'VISITOR',
                'visitors' => [
                    ['name' => $s['name'], 'contact' => '555-0100'],
                ],
            ]);

            $response->assertSessionHasNoErrors();

            $visitor = Visitor::where('name', $s['name'])->firstOrFail();
            $this->assertSame(
                $s['expected_utc'],
                (string) $visitor->getRawOriginal('expected_at'),
                "Failed UTC conversion for timezone {$s['tz']}"
            );
            $this->assertDatabaseHas('visitors', [
                'name' => $s['name'],
                'expected_at' => $s['expected_utc'],
            ]);
        }
    }

    public function test_preserves_user_or_app_timezone_when_timezone_omitted(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        // Omit timezone: default config is America/Jamaica (UTC-5)
        config(['app.timezone' => 'America/Jamaica']);

        $response = $this->actingAs($resident)->post('/dashboard/visitors/bulk', [
            'expected_at' => '2026-10-20 00:00:00',
            'pass_category' => 'VISITOR',
            'visitors' => [
                ['name' => 'Default Tz Guest'],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $visitor = Visitor::where('name', 'Default Tz Guest')->firstOrFail();
        $this->assertSame(
            '2026-10-20 05:00:00',
            (string) $visitor->getRawOriginal('expected_at')
        );
        $this->assertDatabaseHas('visitors', [
            'name' => 'Default Tz Guest',
            'expected_at' => '2026-10-20 05:00:00',
        ]);
    }

    public function test_temporary_homeowner_stay_timeframe_enforced_in_utc(): void
    {
        $tempResident = User::factory()->role(UserRole::TemporaryHomeowner)->create();

        // Approved stay: Oct 10 to Oct 20, 2026
        Renter::create([
            'user_id' => $tempResident->id,
            'name' => $tempResident->name,
            'unit_lot' => 'Lot 42',
            'lease_start' => '2026-10-10',
            'lease_end' => '2026-10-20',
            'is_active' => true,
        ]);

        // Attempt to book visitor on Oct 25 (after lease expiry)
        $response = $this->actingAs($tempResident)->post('/dashboard/visitors/bulk', [
            'expected_at' => '2026-10-25 12:00:00',
            'timezone' => 'America/Jamaica',
            'visitors' => [
                ['name' => 'Expired Stay Guest'],
            ],
        ]);

        $response->assertSessionHasErrors('expected_at');
        $this->assertDatabaseMissing('visitors', ['name' => 'Expired Stay Guest']);
    }
}
