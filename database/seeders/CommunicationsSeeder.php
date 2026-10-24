<?php

namespace Database\Seeders;

use App\Models\ChangelogEntry;
use App\Models\CommunityEvent;
use App\Models\CommunityUpdate;
use App\Models\Feedback;
use App\Models\Guideline;
use App\Models\Notification;
use App\Models\User;
use App\Models\Warning;
use Illuminate\Database\Seeder;

class CommunicationsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'Admin')->first();
        $security = User::where('role', 'Security')->first();
        $resident = User::where('role', 'Homeowner')->first();

        $notifications = [
            [
                'title' => 'Scheduled Water Maintenance',
                'content' => 'The water supply will be interrupted on Saturday between 09:00 and 14:00 while the reservoir pumps are serviced. Please store water in advance.',
            ],
            [
                'title' => 'Annual General Meeting',
                'content' => 'The AGM will be held at the clubhouse at 18:00 on the last Friday of this month. Homeowners in good standing are eligible to vote.',
            ],
            [
                'title' => 'Vehicle Registration Deadline',
                'content' => 'All resident vehicles must be registered with the gatehouse by Friday. Unregistered vehicles will require manual visitor clearance at each entry.',
            ],
        ];

        foreach ($notifications as $index => $notification) {
            Notification::updateOrCreate(
                ['title' => $notification['title']],
                [
                    ...$notification,
                    'author_id' => $admin?->id,
                    'author_name' => $admin?->display_name ?? 'Community Administration',
                    'target_roles' => null,   // everyone
                    'published_at' => now()->subDays($index * 2),
                ],
            );
        }

        $warnings = [
            [
                'title' => 'Unidentified Vehicle on Royal Palm Drive',
                'description' => 'A dark sedan without plates was seen circling the block after 23:00. Residents should report any sighting to the gatehouse immediately.',
            ],
            [
                'title' => 'Perimeter Fence Damage — North Section',
                'description' => 'A section of the north perimeter fence is damaged and under repair. Please avoid the area and do not use it as a shortcut.',
            ],
        ];

        foreach ($warnings as $index => $warning) {
            Warning::updateOrCreate(
                ['title' => $warning['title']],
                [
                    ...$warning,
                    'author_id' => $security?->id,
                    'author_name' => $security?->display_name ?? 'Security Dispatch',
                    'issued_at' => now()->subDays($index)->subHours(5),
                ],
            );
        }

        $events = [
            [
                'title' => 'Community Clean-Up Day',
                'description' => 'Join your neighbours for a morning of grounds tidying, followed by refreshments at the clubhouse.',
                'start_date' => now()->addDays(9)->setTime(8, 0),
                'end_date' => now()->addDays(9)->setTime(12, 0),
            ],
            [
                'title' => 'Family Movie Night',
                'description' => 'Outdoor screening at the recreation park. Bring a blanket; popcorn provided.',
                'start_date' => now()->addDays(16)->setTime(18, 30),
                'end_date' => now()->addDays(16)->setTime(21, 0),
            ],
            [
                'title' => 'Annual General Meeting',
                'description' => 'Review of the year, budget approval and committee elections.',
                'start_date' => now()->addDays(23)->setTime(18, 0),
                'end_date' => now()->addDays(23)->setTime(20, 30),
            ],
        ];

        foreach ($events as $event) {
            CommunityEvent::updateOrCreate(
                ['title' => $event['title'], 'start_date' => $event['start_date']],
                [...$event, 'created_by' => $admin?->id],
            );
        }

        $updates = [
            [
                'title' => 'Gatehouse Scanner Upgrade Complete',
                'date' => now()->subDays(4)->toDateString(),
                'summary' => 'Both gates now run the upgraded digital pass scanner with faster validation and clearer denial reasons.',
            ],
            [
                'title' => 'New Playground Equipment Installed',
                'date' => now()->subDays(18)->toDateString(),
                'summary' => 'The recreation park playground has been refitted with new climbing equipment and soft-fall surfacing.',
            ],
        ];

        foreach ($updates as $update) {
            CommunityUpdate::updateOrCreate(
                ['title' => $update['title']],
                [...$update, 'created_by' => $admin?->id],
            );
        }

        $guidelines = [
            ['category' => 'Access & Security', 'title' => 'Visitor Pre-Clearance', 'description' => 'Register expected visitors before arrival. Unregistered guests require the resident to be reached by phone before entry.', 'sort_order' => 1],
            ['category' => 'Access & Security', 'title' => 'Gate Pass Privacy',     'description' => 'Do not share screenshots of your digital pass. Passes rotate and a shared image will be rejected as a replay.', 'sort_order' => 2],
            ['category' => 'Access & Security', 'title' => 'Household Staff',       'description' => 'Household staff must be registered with a valid photo ID. Expired identification suspends gate access.', 'sort_order' => 3],
            ['category' => 'Community Living',  'title' => 'Quiet Hours',           'description' => 'Quiet hours run from 22:00 to 07:00 daily. Amplified music outdoors requires prior approval.', 'sort_order' => 1],
            ['category' => 'Community Living',  'title' => 'Pet Control',           'description' => 'Pets must be leashed in all common areas and waste removed immediately by the owner.', 'sort_order' => 2],
            ['category' => 'Property',          'title' => 'Exterior Alterations',  'description' => 'Any change to the exterior appearance of a property requires written approval from the committee.', 'sort_order' => 1],
            ['category' => 'Property',          'title' => 'Vehicle Parking',       'description' => 'Park within your driveway. Street parking obstructing emergency access will be towed.', 'sort_order' => 2],
            ['category' => 'Fees & Billing',    'title' => 'Maintenance Fees',      'description' => 'Monthly maintenance fees are due on the first of each month. Accounts over 60 days in arrears lose amenity access.', 'sort_order' => 1],
        ];

        foreach ($guidelines as $guideline) {
            Guideline::updateOrCreate(
                ['category' => $guideline['category'], 'title' => $guideline['title']],
                $guideline,
            );
        }

        $changelog = [
            ['version' => '2.4.0', 'title' => 'Server-side gate pass validation', 'released_on' => now()->subDays(2)->toDateString(), 'body' => 'Pass signing and validation moved to the server. Replay detection is now persistent and shared across all gates.'],
            ['version' => '2.3.0', 'title' => 'White-label branding',             'released_on' => now()->subDays(30)->toDateString(), 'body' => 'Estate administrators can set the app name, logo and colour palette for all residents.'],
            ['version' => '2.2.0', 'title' => 'Dark mode',                        'released_on' => now()->subDays(52)->toDateString(), 'body' => 'Full dark theme across the dashboard, with a per-user preference.'],
            ['version' => '2.1.0', 'title' => 'Community boundary editor',        'released_on' => now()->subDays(75)->toDateString(), 'body' => 'Administrators can define and publish the estate boundary with up to eight survey points.'],
        ];

        foreach ($changelog as $entry) {
            ChangelogEntry::updateOrCreate(['version' => $entry['version']], $entry);
        }

        if ($resident) {
            Feedback::updateOrCreate(
                ['subject' => 'Street light out on Hibiscus Crescent'],
                [
                    'user_id' => $resident->id,
                    'submitted_by' => $resident->display_name,
                    'user_role' => $resident->role->value,
                    'type' => 'Issue',
                    'body' => 'The second street light from the corner has been out for about a week. It is quite dark at night.',
                    'status' => 'In Progress',
                    'submitted_at' => now()->subDays(6),
                ],
            );

            Feedback::updateOrCreate(
                ['subject' => 'Suggestion: add a dog waste station at the park'],
                [
                    'user_id' => $resident->id,
                    'submitted_by' => $resident->display_name,
                    'user_role' => $resident->role->value,
                    'type' => 'Suggestion',
                    'body' => 'A bag dispenser and bin near the park entrance would help keep the green space clean.',
                    'status' => 'New',
                    'submitted_at' => now()->subDays(2),
                ],
            );
        }
    }
}
