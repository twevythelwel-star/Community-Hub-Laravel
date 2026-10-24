<?php

namespace Database\Seeders;

use App\Enums\PassStatus;
use App\Enums\VisitorStatus;
use App\Models\Renter;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Illuminate\Database\Seeder;

/**
 * Visitors from getInitialVisitors() in src/lib/visitors-data.ts, plus staff and
 * lease records. The relative dates in the original (now, now-1d, now-13h) are
 * preserved so the visitors screen shows the same mix of statuses.
 */
class DirectorySeeder extends Seeder
{
    public function run(): void
    {
        $olivia = User::where('email', 'olivia.davis@residence.net')->first();
        $john = User::where('email', 'john.smith@residence.net')->first();
        $jane = User::where('email', 'jane.doe@residence.net')->first();
        $marcus = User::where('email', 'marcus.vance@residence.net')->first();
        $sophia = User::where('email', 'sophia.taylor@residence.net')->first();

        $now = now();

        $visitors = [
            [
                'name' => 'Liam Johnson',
                'type' => 'One-time',
                'status' => 'Expected',
                'expected_at' => $now,
                'date_range' => $now->format('Y-m-d'),
                'homeowner_id' => $olivia?->id,
                'homeowner_name' => 'Olivia Davis (Lot 42)',
                'id_image_url' => 'https://picsum.photos/300/200?q=id1',
                'is_blocked' => false,
            ],
            [
                'name' => 'Noah Williams',
                'type' => 'Recurring',
                'status' => 'Checked In',
                'expected_at' => $now->copy()->subDay(),
                'date_range' => $now->copy()->subDay()->format('Y-m-d').' - '.$now->copy()->addDays(60)->format('Y-m-d'),
                'homeowner_id' => $john?->id,
                'homeowner_name' => 'John Smith (Lot 12)',
                'id_image_url' => 'https://picsum.photos/300/200?q=id2',
                'is_blocked' => false,
                'checked_in_at' => $now->copy()->subDay(),
            ],
            [
                'name' => 'Expired Visitor',
                'type' => 'One-time',
                'status' => 'Expected',
                'expected_at' => $now->copy()->subHours(13),
                'date_range' => $now->copy()->subHours(13)->format('Y-m-d'),
                'homeowner_id' => $john?->id,
                'homeowner_name' => 'John Smith (Lot 12)',
                'is_blocked' => false,
            ],
            [
                'name' => 'Known Troublemaker',
                'type' => 'One-time',
                'status' => 'Expected',
                'expected_at' => $now,
                'date_range' => $now->format('Y-m-d'),
                'homeowner_id' => $jane?->id,
                'homeowner_name' => 'Jane Doe (Lot 03)',
                'id_image_url' => 'https://picsum.photos/300/200?q=trouble',
                // Flagged because the name is on the seeded blocklist.
                'is_blocked' => true,
            ],
            [
                'name' => 'Maria Williams',
                'type' => 'Homeowner Staff',
                'status' => 'Checked In',
                'expected_at' => $now->copy()->setTime(16, 32),
                'checked_in_at' => $now->copy()->setTime(16, 32),
                'date_range' => $now->format('Y-m-d'),
                'homeowner_id' => $olivia?->id,
                'homeowner_name' => 'Robert Sterling (#104)',
                'is_blocked' => false,
            ],
            [
                'name' => 'James Brown',
                'type' => 'Visitor',
                'status' => 'Expected',
                'expected_at' => $now->copy()->setTime(18, 00),
                'date_range' => $now->format('Y-m-d'),
                'homeowner_id' => $john?->id,
                'homeowner_name' => 'Eleanor Vance (#208)',
                'is_blocked' => false,
            ],
            [
                'name' => 'John Smith',
                'type' => 'Contractor',
                'status' => 'Checked Out',
                'expected_at' => $now->copy()->setTime(14, 00),
                'checked_in_at' => $now->copy()->setTime(14, 15),
                'checked_out_at' => $now->copy()->setTime(15, 41),
                'date_range' => $now->format('Y-m-d'),
                'homeowner_id' => $marcus?->id,
                'homeowner_name' => 'David Miller (#311)',
                'is_blocked' => false,
            ],
        ];

        $engine = app(GatePassEngine::class);

        foreach ($visitors as $visitor) {
            $record = Visitor::updateOrCreate(
                ['name' => $visitor['name'], 'homeowner_name' => $visitor['homeowner_name']],
                $visitor,
            );

            // Each seeded visitor gets a gate pass in the state their visit is in.
            if ($record->homeowner && ! $record->gatePass) {
                $pass = $engine->issueGuestPass($record, $record->homeowner);

                $steps = match ($record->status) {
                    VisitorStatus::CheckedIn => [PassStatus::Active, PassStatus::CheckedIn],
                    VisitorStatus::CheckedOut => [PassStatus::Active, PassStatus::CheckedIn, PassStatus::CheckedOut],
                    default => [],
                };

                foreach ($steps as $step) {
                    $pass->transitionTo($step, reason: 'Seeded');
                }
            }
        }

        $staff = [
            [
                'name' => 'Maria Garcia',
                'job' => 'Housekeeper',
                'id_type' => 'National ID',
                'id_number' => 'JM-8841-2290',
                'id_expiry' => now()->addYears(2)->toDateString(),
                'property_label' => 'Lot 42, Royal Palm Drive',
                'added_by' => $marcus?->id,
                'status' => 'Active',
            ],
            [
                'name' => 'Devon Clarke',
                'job' => 'Gardener',
                'id_type' => 'Passport',
                'id_number' => 'A4471902',
                'id_expiry' => now()->addMonths(8)->toDateString(),
                'property_label' => 'Lot 42, Royal Palm Drive',
                'added_by' => $marcus?->id,
                'status' => 'Active',
            ],
            [
                'name' => 'Andre Bailey',
                'job' => 'Grounds Maintenance',
                'id_type' => 'National ID',
                'id_number' => 'JM-2210-7741',
                // Deliberately past, so the Expired ID state is visible.
                'id_expiry' => now()->subMonths(2)->toDateString(),
                'property_label' => 'Community Grounds',
                'added_by' => $marcus?->id,
                'status' => 'Active',
            ],
        ];

        foreach ($staff as $member) {
            Staff::updateOrCreate(
                ['name' => $member['name'], 'property_label' => $member['property_label']],
                $member,
            );
        }

        if ($sophia) {
            Renter::updateOrCreate(
                ['user_id' => $sophia->id],
                [
                    'homeowner_id' => $marcus?->id,
                    'name' => $sophia->display_name,
                    'stay_type' => 'Long-term (Renter)',
                    'contact' => $sophia->phone ?? $sophia->email,
                    'status' => 'Active',
                    'lease_start' => now()->subMonths(4)->toDateString(),
                    'lease_end' => now()->addMonths(8)->toDateString(),
                    'lot' => $sophia->lot ?: 'Lot 14',
                    'street' => $sophia->street ?: 'Hibiscus Way',
                    'notes' => 'Authorized long-term tenant under Marcus Vance property.',
                ],
            );
        }

        if ($marcus) {
            Renter::updateOrCreate(
                ['name' => 'Elena Rostova', 'homeowner_id' => $marcus->id],
                [
                    'stay_type' => 'Short-term (Airbnb)',
                    'contact' => '+1 (555) 392-1084',
                    'status' => 'Active',
                    'lease_start' => now()->subDays(2)->toDateString(),
                    'lease_end' => now()->addDays(5)->toDateString(),
                    'lot' => $marcus->lot ?: 'Lot 14',
                    'street' => $marcus->street ?: 'Hibiscus Way',
                    'notes' => 'Weekend villa booking via Airbnb. 4 guests.',
                ],
            );
        }
    }
}
