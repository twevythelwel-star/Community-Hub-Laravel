<?php

namespace App\Services\Occupancy;

use App\Enums\PassCategory;
use App\Enums\UserRole;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;

class OccupancyCategoryResolver
{
    /**
     * Map a pass, visitor, user, or delegated entity to the 7 primary community occupancy categories:
     * 1. residents
     * 2. long_term_guests (Long-Term Guests / Renters)
     * 3. short_term_guests (Short-Term Rental Guests / Airbnb)
     * 4. visitors
     * 5. staff
     * 6. contractors
     * 7. legacy_contacts
     */
    public function resolveCategory(GatePass|Visitor|User|DelegatedAccess|Renter|array $entity): string
    {
        if ($entity instanceof Visitor) {
            $type = strtolower($entity->type ?? '');
            if (str_contains($type, 'short') || str_contains($type, 'airbnb')) {
                return 'short_term_guests';
            }

            return 'visitors';
        }

        if ($entity instanceof Renter) {
            $stay = strtolower($entity->stay_type ?? '');
            $notes = strtolower($entity->notes ?? '');
            if (str_contains($stay, 'airbnb') || str_contains($stay, 'short') || str_contains($notes, 'airbnb')) {
                return 'short_term_guests';
            }

            return 'long_term_guests';
        }

        if ($entity instanceof DelegatedAccess) {
            $level = strtolower($entity->access_level ?? '');
            $rel = strtolower($entity->relationship ?? '');
            if (str_contains($level, 'short') || str_contains($rel, 'short') || str_contains($rel, 'airbnb')) {
                return 'short_term_guests';
            }
            if (str_contains($level, 'long') || str_contains($rel, 'long')) {
                return 'long_term_guests';
            }
            if (str_contains($level, 'contractor') || str_contains($rel, 'contractor')) {
                return 'contractors';
            }
            if (str_contains($level, 'staff') || str_contains($level, 'caregiver') || str_contains($rel, 'domestic') || str_contains($rel, 'staff')) {
                return 'staff';
            }

            return 'legacy_contacts';
        }

        if ($entity instanceof User) {
            if ($entity->role === UserRole::Staff || $entity->role === UserRole::Security) {
                return 'staff';
            }

            if ($entity->role === UserRole::TemporaryHomeowner) {
                return 'long_term_guests';
            }

            return 'residents';
        }

        if (is_array($entity)) {
            $cat = $entity['categoryKey'] ?? $entity['category'] ?? null;
            if ($cat && in_array($cat, ['residents', 'long_term_guests', 'short_term_guests', 'visitors', 'staff', 'contractors', 'legacy_contacts'], true)) {
                return $cat;
            }
        }

        // GatePass
        $pass = $entity;
        $category = $pass->category;
        $authType = strtolower($pass->metadata['authorization_type'] ?? '');
        $rel = strtolower($pass->metadata['relationship'] ?? '');
        $level = strtolower($pass->metadata['access_level'] ?? '');

        // 1. Short-Term Rental Guests (Airbnb, VRBO)
        if ($authType === 'short_term_rental' || str_contains($rel, 'airbnb') || str_contains($rel, 'short-term') || str_contains($rel, 'short term') || str_contains($level, 'airbnb') || str_contains($level, 'short-term')) {
            return 'short_term_guests';
        }

        // 2. Long-Term Guests / Renters
        if ($category === PassCategory::LongTermOccupant || $authType === 'long_term_occupant' || str_contains($rel, 'long-term') || str_contains($rel, 'long term') || str_contains($level, 'long-term') || $category === PassCategory::Renter) {
            return 'long_term_guests';
        }

        // 3. Visitors
        if ($category === PassCategory::Visitor) {
            return 'visitors';
        }

        // 4. Staff
        if (in_array($category, [PassCategory::Staff, PassCategory::Security, PassCategory::HomeownerStaff], true) || str_contains($level, 'staff') || str_contains($level, 'caregiver')) {
            return 'staff';
        }

        // 5. Contractors
        if ($category === PassCategory::Contractor || str_contains($rel, 'contractor') || str_contains($level, 'contractor')) {
            return 'contractors';
        }

        // 6. Legacy Contacts / Delegates
        if ($category === PassCategory::Delegate || ! empty($pass->metadata['delegated_access_id']) || str_contains($level, 'legacy') || str_contains($level, 'emergency') || str_contains($rel, 'attorney') || str_contains($rel, 'executor')) {
            return 'legacy_contacts';
        }

        // 7. Residents (Homeowners / System Admins)
        if (in_array($category, [PassCategory::Homeowner, PassCategory::SysAdmin, PassCategory::Admin], true)) {
            return 'residents';
        }

        return 'visitors';
    }

    public function categoryLabel(string $categoryKey): string
    {
        return match ($categoryKey) {
            'residents' => 'Residents',
            'long_term_guests' => 'Long-Term Guests / Renters',
            'short_term_guests' => 'Short-Term Rental Guests',
            'visitors' => 'Visitors',
            'staff' => 'Staff',
            'contractors' => 'Contractors',
            'legacy_contacts' => 'Legacy Contacts',
            default => 'Unknown Category',
        };
    }

    public function isEstatePersonnel(GatePass $pass): bool
    {
        $user = $pass->user;

        return $user !== null
            && $pass->visitor_id === null
            && $pass->delegated_access_id === null
            && ($user->role->isAdministrative() || $user->role->isOperational())
            && $user->properties->isEmpty();
    }

    public function normalizeUnit(?string $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        // Repeated prefixes too: passes were stored as "Unit Lot 42".
        if (preg_match('/^(?:(?:Unit|Lot|#)\s*)+([A-Za-z0-9\-_]+)/i', $trimmed, $m)) {
            return 'Unit '.$m[1];
        }

        if (preg_match('/^(\d+[A-Za-z]?)\s*(?:,|$)/', $trimmed, $m)) {
            return 'Unit '.$m[1];
        }

        return $trimmed;
    }

    /**
     * Model the underlying access relationship chain:
     * Person → Property → Relationship → Authorization → Visibility → Permissions
     */
    public function buildRelationshipChain(array $item): array
    {
        $cat = $item['categoryKey'] ?? 'visitors';
        $role = $item['role'] ?? $this->categoryLabel($cat);
        $name = $item['name'] ?? 'Authorized Person';
        $property = $item['property'] ?? $item['unit'] ?? 'Estate Property';
        $host = $item['host'] ?? $item['authorizedBy'] ?? 'Property Owner';
        $accessType = $item['accessType'] ?? match ($cat) {
            'legacy_contacts' => 'Emergency & Legacy Access',
            'short_term_guests' => 'Airbnb Reservation',
            'long_term_guests' => 'Residential Lease Agreement',
            'staff' => 'Estate Staff Clearance',
            'contractors' => 'Authorized Vendor Permit',
            'residents' => 'Primary Resident Title',
            default => 'Visitor Pass',
        };

        // Determine explicit permissions and boundaries
        $can = [];
        $cannot = [];

        switch ($cat) {
            case 'legacy_contacts':
                $can = ['Enter property', 'Receive emergency notifications', 'Authorize visitors during emergency'];
                $cannot = ['View community directory', 'View other properties', 'Modify property ownership'];
                break;
            case 'long_term_guests':
                $can = ['Enter property', 'Manage own visitors', 'See authorized property staff', 'See authorized property contractors', 'Manage own legacy contacts'];
                $cannot = ['View other properties', 'View community residents', 'Remove homeowner', 'Change property ownership', 'View homeowner financials'];
                break;
            case 'short_term_guests':
                $can = ['Enter property during stay', 'Generate visitor passes for own guests', 'View assigned property services'];
                $cannot = ['View homeowner legacy contacts', 'View unrelated residents', 'View community-wide contractor list', 'Access other properties'];
                break;
            case 'visitors':
                $can = ['Enter property at designated gate', 'Park in guest stall', 'Stay until pass expiration'];
                $cannot = ['Authorize other visitors', 'View community records', 'Access off-limits facilities'];
                break;
            case 'contractors':
                $can = ['Enter via service lane during permitted work hours', 'Access designated work parcel'];
                $cannot = ['Enter outside working hours', 'Access residential amenities', 'View community data'];
                break;
            case 'staff':
                $can = ['Enter estate for authorized duties', 'Verify gate credentials', 'Respond to site incidents'];
                $cannot = ['Access private residences without dispatch clearance'];
                break;
            case 'residents':
                $can = ['Full access to own property', 'Manage all occupants and visitors', 'Access community amenities', 'Authorize delegates'];
                $cannot = ['Access private lots of other homeowners'];
                break;
        }

        return [
            'person' => $name,
            'property' => $property,
            'relationship' => $role,
            'authorizedBy' => $host,
            'accessType' => $accessType,
            'visibilityScope' => in_array($cat, ['staff'], true) ? 'community' : 'property',
            'can' => $can,
            'cannot' => $cannot,
            'summary' => "{$name} → {$role} → {$property} → Authorized by {$host} → {$accessType}",
        ];
    }

    /**
     * Visitors, contractors, short-term guests and staff come and go; past the
     * configured age their check-in no longer says they are here. Residents,
     * long-term guests and delegates are never stale.
     */
    public function isStaleCheckIn(array $item): bool
    {
        if (! in_array($item['categoryKey'] ?? null, ['visitors', 'contractors', 'short_term_guests', 'staff'], true)) {
            return false;
        }

        if (empty($item['checkedInAt'])) {
            return false;
        }

        $hours = max(1, (int) config('occupancy.stale_after_hours', 24));

        return Carbon::parse($item['checkedInAt'])->lt(now()->subHours($hours));
    }
}
