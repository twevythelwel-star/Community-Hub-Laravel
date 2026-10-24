<?php

namespace App\Services\People;

use App\Enums\UserRole;
use App\Models\User;

class DelegationSectionsBuilder
{
    /**
     * Build category counts, sections, and permissions matrix for the Delegation dashboard.
     *
     * @param  array<string, mixed>  $context
     * @return array{categoryCounts: array<string, int>, sections: array<int, array<string, mixed>>, permissions: array<string, mixed>}
     */
    public function build(
        User $user,
        string $unitLabel,
        bool $isShortTerm,
        bool $isRenter,
        bool $isHomeowner,
        bool $isAdmin,
        ?string $scopeBadge,
        mixed $renterRecord,
        array $lists
    ): array {
        if ($isShortTerm) {
            $myStayList = $lists['myStayList'];
            $visitorsList = $lists['visitorsList'];
            $myAccessList = $lists['myAccessList'];
            $propertyServicesList = $lists['propertyServicesList'];

            $categoryCounts = [
                'all' => $lists['allPeople']->count(),
                'my_stay' => $myStayList->count(),
                'my_visitors' => $visitorsList->count(),
                'my_access' => $myAccessList->count(),
                'property_services' => $propertyServicesList->count(),
                'residents' => 0,
                'long_term_occupants' => 0,
                'short_term_rentals' => 0,
                'legacy_contacts' => 0,
                'family_members' => 0,
                'caregivers' => 0,
                'property_managers' => 0,
                'domestic_staff' => 0,
                'staff' => 0,
                'contractors' => 0,
                'visitors' => $visitorsList->count(),
                'former_occupants' => 0,
            ];

            $sections = [
                [
                    'key' => 'my_stay',
                    'title' => 'My Stay',
                    'subtitle' => "Property: {$unitLabel}",
                    'description' => "Reservation details, host contact, and check-in/check-out clearance for {$unitLabel}",
                    'count' => $myStayList->count(),
                    'items' => $myStayList,
                    'emptyMessage' => "No reservation record found for {$unitLabel}.",
                    'actionLabel' => '',
                    'actionCategory' => '',
                ],
                [
                    'key' => 'my_visitors',
                    'title' => 'My Visitors',
                    'subtitle' => "Guests registered for your stay at {$unitLabel}",
                    'description' => "Authorized visitors and reservation guests registered for your stay at {$unitLabel}",
                    'count' => $visitorsList->count(),
                    'items' => $visitorsList,
                    'emptyMessage' => "No additional visitors currently registered for your stay at {$unitLabel}.",
                    'actionLabel' => 'Pre-Clear Visitor',
                    'actionCategory' => 'visitors',
                ],
                [
                    'key' => 'my_access',
                    'title' => 'My Access',
                    'subtitle' => 'Digital Gate Pass & Scanner Clearance',
                    'description' => 'Your verified QR badge, gate ingress clearance, and stay validity window',
                    'count' => $myAccessList->count(),
                    'items' => $myAccessList,
                    'emptyMessage' => "No active gate credential issued for {$unitLabel}.",
                    'actionLabel' => '',
                    'actionCategory' => '',
                ],
                [
                    'key' => 'property_services',
                    'title' => 'Property Services',
                    'subtitle' => "Authorized staff & contractors for {$unitLabel}",
                    'description' => "Authorized maintenance, housekeeping, and emergency property contacts for {$unitLabel}",
                    'count' => $propertyServicesList->count(),
                    'items' => $propertyServicesList,
                    'emptyMessage' => 'No external property services scheduled during your stay.',
                    'actionLabel' => '',
                    'actionCategory' => '',
                ],
            ];
        } else {
            $residentsList = $lists['residentsList'];
            $longTermOccupantsList = $lists['longTermOccupantsList'];
            $shortTermRentalsList = $lists['shortTermRentalsList'];
            $legacyContactsList = $lists['legacyContactsList'];
            $familyMembersList = $lists['familyMembersList'];
            $caregiversList = $lists['caregiversList'];
            $propertyManagersList = $lists['propertyManagersList'];
            $domesticStaffList = $lists['domesticStaffList'];
            $staffList = $lists['staffList'];
            $contractorsList = $lists['contractorsList'];
            $visitorsList = $lists['visitorsList'];
            $formerOccupantsList = $lists['formerOccupantsList'];

            $categoryCounts = [
                'all' => $lists['allPeople']->count(),
                'residents' => $residentsList->count(),
                'long_term_occupants' => $longTermOccupantsList->count(),
                'short_term_rentals' => $shortTermRentalsList->count(),
                'legacy_contacts' => $legacyContactsList->count(),
                'family_members' => $familyMembersList->count(),
                'caregivers' => $caregiversList->count(),
                'property_managers' => $propertyManagersList->count(),
                'domestic_staff' => $domesticStaffList->count(),
                'staff' => $staffList->count(),
                'contractors' => $contractorsList->count(),
                'visitors' => $visitorsList->count(),
                'former_occupants' => $formerOccupantsList->count(),
                'my_stay' => 0,
                'my_visitors' => 0,
                'my_access' => 0,
                'property_services' => 0,
            ];

            if ($isRenter) {
                $sections = [
                    [
                        'key' => 'residents',
                        'title' => 'Co-Occupants',
                        'subtitle' => "Approved household members living with you at {$unitLabel}",
                        'description' => "Authorized co-occupants sharing your tenancy at {$unitLabel}",
                        'count' => $residentsList->count(),
                        'items' => $residentsList,
                        'emptyMessage' => "No co-occupants registered for your lease at {$unitLabel}.",
                        'actionLabel' => 'Add Co-Occupant',
                        'actionCategory' => 'residents',
                    ],
                    [
                        'key' => 'visitors',
                        'title' => 'Visitors',
                        'subtitle' => "Scheduled and pre-cleared guests for {$unitLabel}",
                        'description' => "Temporary visitors pre-cleared for entry during your tenancy at {$unitLabel}",
                        'count' => $visitorsList->count(),
                        'items' => $visitorsList,
                        'emptyMessage' => "No current or scheduled visitors for {$unitLabel}.",
                        'actionLabel' => 'Pre-Clear Visitor',
                        'actionCategory' => 'visitors',
                    ],
                    [
                        'key' => 'staff',
                        'title' => 'Staff & Service Providers',
                        'subtitle' => "Authorized household helpers for {$unitLabel}",
                        'description' => "Domestic staff, caregivers, and cleaners authorized for your tenancy at {$unitLabel}",
                        'count' => $staffList->count(),
                        'items' => $staffList,
                        'emptyMessage' => "No staff currently authorized for {$unitLabel}.",
                        'actionLabel' => 'Authorize Helper',
                        'actionCategory' => 'domestic_staff',
                    ],
                    [
                        'key' => 'contractors',
                        'title' => 'Contractors',
                        'subtitle' => "Approved maintenance personnel for {$unitLabel}",
                        'description' => "Authorized maintenance contractors, technicians, and repair personnel for {$unitLabel}",
                        'count' => $contractorsList->count(),
                        'items' => $contractorsList,
                        'emptyMessage' => "No active contractors currently cleared for {$unitLabel}.",
                        'actionLabel' => 'Authorize Contractor',
                        'actionCategory' => 'contractors',
                    ],
                    [
                        'key' => 'legacy_contacts',
                        'title' => 'Emergency Contacts',
                        'subtitle' => "Emergency delegates authorized for {$unitLabel}",
                        'description' => "Designated emergency contacts and limited delegates authorized for {$unitLabel}",
                        'count' => $legacyContactsList->count(),
                        'items' => $legacyContactsList,
                        'emptyMessage' => "No active emergency contacts configured for {$unitLabel}.",
                        'actionLabel' => 'Add Emergency Contact',
                        'actionCategory' => 'legacy_contacts',
                    ],
                ];
            } else {
                $sections = [
                    [
                        'key' => 'residents',
                        'title' => 'Household Members',
                        'subtitle' => "Family members and co-residents residing at {$unitLabel}",
                        'description' => "Permanent occupants with continuous residence privileges at {$unitLabel}",
                        'count' => $residentsList->count(),
                        'items' => $residentsList,
                        'emptyMessage' => "No household members registered yet for {$unitLabel}.",
                        'actionLabel' => 'Add Household Member',
                        'actionCategory' => 'residents',
                    ],
                    [
                        'key' => 'long_term_occupants',
                        'title' => 'Long-Term Guests / Renters',
                        'subtitle' => 'Authorized long-term occupants',
                        'description' => "Authorized extended occupants and long-term lease tenants residing in {$unitLabel}",
                        'count' => $longTermOccupantsList->count(),
                        'items' => $longTermOccupantsList,
                        'emptyMessage' => "No active long-term occupants or lease tenants registered for {$unitLabel}.",
                        'actionLabel' => 'Add Long-Term Occupant',
                        'actionCategory' => 'long_term_occupants',
                    ],
                    [
                        'key' => 'short_term_rentals',
                        'title' => 'Short-Term Rental',
                        'subtitle' => 'Active Airbnb/short-term occupants, if applicable',
                        'description' => "Active Airbnb reservations and temporary short-term occupants authorized for {$unitLabel}",
                        'count' => $shortTermRentalsList->count(),
                        'items' => $shortTermRentalsList,
                        'emptyMessage' => "No active Airbnb or short-term occupants currently staying at {$unitLabel}.",
                        'actionLabel' => 'Register Short-Term Guest',
                        'actionCategory' => 'short_term_rentals',
                    ],
                    [
                        'key' => 'visitors',
                        'title' => 'Visitors',
                        'subtitle' => "Current and upcoming visitors associated with {$unitLabel}",
                        'description' => "Pre-cleared day visitors, deliveries, and expected guests authorized for {$unitLabel}",
                        'count' => $visitorsList->count(),
                        'items' => $visitorsList,
                        'emptyMessage' => "No current or upcoming visitors scheduled for {$unitLabel}.",
                        'actionLabel' => 'Pre-Clear Visitor',
                        'actionCategory' => 'visitors',
                    ],
                    [
                        'key' => 'staff',
                        'title' => 'Staff',
                        'subtitle' => "Staff authorized for {$unitLabel}",
                        'description' => "Domestic staff, caregivers, housekeepers, gardeners, drivers, and property managers authorized for {$unitLabel}",
                        'count' => $staffList->count(),
                        'items' => $staffList,
                        'emptyMessage' => "No dedicated household staff currently authorized for {$unitLabel}.",
                        'actionLabel' => 'Authorize Staff Member',
                        'actionCategory' => 'domestic_staff',
                    ],
                    [
                        'key' => 'contractors',
                        'title' => 'Contractors',
                        'subtitle' => "Contractors authorized for {$unitLabel}",
                        'description' => "Authorized maintenance contractors, electricians, technicians, and repair personnel for {$unitLabel}",
                        'count' => $contractorsList->count(),
                        'items' => $contractorsList,
                        'emptyMessage' => "No active contractors currently cleared for {$unitLabel}.",
                        'actionLabel' => 'Authorize Contractor',
                        'actionCategory' => 'contractors',
                    ],
                    [
                        'key' => 'legacy_contacts',
                        'title' => 'Legacy Contacts',
                        'subtitle' => "Active legacy/emergency delegates for {$unitLabel}",
                        'description' => "Designated emergency contacts, attorneys, and succession delegates authorized for {$unitLabel}",
                        'count' => $legacyContactsList->count(),
                        'items' => $legacyContactsList,
                        'emptyMessage' => "No active legacy contacts or emergency delegates configured for {$unitLabel}.",
                        'actionLabel' => 'Add Legacy Contact',
                        'actionCategory' => 'legacy_contacts',
                    ],
                ];
            }
        }

        $permissions = [
            'canSee' => [
                'ownHousehold' => ! $isShortTerm,
                'ownVisitors' => true,
                'propertyStaff' => true,
                'propertyContractors' => true,
                'ownLegacyContacts' => ! $isShortTerm,
                'otherProperties' => false,
                'entireCommunity' => $isAdmin || $user->role->isSecurity(),
                'homeownerFinancials' => ! $isRenter && ! $isShortTerm && ($isHomeowner || $isAdmin),
                'shortTermRentals' => ! $isRenter && ! $isShortTerm && ($isHomeowner || $isAdmin),
                'propertyOwnership' => ! $isRenter && ! $isShortTerm && ($isHomeowner || $isAdmin),
            ],
            'canAct' => [
                'removeHomeowner' => false,
                'changePropertyOwnership' => false,
                'viewHomeownerFinancials' => false,
                'createPermanentCredentials' => ! $isRenter && ! $isShortTerm && ($isHomeowner || $isAdmin),
                'modifyCommunitySettings' => $isAdmin,
                'preClearVisitor' => true,
                'authorizeStaff' => ! $isShortTerm,
                'authorizeContractor' => ! $isShortTerm,
                'addOccupants' => ! $isShortTerm,
                'manageLegacyContacts' => ! $isShortTerm,
                'revokeOwnCredentials' => true,
            ],
            'isShortTerm' => $isShortTerm,
            'isRenter' => $isRenter,
            'isHomeowner' => $isHomeowner,
            'isAdmin' => $isAdmin,
            'roleLabel' => $isShortTerm ? 'Short-Term Rental Guest' : ($isRenter ? 'Long-Term Renter' : ($isHomeowner ? 'Homeowner' : $user->role->value)),
            'leaseEndDate' => $renterRecord?->lease_end?->toDateString(),
            'checkoutDate' => $renterRecord?->lease_end?->toDateString(),
            'scopeBadge' => $scopeBadge,
        ];

        return [
            'categoryCounts' => $categoryCounts,
            'sections' => $sections,
            'permissions' => $permissions,
        ];
    }
}
