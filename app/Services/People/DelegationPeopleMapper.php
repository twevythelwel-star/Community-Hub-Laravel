<?php

namespace App\Services\People;

use App\Enums\PassCategory;
use App\Enums\VisitorStatus;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\HouseholdMember;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;

class DelegationPeopleMapper
{
    /**
     * Map the primary resident or tenant.
     */
    public function mapPrimaryOccupant(User $user, ?Renter $renterRecord, string $unitLabel, GatePass $userPass, bool $isRenter): array
    {
        if ($isRenter) {
            return [
                'id' => 'user-'.$user->id,
                'sourceType' => 'user',
                'sourceId' => $user->id,
                'categoryKey' => 'residents',
                'categoryLabel' => 'Primary Tenant (Leaseholder)',
                'categoryBadgeColor' => 'bg-indigo-500/10 text-indigo-400 border-indigo-500/30',
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'relationship' => 'Primary Leaseholder',
                'roleOrTitle' => 'Long-Term Renter',
                'propertyLabel' => $unitLabel,
                'propertyId' => null,
                'status' => 'active',
                'startsAt' => $renterRecord?->lease_start?->toIso8601String() ?? $user->created_at?->toIso8601String(),
                'expiresAt' => $renterRecord?->lease_end?->toIso8601String(),
                'activationMethod' => 'lease_clearance',
                'approvalStatus' => 'approved',
                'permissions' => [
                    'gate_access' => 'Digital Gate Pass & Scanner Clearance',
                    'pool_access' => 'Estate Pool & Clubhouse Access',
                    'gym_access' => 'Fitness Center Access',
                    'parking_allocated' => 'Tenant Allocated Parking',
                    'visitor_authorization' => 'Authorize Visitors (Within Lease Limits)',
                ],
                'accessRules' => [
                    'gate_access' => true,
                    'pool_access' => true,
                    'gym_access' => true,
                    'clubhouse_access' => true,
                    'parking_allocated' => true,
                    'can_invite_visitors' => true,
                    'requires_id_verification' => false,
                    'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    'entry_start_time' => '00:00',
                    'entry_end_time' => '23:59',
                ],
                'credential' => [
                    'passId' => $userPass->pass_id,
                    'category' => 'RENTER',
                    'categoryCode' => 'GP-RNT',
                    'shape' => 'Squircle',
                    'status' => $userPass->status->value,
                    'isActive' => $userPass->isActive(),
                    'validFrom' => $userPass->valid_from?->toIso8601String(),
                    'validUntil' => $renterRecord?->lease_end?->toIso8601String() ?? $userPass->valid_until?->toIso8601String(),
                ],
                'raw' => $user,
            ];
        }

        return [
            'id' => 'user-'.$user->id,
            'sourceType' => 'user',
            'sourceId' => $user->id,
            'categoryKey' => 'residents',
            'categoryLabel' => 'Resident (Owner)',
            'categoryBadgeColor' => 'bg-emerald-500/10 text-emerald-500 border-emerald-500/30',
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? null,
            'relationship' => 'Primary Homeowner',
            'roleOrTitle' => 'Homeowner / Resident',
            'propertyLabel' => $unitLabel,
            'propertyId' => $user->properties()->first()?->id,
            'status' => 'active',
            'startsAt' => $user->created_at?->toIso8601String(),
            'expiresAt' => null,
            'activationMethod' => 'immediate',
            'approvalStatus' => 'approved',
            'permissions' => [
                'gate_access' => 'Digital Gate Pass & Scanner Clearance',
                'pool_access' => 'Estate Pool & Clubhouse Access',
                'gym_access' => 'Fitness Center Access',
                'parking_allocated' => 'Resident Allocated Parking',
                'visitor_authorization' => 'Authorize Visitors & Pre-Clear Guests',
                'emergency_communications' => 'Receive & Broadcast Emergency Alerts',
                'property_maintenance' => 'Submit & Approve Maintenance Work',
            ],
            'accessRules' => [
                'gate_access' => true,
                'pool_access' => true,
                'gym_access' => true,
                'clubhouse_access' => true,
                'parking_allocated' => true,
                'can_invite_visitors' => true,
                'requires_id_verification' => false,
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                'entry_start_time' => '00:00',
                'entry_end_time' => '23:59',
            ],
            'credential' => [
                'passId' => $userPass->pass_id,
                'category' => $userPass->category->value,
                'categoryCode' => $userPass->category->passIdPrefix(),
                'shape' => $userPass->category->shape()->value,
                'status' => $userPass->status->value,
                'isActive' => $userPass->isActive(),
                'validFrom' => $userPass->valid_from?->toIso8601String(),
                'validUntil' => $userPass->valid_until?->toIso8601String(),
            ],
            'raw' => $user,
        ];
    }

    /**
     * Map a household member.
     */
    public function mapHouseholdMember(HouseholdMember $member, string $unitLabel, bool $isMemberActive): array
    {
        $mPass = $member->gatePass;

        $mCatCode = match ($member->pass_category) {
            'HOMEOWNER' => 'GP-HO',
            'RENTER' => 'GP-RNT',
            'LONG_TERM_OCCUPANT' => 'GP-LTO',
            'DELEGATE' => 'GP-DEL',
            'HOMEOWNER_STAFF' => 'GP-HST',
            default => 'GP-RES',
        };

        $mShape = match ($member->pass_category) {
            'HOMEOWNER' => 'Hexagon',
            'RENTER' => 'Squircle',
            'LONG_TERM_OCCUPANT' => 'HouseHex',
            'DELEGATE' => 'Octagon',
            'HOMEOWNER_STAFF' => 'HouseHex',
            default => 'RoundedSquare',
        };

        return [
            'id' => 'household-member-'.$member->id,
            'sourceType' => 'household_member',
            'sourceId' => $member->id,
            'categoryKey' => $isMemberActive ? 'residents' : 'former_occupants',
            'categoryLabel' => $isMemberActive ? ('Household Member ('.ucfirst($member->role_in_household).')') : 'Former Household Member',
            'categoryBadgeColor' => $isMemberActive ? $member->roleBadgeColor() : 'bg-zinc-500/10 text-zinc-400 border-zinc-500/30',
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'relationship' => $member->relationship_label ?: ucfirst($member->role_in_household),
            'roleOrTitle' => 'Household Member • '.ucfirst($member->role_in_household),
            'propertyLabel' => $unitLabel,
            'propertyId' => null,
            'status' => $isMemberActive ? 'active' : 'expired',
            'startsAt' => $member->created_at?->toIso8601String(),
            'expiresAt' => $member->valid_until?->toIso8601String(),
            'activationMethod' => 'household_sponsorship',
            'approvalStatus' => $isMemberActive ? 'approved' : 'expired',
            'permissions' => $member->permissions ?? ['gate_access'],
            'accessRules' => $member->access_schedule ?? [
                'gate_access' => true,
                'pool_access' => true,
                'gym_access' => true,
                'clubhouse_access' => true,
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                'entry_start_time' => '00:00',
                'entry_end_time' => '23:59',
            ],
            'credential' => $mPass ? [
                'passId' => $mPass->pass_id,
                'category' => $mPass->category->value,
                'categoryCode' => $mCatCode,
                'shape' => $mShape,
                'status' => $mPass->status->value,
                'isActive' => $mPass->isActive() && $isMemberActive,
                'validFrom' => $mPass->valid_from?->toIso8601String(),
                'validUntil' => $mPass->valid_until?->toIso8601String(),
            ] : [
                'passId' => 'GP-HHM-'.$member->id,
                'category' => $member->pass_category ?: 'RESIDENT',
                'categoryCode' => $mCatCode,
                'shape' => $mShape,
                'status' => $isMemberActive ? 'ACTIVE' : 'EXPIRED',
                'isActive' => $isMemberActive,
                'validFrom' => $member->created_at?->toIso8601String(),
                'validUntil' => $member->valid_until?->toIso8601String(),
            ],
            'raw' => $member,
        ];
    }

    /**
     * Map a renter.
     */
    public function mapRenter(Renter $r, string $unitLabel, ?GatePass $renterPass, bool $isRenterActive, bool $isItemShortTerm): array
    {
        $catCode = $isItemShortTerm ? 'GP-AIR' : 'GP-RNT';
        $shape = $isItemShortTerm ? 'RoundedSquare' : 'Squircle';
        $badgeColor = $isItemShortTerm
            ? 'bg-pink-500/10 text-pink-400 border-pink-500/30'
            : 'bg-indigo-500/10 text-indigo-400 border-indigo-500/30';

        return [
            'id' => 'renter-'.$r->id,
            'sourceType' => 'renter',
            'sourceId' => $r->id,
            'categoryKey' => ! $isRenterActive ? 'former_occupants' : ($isItemShortTerm ? 'short_term_rentals' : 'long_term_occupants'),
            'categoryLabel' => ! $isRenterActive ? 'Former Tenant' : ($isItemShortTerm ? 'Short-Term Rental (Airbnb)' : 'Tenant (Leaseholder)'),
            'categoryBadgeColor' => ! $isRenterActive ? 'bg-zinc-500/10 text-zinc-400 border-zinc-500/30' : $badgeColor,
            'name' => $r->name,
            'email' => $r->email,
            'phone' => $r->phone,
            'relationship' => $isItemShortTerm ? 'Short-Term Guest' : 'Tenant',
            'roleOrTitle' => $isItemShortTerm ? 'Short-Term Stay' : 'Long-Term Tenant',
            'propertyLabel' => $unitLabel,
            'propertyId' => $r->property_id,
            'status' => $isRenterActive ? 'active' : 'expired',
            'startsAt' => $r->lease_start?->toIso8601String(),
            'expiresAt' => $r->lease_end?->toIso8601String(),
            'activationMethod' => $isItemShortTerm ? 'reservation_checkin' : 'lease_clearance',
            'approvalStatus' => $isRenterActive ? 'approved' : 'expired',
            'permissions' => [
                'gate_access' => 'Digital Gate Pass & Scanner Clearance',
                'pool_access' => 'Estate Pool & Clubhouse Access',
                'gym_access' => 'Fitness Center Access',
                'parking_allocated' => 'Tenant Allocated Parking',
            ],
            'accessRules' => [
                'gate_access' => true,
                'pool_access' => true,
                'gym_access' => true,
                'clubhouse_access' => true,
                'parking_allocated' => true,
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                'entry_start_time' => '00:00',
                'entry_end_time' => '23:59',
            ],
            'credential' => $renterPass ? [
                'passId' => $renterPass->pass_id,
                'category' => $renterPass->category->value,
                'categoryCode' => $renterPass->category->passIdPrefix(),
                'shape' => $renterPass->category->shape()->value,
                'status' => $renterPass->status->value,
                'isActive' => $renterPass->isActive() && $isRenterActive,
                'validFrom' => $renterPass->valid_from?->toIso8601String(),
                'validUntil' => $renterPass->valid_until?->toIso8601String(),
            ] : [
                'passId' => sprintf('%s-%d', $catCode, $r->id),
                'category' => $isItemShortTerm ? 'SHORT_TERM_OCCUPANT' : 'RENTER',
                'categoryCode' => $catCode,
                'shape' => $shape,
                'status' => $isRenterActive ? 'ACTIVE' : 'EXPIRED',
                'isActive' => $isRenterActive,
                'validFrom' => $r->lease_start?->toIso8601String(),
                'validUntil' => $r->lease_end?->toIso8601String(),
            ],
            'raw' => $r,
        ];
    }

    /**
     * Map a delegated access record.
     */
    public function mapDelegation(DelegatedAccess $d, string $unitLabel, ?GatePass $pass, bool $isFormer): array
    {
        $catCode = match (true) {
            $d->access_level === 'Long-Term Occupant' || $d->authorization_type === 'long_term_occupant' => 'GP-LTO',
            $d->access_level === 'Domestic Staff' || $d->authorization_type === 'domestic_staff' => 'GP-HST',
            default => 'GP-DEL',
        };

        $shape = match ($catCode) {
            'GP-LTO' => 'RoundedSquare',
            'GP-HST' => 'HouseHex',
            default => 'Octagon',
        };

        $badgeColor = match ($catCode) {
            'GP-LTO' => 'bg-indigo-500/10 text-indigo-400 border-indigo-500/30',
            'GP-HST' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
            default => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
        };

        return [
            'id' => 'del-'.$d->id,
            'sourceType' => 'delegation',
            'sourceId' => $d->id,
            'categoryKey' => '',
            'categoryLabel' => $d->access_level,
            'categoryBadgeColor' => $badgeColor,
            'name' => $d->name,
            'email' => $d->email,
            'phone' => $d->phone,
            'relationship' => $d->relationship,
            'roleOrTitle' => $d->access_level,
            'propertyLabel' => $d->property ? "Lot {$d->property->lot_number}" : $unitLabel,
            'propertyId' => $d->property_id,
            'status' => $d->status,
            'isEmergencyActive' => (bool) $d->is_emergency_active,
            'startsAt' => $d->starts_at?->toIso8601String(),
            'expiresAt' => $d->expires_at?->toIso8601String(),
            'durationType' => $d->duration_type ?? 'custom',
            'durationLabel' => $d->durationLabel(),
            'activationMethod' => $d->activation_method,
            'approvalStatus' => $d->approval_status,
            'permissions' => $d->permissions ?? [],
            'accessRules' => $d->access_rules ?? [],
            'credential' => $pass ? [
                'passId' => $pass->pass_id,
                'category' => $pass->category->value,
                'categoryCode' => $catCode,
                'shape' => $shape,
                'status' => $pass->status->value,
                'isActive' => $pass->isActive(),
                'validFrom' => $pass->valid_from?->toIso8601String(),
                'validUntil' => $pass->valid_until?->toIso8601String(),
            ] : [
                'passId' => sprintf('%s-%d-REG', $catCode, $d->id),
                'category' => $catCode === 'GP-LTO' ? 'LONG_TERM_OCCUPANT' : ($catCode === 'GP-HST' ? 'HOMEOWNER_STAFF' : 'DELEGATE'),
                'categoryCode' => $catCode,
                'shape' => $shape,
                'status' => $d->status === 'active' ? 'ACTIVE' : 'INACTIVE',
                'isActive' => $d->status === 'active' && ! $isFormer,
                'validFrom' => $d->starts_at?->toIso8601String(),
                'validUntil' => $d->expires_at?->toIso8601String(),
            ],
            'raw' => $d,
        ];
    }

    /**
     * Map a visitor.
     */
    public function mapVisitor(Visitor $v, string $unitLabel, bool $isFormer): array
    {
        $vPass = $v->gatePass;
        $isContractor = ($v->type === 'Contractor') || ($vPass?->category === PassCategory::Contractor);
        $catCode = $isContractor ? 'GP-CON' : 'GP-VIS';
        $shape = $isContractor ? 'Pentagon' : 'Circle';
        $badgeColor = $isContractor
            ? 'bg-amber-500/10 text-amber-400 border-amber-500/30'
            : 'bg-teal-500/10 text-teal-400 border-teal-500/30';

        return [
            'id' => 'vis-'.$v->id,
            'sourceType' => 'visitor',
            'sourceId' => $v->id,
            'categoryKey' => $isFormer ? 'former_occupants' : ($isContractor ? 'contractors' : 'visitors'),
            'categoryLabel' => $isFormer ? 'Former Guest / Worker' : ($isContractor ? 'Contractor / Vendor' : 'Visitor / Guest'),
            'categoryBadgeColor' => $isFormer ? 'bg-zinc-500/10 text-zinc-400 border-zinc-500/30' : $badgeColor,
            'name' => $v->name,
            'email' => $v->contact && filter_var($v->contact, FILTER_VALIDATE_EMAIL) ? $v->contact : null,
            'phone' => $v->contact && ! filter_var($v->contact, FILTER_VALIDATE_EMAIL) ? $v->contact : null,
            'relationship' => $isContractor ? 'Contractor / Maintenance' : 'Visitor / Social Guest',
            'roleOrTitle' => $isContractor ? 'Service Contractor' : 'Visitor',
            'propertyLabel' => $unitLabel,
            'propertyId' => null,
            'status' => $v->is_blocked ? 'revoked' : strtolower($v->status->value),
            'startsAt' => $v->expected_at?->toIso8601String(),
            'expiresAt' => $v->expired_at?->toIso8601String() ?? $vPass?->valid_until?->toIso8601String(),
            'activationMethod' => 'guest_clearance',
            'approvalStatus' => $v->status === VisitorStatus::Expected ? 'approved' : 'checked_in',
            'permissions' => [
                'gate_access' => 'Digital Gate Pass & Scanner Clearance',
            ],
            'accessRules' => [
                'gate_access' => true,
                'requires_id_verification' => filled($v->id_number) || filled($v->id_type),
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                'entry_start_time' => '06:00',
                'entry_end_time' => '22:00',
            ],
            'credential' => $vPass ? [
                'passId' => $vPass->pass_id,
                'category' => $vPass->category->value,
                'categoryCode' => $catCode,
                'shape' => $shape,
                'status' => $vPass->status->value,
                'isActive' => $vPass->isActive() && ! $isFormer,
                'validFrom' => $vPass->valid_from?->toIso8601String(),
                'validUntil' => $vPass->valid_until?->toIso8601String(),
            ] : [
                'passId' => sprintf('%s-%d', $catCode, $v->id),
                'category' => $isContractor ? 'CONTRACTOR' : 'VISITOR',
                'categoryCode' => $catCode,
                'shape' => $shape,
                'status' => $isFormer ? 'EXPIRED' : 'ACTIVE',
                'isActive' => ! $isFormer,
                'validFrom' => $v->expected_at?->toIso8601String(),
                'validUntil' => $v->expired_at?->toIso8601String(),
            ],
            'raw' => $v,
        ];
    }
}
