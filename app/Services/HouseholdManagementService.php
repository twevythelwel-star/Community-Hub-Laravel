<?php

namespace App\Services;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Models\GatePass;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Carbon\Carbon;

class HouseholdManagementService
{
    /**
     * All recognized granular household permissions.
     */
    public const AVAILABLE_PERMISSIONS = [
        'manage_household' => [
            'label' => 'Manage Household',
            'description' => 'Can invite, edit, and revoke household members',
        ],
        'manage_guests' => [
            'label' => 'Guest Management',
            'description' => 'Can pre-register visitors and create digital guest invitations',
        ],
        'view_billing' => [
            'label' => 'Billing & Assessments',
            'description' => 'Can view community HOA dues, balances, and payment receipts',
        ],
        'receive_emergency_alerts' => [
            'label' => 'Emergency SOS Alerts',
            'description' => 'Subscribed to community broadcast alerts (Push, SMS, WhatsApp, Email)',
        ],
        'gate_access_24_7' => [
            'label' => '24/7 Gate Clearance',
            'description' => 'Unrestricted round-the-clock entry through automated estate gates',
        ],
        'gate_access_scheduled' => [
            'label' => 'Scheduled Gate Clearance',
            'description' => 'Restricted entry matching designated recurring shift hours (e.g. Mon–Fri)',
        ],
        'request_maintenance' => [
            'label' => 'Maintenance Requests',
            'description' => 'Can submit and track property work orders and repairs',
        ],
    ];

    /**
     * Find or initialize a household for the given homeowner user.
     */
    public function getOrCreateHousehold(User $homeowner): Household
    {
        $propertyNumber = $this->unitOf($homeowner);

        $household = Household::firstOrCreate(
            ['primary_homeowner_id' => $homeowner->id],
            [
                'property_number' => $propertyNumber,
                'name' => ($homeowner->name ? $homeowner->name.' Household' : 'Resident Household'),
                'address' => $homeowner->propertyLabel(),
                'notes' => 'Primary residential household account.',
            ]
        );

        // Ensure primary homeowner is registered as the head of household
        if (! $household->members()->where('role_in_household', 'homeowner')->exists()) {
            $this->addMember($household, [
                'user_id' => $homeowner->id,
                'name' => $homeowner->name,
                'email' => $homeowner->email,
                'phone' => $homeowner->phone,
                'role_in_household' => 'homeowner',
                'relationship_label' => 'Homeowner (Primary Account Holder)',
            ]);
        }

        return $household->load(['members.gatePass', 'primaryHomeowner']);
    }

    /**
     * Add a member to the household and provision their credential and permissions.
     */
    public function addMember(Household $household, array $data): HouseholdMember
    {
        $role = $data['role_in_household'] ?? 'other';
        $name = trim($data['name'] ?? 'Household Member');
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        $userId = $data['user_id'] ?? null;
        $validUntil = ! empty($data['valid_until']) ? Carbon::parse($data['valid_until']) : null;

        // 1. Resolve Appropriate Pass Category
        $passCategory = match ($role) {
            'homeowner', 'spouse' => PassCategory::Homeowner,
            'child' => PassCategory::Renter, // Resident Dependent Squircle
            'long_term_occupant' => PassCategory::LongTermOccupant,
            'caregiver' => PassCategory::Delegate, // Or HomeownerStaff
            // Lives here without owning it. "Other" used to get a full Homeowner pass.
            default => PassCategory::LongTermOccupant,
        };

        // Owners' passes run on; everyone else's ends, at their own date or the
        // delegate maximum. These passes were created with no end at all.
        $maxUntil = now()->addDays((int) config('delegation.max_pass_days', 365));
        $passUntil = in_array($role, ['homeowner', 'spouse'], true)
            ? $validUntil
            : ($validUntil ? $validUntil->min($maxUntil) : $maxUntil);

        // 2. Default Permissions Matrix
        $permissions = $data['permissions'] ?? $this->getDefaultPermissionsForRole($role);

        // 3. Operational Schedule (e.g. Caregiver Mon-Fri 8am-5pm)
        $schedule = $data['access_schedule'] ?? $this->getDefaultScheduleForRole($role);

        // 4. Provision Dedicated Digital Gate Pass Credential
        $passPrefix = match ($role) {
            'homeowner' => 'GP-OWN',
            'spouse' => 'GP-SPS',
            'child' => 'GP-DEP',
            'long_term_occupant' => 'GP-LTO',
            'caregiver' => 'GP-CRG',
            default => 'GP-HSD',
        };

        $passId = sprintf('%s-%04d-%04d', $passPrefix, $household->id, rand(100, 9999));

        $gatePass = GatePass::create([
            'pass_id' => $passId,
            'user_id' => $userId ?? $household->primary_homeowner_id,
            'category' => $passCategory,
            'holder_name' => $name,
            'property' => $household->property_number,
            'designated_gate' => GateId::Any,
            'status' => PassStatus::Active,
            'valid_from' => now(),
            'valid_until' => $passUntil,
            'created_at' => now(),
            'updated_at' => now(),
            'metadata' => [
                'household_id' => $household->id,
                'role_in_household' => $role,
                'relationship' => $data['relationship_label'] ?? ucfirst($role),
                'permissions' => $permissions,
                'access_schedule' => $schedule,
                'contact' => $phone,
                'email' => $email,
            ],
        ]);

        // 5. Store Member Record
        $member = HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $userId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'role_in_household' => $role,
            'relationship_label' => $data['relationship_label'] ?? ucfirst(str_replace('_', ' ', $role)),
            'pass_category' => $passCategory->value,
            'gate_pass_id' => $gatePass->id,
            'permissions' => $permissions,
            'access_schedule' => $schedule,
            'status' => 'active',
            'valid_until' => $validUntil,
        ]);

        return $member->load('gatePass');
    }

    /**
     * Update an existing household member's profile, credential, or permissions.
     */
    public function updateMember(HouseholdMember $member, array $data): HouseholdMember
    {
        $updateFields = [];

        if (isset($data['name'])) {
            $updateFields['name'] = trim($data['name']);
        }
        if (isset($data['email'])) {
            $updateFields['email'] = $data['email'];
        }
        if (isset($data['phone'])) {
            $updateFields['phone'] = $data['phone'];
        }
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $updateFields['permissions'] = $data['permissions'];
        }
        if (isset($data['access_schedule']) && is_array($data['access_schedule'])) {
            $updateFields['access_schedule'] = $data['access_schedule'];
        }
        if (isset($data['valid_until'])) {
            $updateFields['valid_until'] = ! empty($data['valid_until']) ? Carbon::parse($data['valid_until']) : null;
            if ($member->gatePass && $updateFields['valid_until'] && ! in_array($member->role_in_household, ['homeowner', 'spouse'], true)) {
                $member->gatePass->update(['valid_until' => $updateFields['valid_until']->min(now()->addDays((int) config('delegation.max_pass_days', 365)))]);
            }
        }
        if (isset($data['status'])) {
            $updateFields['status'] = $data['status'];
            // Suspending suspends the pass; reactivating lifts only that. It
            // used to revoke and then revive, so a pass security had revoked
            // could be switched back on by the household.
            if ($member->gatePass) {
                if ($data['status'] === 'suspended' && $member->gatePass->status === PassStatus::Active) {
                    $member->gatePass->update(['status' => PassStatus::Suspended]);
                } elseif ($data['status'] === 'active' && $member->gatePass->status === PassStatus::Suspended) {
                    $member->gatePass->update(['status' => PassStatus::Active]);
                }
            }
        }

        $member->update($updateFields);

        // Sync metadata on gate pass
        if ($member->gatePass) {
            $meta = $member->gatePass->metadata ?? [];
            if (isset($updateFields['permissions'])) {
                $meta['permissions'] = $updateFields['permissions'];
            }
            if (isset($updateFields['access_schedule'])) {
                $meta['access_schedule'] = $updateFields['access_schedule'];
            }
            $member->gatePass->update([
                'holder_name' => $member->name,
                'metadata' => $meta,
            ]);
        }

        return $member->fresh(['gatePass']);
    }

    /**
     * Remove a household member and revoke their digital gate credential.
     */
    public function removeMember(HouseholdMember $member): void
    {
        if ($member->gatePass) {
            $member->gatePass->update([
                'status' => PassStatus::Revoked,
            ]);
        }

        $member->delete();
    }

    /**
     * Seed the exact prompt example:
     * Household
     * John Smith — Homeowner
     * Mary Smith — Spouse
     * Alex Smith — Child
     * James Smith — Long-term occupant
     * Maria Smith — Caregiver
     */
    public function seedExampleSmithHousehold(User $homeowner): Household
    {
        $household = Household::firstOrCreate(
            ['primary_homeowner_id' => $homeowner->id],
            [
                'property_number' => $this->unitOf($homeowner),
                'name' => 'Smith Household',
                'address' => '14 Royal Palm Way, Oceanview Estates',
                'notes' => 'Primary family residence.',
            ]
        );

        // Clear existing members if any to demonstrate the clean scenario
        $household->members()->delete();

        // 1. John Smith — Homeowner
        $this->addMember($household, [
            'name' => 'John Smith',
            'email' => $homeowner->email,
            'phone' => $homeowner->phone ?? '+18765550101',
            'role_in_household' => 'homeowner',
            'relationship_label' => 'Homeowner (Primary Account Holder)',
            'user_id' => $homeowner->id,
        ]);

        // 2. Mary Smith — Spouse
        $this->addMember($household, [
            'name' => 'Mary Smith',
            'email' => 'mary.smith@example.com',
            'phone' => '+18765550102',
            'role_in_household' => 'spouse',
            'relationship_label' => 'Spouse (Co-Owner)',
        ]);

        // 3. Alex Smith — Child
        $this->addMember($household, [
            'name' => 'Alex Smith',
            'phone' => '+18765550103',
            'role_in_household' => 'child',
            'relationship_label' => 'Child (Resident Dependent)',
            'access_schedule' => [
                'curfew_enabled' => true,
                'curfew_hours' => '06:00 AM – 9:00 PM',
            ],
        ]);

        // 4. James Smith — Long-term occupant
        $this->addMember($household, [
            'name' => 'James Smith',
            'email' => 'james.smith@example.com',
            'phone' => '+18765550104',
            'role_in_household' => 'long_term_occupant',
            'relationship_label' => 'Long-Term Occupant (Extended Family)',
            'valid_until' => now()->endOfYear(),
        ]);

        // 5. Maria Smith — Caregiver
        $this->addMember($household, [
            'name' => 'Maria Smith',
            'phone' => '+18765550105',
            'role_in_household' => 'caregiver',
            'relationship_label' => 'Caregiver (Health & Household Support)',
            'access_schedule' => [
                'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'hours' => '8:00 AM – 5:00 PM',
                'valid_until' => now()->endOfYear()->format('Y-m-d'),
            ],
            'valid_until' => now()->endOfYear(),
        ]);

        return $household->fresh(['members.gatePass', 'primaryHomeowner']);
    }

    /**
     * Default permission assignments based on household role.
     */
    public function getDefaultPermissionsForRole(string $role): array
    {
        return match ($role) {
            'homeowner' => [
                'manage_household',
                'manage_guests',
                'view_billing',
                'receive_emergency_alerts',
                'gate_access_24_7',
                'request_maintenance',
            ],
            'spouse' => [
                'manage_guests',
                'view_billing',
                'receive_emergency_alerts',
                'gate_access_24_7',
                'request_maintenance',
            ],
            'child' => [
                'gate_access_24_7',
                'receive_emergency_alerts',
            ],
            'long_term_occupant' => [
                'gate_access_24_7',
                'receive_emergency_alerts',
                'request_maintenance',
            ],
            'caregiver' => [
                'gate_access_scheduled',
                'receive_emergency_alerts',
                'request_maintenance',
            ],
            default => [
                'gate_access_24_7',
                'receive_emergency_alerts',
            ],
        };
    }

    /**
     * Default operational schedule for roles.
     */
    public function getDefaultScheduleForRole(string $role): ?array
    {
        return match ($role) {
            'child' => [
                'curfew_enabled' => true,
                'curfew_hours' => '06:00 AM – 9:00 PM',
            ],
            'caregiver' => [
                'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'hours' => '8:00 AM – 5:00 PM',
                'valid_until' => now()->endOfYear()->format('Y-m-d'),
            ],
            default => null,
        };
    }

    /**
     * "14" is "Unit 14"; a lot already named ("Lot 42", "Unit 15B") stays as it is.
     * Prefixing every lot made "Lot 42" into "Unit Lot 42", which the occupancy
     * count filed under a unit called "Unit Lot".
     */
    private function unitOf(User $homeowner): string
    {
        $lot = trim((string) $homeowner->lot);

        if ($lot === '') {
            return $homeowner->propertyLabel();
        }

        return preg_match('/^\d/', $lot) ? 'Unit '.$lot : $lot;
    }
}
