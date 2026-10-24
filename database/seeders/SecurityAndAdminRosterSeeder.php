<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\GatePassEngine;
use App\Services\PropertyOwnershipService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The security team and the estate's administrators.
 *
 * Ten Security officers, and eleven Admins who also own a home on the
 * estate. An account has one role, so "admin and homeowner" is an Admin
 * account with a recorded property: HOA dues are charged per property, so
 * each of them is billed for their home like any other owner.
 *
 * Safe to re-run: accounts are matched by email and properties by lot.
 * Passwords are the seed password, as for UserSeeder's accounts.
 */
class SecurityAndAdminRosterSeeder extends Seeder
{
    /** @var list<array{name: string, email: string, title: string, post: string}> */
    private const SECURITY = [
        ['name' => 'Andre Campbell', 'email' => 'andre.campbell@apexguard.com', 'title' => 'Shift Supervisor', 'post' => 'Gatehouse 1'],
        ['name' => 'Kemar Williams', 'email' => 'kemar.williams@apexguard.com', 'title' => 'Gate Officer', 'post' => 'Gatehouse 1'],
        ['name' => 'Tanisha Brown', 'email' => 'tanisha.brown@apexguard.com', 'title' => 'Gate Officer', 'post' => 'Gatehouse 1'],
        ['name' => 'Dwayne Clarke', 'email' => 'dwayne.clarke@apexguard.com', 'title' => 'Gate Officer', 'post' => 'Gatehouse 2'],
        ['name' => 'Shanice Reid', 'email' => 'shanice.reid@apexguard.com', 'title' => 'Gate Officer', 'post' => 'Gatehouse 2'],
        ['name' => 'Omar Thompson', 'email' => 'omar.thompson@apexguard.com', 'title' => 'Patrol Officer', 'post' => 'Mobile Patrol'],
        ['name' => 'Kadian Morgan', 'email' => 'kadian.morgan@apexguard.com', 'title' => 'Patrol Officer', 'post' => 'Mobile Patrol'],
        ['name' => 'Ricardo Henry', 'email' => 'ricardo.henry@apexguard.com', 'title' => 'Night Patrol Officer', 'post' => 'Mobile Patrol'],
        ['name' => 'Latoya Grant', 'email' => 'latoya.grant@apexguard.com', 'title' => 'CCTV Control Room Operator', 'post' => 'Control Room'],
        ['name' => 'Garfield Stewart', 'email' => 'garfield.stewart@apexguard.com', 'title' => 'Service Gate Officer', 'post' => 'Service Gate North'],
    ];

    /** @var list<array{name: string, email: string, title: string, lot: string, street: string}> */
    private const ADMIN_HOMEOWNERS = [
        ['name' => 'Patricia Lindo', 'email' => 'patricia.lindo@communityhub.org', 'title' => 'HOA Board President', 'lot' => 'Lot 101', 'street' => 'Royal Palm Drive'],
        ['name' => 'Michael Bennett', 'email' => 'michael.bennett@communityhub.org', 'title' => 'HOA Treasurer', 'lot' => 'Lot 102', 'street' => 'Royal Palm Drive'],
        ['name' => 'Sandra Whyte', 'email' => 'sandra.whyte@communityhub.org', 'title' => 'HOA Secretary', 'lot' => 'Lot 103', 'street' => 'Hibiscus Crescent'],
        ['name' => 'Courtney Francis', 'email' => 'courtney.francis@communityhub.org', 'title' => 'Security Liaison', 'lot' => 'Lot 104', 'street' => 'Hibiscus Crescent'],
        ['name' => 'Natalie Palmer', 'email' => 'natalie.palmer@communityhub.org', 'title' => 'Facilities Committee Chair', 'lot' => 'Lot 105', 'street' => 'Coral Way'],
        ['name' => 'Desmond Allen', 'email' => 'desmond.allen@communityhub.org', 'title' => 'Finance Committee Member', 'lot' => 'Lot 106', 'street' => 'Coral Way'],
        ['name' => 'Simone Edwards', 'email' => 'simone.edwards@communityhub.org', 'title' => 'Community Events Coordinator', 'lot' => 'Lot 107', 'street' => 'Mahogany Lane'],
        ['name' => 'Wayne Robinson', 'email' => 'wayne.robinson@communityhub.org', 'title' => 'Architectural Review Chair', 'lot' => 'Lot 108', 'street' => 'Mahogany Lane'],
        ['name' => 'Karen McKenzie', 'email' => 'karen.mckenzie@communityhub.org', 'title' => 'Resident Relations Officer', 'lot' => 'Lot 109', 'street' => 'Blue Mahoe Avenue'],
        ['name' => 'Rohan Gordon', 'email' => 'rohan.gordon@communityhub.org', 'title' => 'Compliance Officer', 'lot' => 'Lot 110', 'street' => 'Blue Mahoe Avenue'],
        ['name' => 'Jacqueline Hall', 'email' => 'jacqueline.hall@communityhub.org', 'title' => 'Landscaping Committee Chair', 'lot' => 'Lot 111', 'street' => 'Lignum Vitae Close'],
    ];

    public function run(GatePassEngine $engine, PropertyOwnershipService $ownership): void
    {
        $password = config('auth.seed_password');

        foreach (self::SECURITY as $i => $officer) {
            $user = $this->account($officer['email'], [
                'name' => $officer['name'],
                'role' => UserRole::Security,
                'title' => $officer['title'],
                'phone' => sprintf('(876) 555-02%02d', $i + 1),
                'lot' => $officer['post'],
                'street' => 'Main Perimeter Entrance',
            ], $password);

            $engine->issuePassFor($user);
        }

        foreach (self::ADMIN_HOMEOWNERS as $i => $admin) {
            $user = $this->account($admin['email'], [
                'name' => $admin['name'],
                'role' => UserRole::Admin,
                'title' => $admin['title'],
                'phone' => sprintf('(876) 555-03%02d', $i + 1),
                'lot' => $admin['lot'],
                'street' => $admin['street'],
            ], $password);

            // Their home, on record, so it is billed HOA dues.
            $ownership->assign($user, $admin['lot'], $admin['street']);
            $engine->issuePassFor($user);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function account(string $email, array $attributes, ?string $password): User
    {
        $user = User::firstOrNew(['email' => $email]);

        $user->fill([
            ...$attributes,
            'display_name' => $attributes['name'],
            'status' => 'Active',
        ]);

        // A re-run never resets a password someone has since changed.
        if (! $user->exists) {
            $user->uid = (string) Str::uuid();
            $user->password = $password;
        }

        $user->save();

        return $user;
    }
}
