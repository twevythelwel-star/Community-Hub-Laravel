<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\GatePassEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Reproduces mockUserDatabase and mockRoleMapping from
 * src/context/auth-context.tsx, so the six demo accounts exist with the same
 * names, emails, titles and properties as before.
 *
 * The one deliberate difference: they now have real passwords. The original
 * accepted any password for a known username. The seeded password comes from
 * SEED_PASSWORD and must be changed before any real deployment.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // From config: the env helper returns null once config is cached,
        // which silently seeded every account with the literal default.
        $password = config('auth.seed_password');

        $accounts = [
            [
                'key' => 'user-sysadmin',
                'role' => UserRole::SystemAdmin,
                'name' => 'Alexander Wright',
                'display_name' => 'Alex Wright',
                'email' => 'alexander.wright@communityhub.org',
                'phone' => '(876) 555-0101',
                'title' => 'Senior Systems Administrator',
                'lot' => 'HQ-01',
                'street' => 'Executive Pavilion',
            ],
            [
                'key' => 'user-admin',
                'role' => UserRole::Admin,
                'name' => 'Elena Rostova',
                'display_name' => 'Elena Rostova',
                'email' => 'elena.rostova@communityhub.org',
                'phone' => '(876) 555-0102',
                'title' => 'Community Operations Director',
                'lot' => 'Admin Suite',
                'street' => 'Central Clubhouse Way',
            ],
            [
                'key' => 'user-homeowner',
                'role' => UserRole::Homeowner,
                'name' => 'Marcus Vance',
                'display_name' => 'Marcus Vance',
                'email' => 'marcus.vance@residence.net',
                'phone' => '(876) 555-0103',
                'title' => 'Verified Homeowner',
                'lot' => 'Lot 42',
                'street' => 'Royal Palm Drive',
            ],
            [
                'key' => 'user-renter',
                'role' => UserRole::TemporaryHomeowner,
                'name' => 'Sophia Taylor',
                'display_name' => 'Sophia Taylor',
                'email' => 'sophia.taylor@residence.net',
                'phone' => '(876) 555-0104',
                'title' => 'Resident Member',
                'lot' => 'Unit 15B',
                'street' => 'Hibiscus Crescent',
            ],
            [
                'key' => 'user-security',
                'role' => UserRole::Security,
                'name' => 'Apex Security Command',
                'display_name' => 'Security Dispatch',
                'email' => 'dispatch@apexguard.com',
                'phone' => '(876) 555-0105',
                'title' => 'Authorized Gate & Patrol Lead',
                'lot' => 'Gatehouse 1',
                'street' => 'Main Perimeter Entrance',
            ],
            [
                'key' => 'user-staff',
                'role' => UserRole::Staff,
                'name' => 'Maria Garcia',
                'display_name' => 'Maria Garcia',
                'email' => 'maria.garcia@communitystaff.org',
                'phone' => '(876) 555-0106',
                'title' => 'Lead Facilities Coordinator',
                'lot' => 'Lot 42',
                'street' => 'Royal Palm Drive',
            ],
        ];

        $engine = app(GatePassEngine::class);

        foreach ($accounts as $account) {
            $key = $account['key'];
            unset($account['key']);

            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    ...$account,
                    // Keeps the original uid shape so anything keyed on it still matches.
                    'uid' => 'mock-uid-'.$key,
                    'status' => 'Active',
                    'password' => $password,
                ],
            );

            $engine->issuePassFor($user);
        }

        // Extra residents referenced by the mock visitor records, so the
        // homeowner names on those visitors resolve to real accounts.
        $extraResidents = [
            ['name' => 'Olivia Davis', 'email' => 'olivia.davis@residence.net', 'lot' => 'Lot 42', 'street' => 'Royal Palm Drive'],
            ['name' => 'John Smith',   'email' => 'john.smith@residence.net',   'lot' => 'Lot 12', 'street' => 'Hibiscus Crescent'],
            ['name' => 'Jane Doe',     'email' => 'jane.doe@residence.net',     'lot' => 'Lot 03', 'street' => 'Coral Way'],
        ];

        foreach ($extraResidents as $resident) {
            $user = User::updateOrCreate(
                ['email' => $resident['email']],
                [
                    'uid' => (string) Str::uuid(),
                    'name' => $resident['name'],
                    'display_name' => $resident['name'],
                    'phone' => '(876) 555-'.random_int(1000, 9999),
                    'role' => UserRole::Homeowner,
                    'title' => 'Verified Homeowner',
                    'lot' => $resident['lot'],
                    'street' => $resident['street'],
                    'status' => 'Active',
                    'password' => $password,
                ],
            );

            $engine->issuePassFor($user);
        }
    }
}
