<?php

namespace Database\Seeders;

use App\Models\AccessLogEntry;
use App\Models\BlocklistEntry;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Blocklist from mockBlocklist in src/lib/data.ts, plus a small access log so
 * the log and analytics screens are not empty on first run.
 */
class SecuritySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'Admin')->first();
        $security = User::where('role', 'Security')->first();

        $entries = [
            [
                'name' => 'Known Troublemaker',
                'photo_url' => 'https://picsum.photos/100?q=trouble',
                'reason' => 'Repeatedly causing disturbances at community events. Multiple complaints filed.',
                'date_added' => '2024-03-15 00:00:00',
                'expiry_date' => null,
                'added_by' => 'Admin',
                'added_by_id' => $admin?->id,
            ],
            [
                'name' => 'Suspicious Vehicle Owner',
                'photo_url' => null,
                'reason' => 'Vehicle seen loitering at odd hours. License plate reported.',
                'date_added' => '2024-07-10 00:00:00',
                'expiry_date' => '2025-01-10 00:00:00',
                'added_by' => 'Guard McSecurity',
                'added_by_id' => $security?->id,
            ],
        ];

        foreach ($entries as $entry) {
            BlocklistEntry::updateOrCreate(['name' => $entry['name']], $entry);
        }

        // A handful of representative gate events across the last few days.
        $residents = User::whereIn('role', ['Homeowner', 'Temporary Homeowner'])->get();

        if ($residents->isEmpty()) {
            return;
        }

        $methods = ['Digital Pass', 'Staff Pass', 'Visitor Pass', 'Manual Entry'];
        $gates = ['Main Gate', 'Service Gate', 'Pedestrian Gate'];

        for ($i = 0; $i < 40; $i++) {
            $resident = $residents->random();
            $denied = $i % 9 === 0;

            AccessLogEntry::create([
                'user_id' => $resident->id,
                'user_name' => $resident->display_name,
                'user_role' => $resident->role->value,
                'method' => $methods[$i % count($methods)],
                'gate' => $gates[$i % count($gates)],
                'pass_id' => $resident->gatePasses()->first()?->pass_id,
                'result' => $denied ? 'DENY' : 'ALLOW',
                'deny_reason' => $denied
                    ? 'TOKEN_EXPIRED: Dynamic credential expired before scan completed.'
                    : null,
                'scanned_by' => $security?->id,
                'occurred_at' => now()->subHours($i * 3)->subMinutes($i * 7),
            ]);
        }
    }
}
