<?php

namespace App\Services\Properties;

use App\Models\AuthorizedPerson;
use App\Models\DelegatedAccess;
use App\Models\Household;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Models\Renter;
use App\Models\Visitor;
use Illuminate\Support\Collection;

/**
 * Scoped People & Access provider for corporate ownership property portfolios:
 * Residents, Renters, Visitors, Staff, Contractors, Legacy Contacts, and Corporate Representatives.
 */
class CompanyPortfolioPeopleProvider
{
    /**
     * Retrieve the 7 People & Access pillars scoped to the authorized property portfolio.
     */
    public function getPeopleAndAccess(
        OwnershipEntity $company,
        Collection $authorizedProperties,
        Collection $scopedProperties,
        array $scopedPropertyIds,
        array $scopedLotNumbers
    ): array {
        return [
            'residents' => $this->getScopedResidents($scopedProperties),
            'renters' => $this->getScopedRenters($scopedPropertyIds, $scopedLotNumbers),
            'visitors' => $this->getScopedVisitors($scopedPropertyIds, $scopedProperties),
            'staff' => $this->getScopedStaff($scopedPropertyIds),
            'contractors' => $this->getScopedContractors($scopedPropertyIds),
            'legacy_contacts' => $this->getScopedLegacyContacts($scopedPropertyIds),
            'representatives' => $this->getCompanyRepresentatives($company, $authorizedProperties),
        ];
    }

    /**
     * Residents: Primary occupants and verified household members.
     */
    public function getScopedResidents(Collection $properties): array
    {
        $residents = [];

        foreach ($properties as $property) {
            $occupant = $property->primaryOccupant;
            if ($occupant) {
                $residents[] = [
                    'id' => 'res_occ_'.$occupant->id,
                    'name' => $occupant->name,
                    'role' => 'Primary Occupant',
                    'unit' => $property->lot_number ?: "Unit {$property->id}",
                    'property_id' => $property->id,
                    'email' => $occupant->email,
                    'phone' => $occupant->phone ?: 'Unlisted',
                    'status' => 'Active',
                    'residing_since' => $occupant->created_at?->format('M Y') ?? 'Registered',
                    'badge' => 'Primary Resident',
                ];

                $household = Household::where('primary_homeowner_id', $occupant->id)->first();
                if ($household) {
                    foreach ($household->members()->where('status', 'active')->get() as $member) {
                        $residents[] = [
                            'id' => 'res_mem_'.$member->id,
                            'name' => $member->name,
                            'role' => $member->relationship_label ?: 'Household Member',
                            'unit' => $property->lot_number ?: "Unit {$property->id}",
                            'property_id' => $property->id,
                            'email' => $member->email ?: 'Via Primary Occupant',
                            'phone' => $member->phone ?: 'Unlisted',
                            'status' => 'Active',
                            'residing_since' => $member->created_at?->format('M Y') ?? 'Registered',
                            'badge' => 'Co-occupant',
                        ];
                    }
                }
            }
        }

        return $residents;
    }

    /**
     * Renters: Long-term tenants and short-term guests.
     */
    public function getScopedRenters(array $propertyIds, array $lotNumbers): array
    {
        return Renter::where(function ($q) use ($propertyIds, $lotNumbers) {
            $q->whereIn('property_id', $propertyIds);
            foreach ($lotNumbers as $lot) {
                $q->orWhere('lot', $lot)->orWhere('lot', "Unit {$lot}");
            }
        })
            ->latest()
            ->get()
            ->map(fn (Renter $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'unit' => $r->property?->lot_number ?: ($r->lot ?: 'Assigned Unit'),
                'property_id' => $r->property_id,
                'stay_type' => $r->stay_type ?: 'Long-term',
                'contact' => $r->contact ?: 'Unlisted',
                'status' => $r->status ?: 'Active',
                'lease_start' => $r->lease_start?->format('M d, Y') ?? 'Immediate',
                'lease_end' => $r->lease_end?->format('M d, Y') ?? 'Indefinite',
                'is_expired' => $r->leaseHasExpired(),
                'notes' => $r->notes,
            ])
            ->values()
            ->all();
    }

    /**
     * Visitors: Scheduled, expected, and checked-in guests.
     */
    public function getScopedVisitors(array $propertyIds, Collection $properties): array
    {
        $occupantUserIds = $properties->pluck('primary_occupant_id')->filter()->all();

        return Visitor::where(function ($q) use ($propertyIds, $occupantUserIds) {
            $q->whereIn('property_id', $propertyIds);
            if (! empty($occupantUserIds)) {
                $q->orWhereIn('homeowner_id', $occupantUserIds);
            }
        })
            ->latest('expected_at')
            ->get()
            ->map(fn (Visitor $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'type' => $v->type ?: 'Visitor',
                'unit' => $v->property?->lot_number ?: ($v->homeowner_name ?: 'Estate Unit'),
                'property_id' => $v->property_id,
                'status' => $v->status?->value ?? $v->status,
                'status_label' => ucfirst($v->status?->value ?? (string) $v->status),
                'expected_at' => $v->expected_at?->format('M d, Y H:i') ?? 'Today',
                'checked_in_at' => $v->checked_in_at?->format('H:i') ?? null,
                'host_name' => $v->homeowner_name ?: $v->homeowner?->name,
                'is_blocked' => (bool) $v->is_blocked,
            ])
            ->values()
            ->all();
    }

    /**
     * Staff: Domestic cleaners, housekeepers, caregivers, groundskeepers.
     */
    public function getScopedStaff(array $propertyIds): array
    {
        return DelegatedAccess::whereIn('property_id', $propertyIds)
            ->where(function ($q) {
                $q->whereIn('access_level', ['Domestic Staff', 'Caregiver', 'Staff', 'Housekeeper', 'Domestic Worker'])
                    ->orWhere('authorization_type', 'household_staff')
                    ->orWhere('authorization_type', 'estate_care');
            })
            ->latest()
            ->get()
            ->map(fn (DelegatedAccess $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'role' => $d->relationship ?: 'Household Staff',
                'access_level' => $d->access_level,
                'unit' => $d->property?->lot_number ?: "Unit {$d->property_id}",
                'property_id' => $d->property_id,
                'phone' => $d->phone ?: 'Unlisted',
                'email' => $d->email ?: 'Unlisted',
                'status' => $d->status ?: 'active',
                'schedule' => $d->access_rules['days_of_week'] ?? ['Scheduled Shifts'],
                'time_window' => ($d->access_rules['time_window_start'] ?? '08:00').' - '.($d->access_rules['time_window_end'] ?? '17:00'),
            ])
            ->values()
            ->all();
    }

    /**
     * Contractors: Service vendors, electricians, plumbers, construction teams.
     */
    public function getScopedContractors(array $propertyIds): array
    {
        return DelegatedAccess::whereIn('property_id', $propertyIds)
            ->where(function ($q) {
                $q->where('access_level', 'Contractor')
                    ->orWhere('authorization_type', 'vendor_work_order')
                    ->orWhere('authorization_type', 'preventive_maintenance')
                    ->orWhere('relationship', 'Contractor');
            })
            ->latest()
            ->get()
            ->map(fn (DelegatedAccess $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'trade' => $d->relationship ?: 'Contractor',
                'unit' => $d->property?->lot_number ?: "Unit {$d->property_id}",
                'property_id' => $d->property_id,
                'phone' => $d->phone ?: 'Unlisted',
                'email' => $d->email ?: 'Unlisted',
                'status' => $d->status ?: 'active',
                'starts_at' => $d->starts_at?->format('M d, Y') ?? 'Active',
                'expires_at' => $d->expires_at?->format('M d, Y') ?? 'On Completion',
                'notes' => $d->security_notes ?: 'Authorized property maintenance permit.',
            ])
            ->values()
            ->all();
    }

    /**
     * Legacy Contacts: Emergency succession contacts and continuity delegates.
     */
    public function getScopedLegacyContacts(array $propertyIds): array
    {
        return DelegatedAccess::whereIn('property_id', $propertyIds)
            ->where(function ($q) {
                $q->whereIn('access_level', ['Legacy Delegate', 'Emergency Contact', 'Emergency Delegate'])
                    ->orWhere('authorization_type', 'legacy_contact')
                    ->orWhere('authorization_type', 'emergency_delegate');
            })
            ->latest()
            ->get()
            ->map(fn (DelegatedAccess $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'relationship' => $d->relationship ?: 'Emergency Succession Contact',
                'unit' => $d->property?->lot_number ?: "Unit {$d->property_id}",
                'property_id' => $d->property_id,
                'phone' => $d->phone ?: 'Unlisted',
                'email' => $d->email ?: 'Unlisted',
                'status' => $d->status ?: 'active',
                'notes' => $d->security_notes ?: 'Designated legal & property succession contact.',
            ])
            ->values()
            ->all();
    }

    /**
     * Representatives: Authorized company directors, property managers, accountants.
     */
    public function getCompanyRepresentatives(OwnershipEntity $company, Collection $authorizedProperties): array
    {
        return $company->activeAuthorizedPeople()
            ->with('user')
            ->get()
            ->map(function (AuthorizedPerson $p) {
                $scopedProps = $p->authorizedProperties();
                $isAllProps = ! isset($p->metadata['authorized_property_ids']) || empty($p->metadata['authorized_property_ids']);

                return [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'name' => $p->full_name ?: $p->user?->name,
                    'role' => $p->role->value,
                    'role_label' => $p->role->label(),
                    'corporate_title' => $p->metadata['corporate_title'] ?? $p->role->label(),
                    'email' => $p->email ?: $p->user?->email,
                    'phone' => $p->phone ?: $p->user?->phone,
                    'is_primary_contact' => (bool) $p->is_primary_contact,
                    'is_signatory' => (bool) $p->is_signatory,
                    'verification_status' => $p->verification_status,
                    'status' => $p->status,
                    'permissions' => $p->effectivePermissions(),
                    'managed_properties_summary' => $isAllProps
                        ? 'All Company Properties'
                        : $scopedProps->pluck('lot_number')->implode(', '),
                    'authorized_property_ids' => $p->metadata['authorized_property_ids'] ?? null,
                ];
            })
            ->values()
            ->all();
    }
}
