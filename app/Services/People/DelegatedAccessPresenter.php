<?php

namespace App\Services\People;

use App\Enums\AuthorizationType;
use App\Enums\PassCategory;
use App\Enums\UserRole;
use App\Models\DelegatedAccess;
use App\Models\EmergencyContinuityPlan;
use App\Models\Household;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\AccessVisibilityService;
use App\Services\GatePassEngine;
use Illuminate\Http\Request;

class DelegatedAccessPresenter
{
    public function __construct(
        protected GatePassEngine $engine,
        protected PropertyPeopleService $propertyPeopleService,
        protected DelegationPeopleMapper $mapper,
        protected DelegationSectionsBuilder $sectionsBuilder
    ) {}

    public function present(Request $request): array
    {
        $user = $request->user();
        $visibilityService = app(AccessVisibilityService::class);
        $roleName = $visibilityService->resolveUserRole($user);
        $viewAs = strtolower((string) ($request->query('view_as') ?? $request->input('view_as') ?? ''));
        $renterRecord = ($user->role !== UserRole::Homeowner) ? ($user->renter ?? $user->activeStay()) : null;

        $isShortTermRenter = false;
        if ($renterRecord) {
            $stay = strtolower((string) ($renterRecord->stay_type ?? ''));
            $notes = strtolower((string) ($renterRecord->notes ?? ''));
            if (str_contains($stay, 'short') || str_contains($stay, 'airbnb') || str_contains($notes, 'airbnb') || str_contains($stay, 'vacation')) {
                $isShortTermRenter = true;
            }
        }

        $isShortTerm = ($user->role !== UserRole::Homeowner || in_array($viewAs, ['short_term', 'airbnb', 'guest', 'vacation'], true)) && (
            in_array($viewAs, ['short_term', 'airbnb', 'guest', 'vacation'], true)
            || $roleName === 'Short-Term Rental / Airbnb Host/Guest'
            || $roleName === 'Short-Term Renter'
            || $isShortTermRenter
        );

        $isRenter = ! $isShortTerm && ($roleName === 'Long-Term Renter' || $viewAs === 'renter');
        $isHomeowner = ! $isRenter && ! $isShortTerm && ($user->role === UserRole::Homeowner);
        $isAdmin = $user->role->isAdministrative();

        $unitLabel = $this->propertyPeopleService->resolveUnitLabel($user, $renterRecord);
        $property = $this->propertyPeopleService->resolvePrimaryProperty($user, $renterRecord);
        $propertyId = $property?->id;

        if ($isShortTerm) {
            $pageTitle = "{$unitLabel} — My Stay";
            $pageSubtitle = "Your reservation details, active access pass, registered guests, and property services for {$unitLabel}.";
            $scopeBadge = 'Short-Term Guest Scope • Reservation Bounded';
        } elseif ($isRenter) {
            $pageTitle = "{$unitLabel} — My Access Network";
            $pageSubtitle = "Authorized occupants, visitors, staff, and contractors for your tenancy at {$unitLabel}.";
            $scopeBadge = 'Tenant Access Scope • Bounded by Lease';
        } else {
            $pageTitle = "{$unitLabel} — People & Access";
            $pageSubtitle = "Authorized occupants, guests, staff, contractors, and legacy delegates strictly associated with {$unitLabel}.";
            $scopeBadge = 'Zero Cross-Unit Leakage • Scoped';
        }

        if ($isAdmin) {
            $allDelegations = DelegatedAccess::with(['grantor', 'delegate', 'property', 'gatePasses'])->latest()->get();
        } elseif ($isShortTerm || $isRenter) {
            $allDelegations = DelegatedAccess::with(['grantor', 'delegate', 'property', 'gatePasses'])
                ->where(function ($query) use ($user, $propertyId) {
                    $query->where('grantor_user_id', $user->id);
                    if ($propertyId) {
                        $query->orWhere(function ($propQuery) use ($propertyId) {
                            $propQuery->where('property_id', $propertyId)
                                ->where(function ($sub) {
                                    $sub->whereIn('authorization_type', ['domestic_staff', 'caregiver', 'property_manager', 'contractor'])
                                        ->orWhereIn('access_level', ['Domestic Staff', 'Caregiver', 'Property Delegate', 'Contractor']);
                                 });
                        });
                    }
                })
                ->latest()
                ->get();
        } else {
            $allDelegations = $user->delegatedAccessesGranted()->with(['delegate', 'property', 'gatePasses'])->latest()->get();
        }

        $userPass = $this->engine->issuePassFor($user);
        $residentsList = collect([$this->mapper->mapPrimaryOccupant($user, $renterRecord, $unitLabel, $userPass, $isRenter)]);
        $formerOccupantsList = collect();

        $households = Household::where('primary_homeowner_id', $user->id)->with(['members.gatePass'])->get();
        foreach ($households as $hh) {
            foreach ($hh->members as $member) {
                $isMemberActive = ($member->status === 'active') && (! $member->valid_until || ! $member->valid_until->isPast());
                $memberItem = $this->mapper->mapHouseholdMember($member, $unitLabel, $isMemberActive);
                if ($isMemberActive) {
                    $residentsList->push($memberItem);
                } else {
                    $formerOccupantsList->push($memberItem);
                }
            }
        }

        $longTermRentersList = collect();
        $shortTermRentalsList = collect();
        $renters = Renter::where('homeowner_id', $user->id)->get();
        foreach ($renters as $r) {
            $isRenterActive = ($r->status === 'Active') && (! $r->lease_end || ! $r->lease_end->isPast());
            $isItemShortTerm = (stripos($r->stay_type ?? '', 'short') !== false)
                || (stripos($r->stay_type ?? '', 'airbnb') !== false)
                || (stripos($r->notes ?? '', 'airbnb') !== false);

            $renterPass = null;
            if ($r->user_id) {
                $renterUser = User::find($r->user_id);
                if ($renterUser) {
                    $renterPass = $this->engine->issuePassFor($renterUser);
                }
            }

            $renterItem = $this->mapper->mapRenter($r, $unitLabel, $renterPass, $isRenterActive, $isItemShortTerm);
            if (! $isRenterActive) {
                $formerOccupantsList->push($renterItem);
            } elseif ($isItemShortTerm) {
                $shortTermRentalsList->push($renterItem);
            } else {
                $longTermRentersList->push($renterItem);
            }
        }

        $visitors = Visitor::where('homeowner_id', $user->id)->with('gatePass')->latest()->get();

        $longTermOccupantsList = collect()->concat($longTermRentersList);
        $legacyContactsList = collect();
        $familyMembersList = collect();
        $caregiversList = collect();
        $propertyManagersList = collect();
        $domesticStaffList = collect();
        $contractorsList = collect();
        $visitorsList = collect();

        foreach ($allDelegations as $d) {
            $isFormer = $d->status === 'revoked' || $d->isExpired() || ($d->expires_at && $d->expires_at->isPast());
            $pass = $d->gatePasses->first();
            if (! $pass && $d->hasPermission('gate_access') && $d->status === 'active' && ! $isFormer) {
                try {
                    $pass = $d->issueDelegateGatePass($this->engine, $user);
                } catch (\Throwable $e) {
                }
            }

            $personItem = $this->mapper->mapDelegation($d, $unitLabel, $pass, $isFormer);

            if ($isFormer) {
                $personItem['categoryKey'] = 'former_occupants';
                $personItem['categoryLabel'] = 'Former Delegate / Occupant';
                $personItem['categoryBadgeColor'] = 'bg-rose-500/10 text-rose-400 border-rose-500/30';
                $formerOccupantsList->push($personItem);
                continue;
            }

            if ($isShortTerm || ($isRenter && $d->grantor_user_id !== $user->id)) {
                $isStaffOrContractor = in_array($d->authorization_type, ['domestic_staff', 'caregiver', 'property_manager', 'contractor'])
                    || in_array($d->access_level, ['Domestic Staff', 'Caregiver', 'Property Delegate', 'Contractor']);
                if (! $isStaffOrContractor) {
                    continue;
                }
                $personItem['canManage'] = false;
                $personItem['propertyLabel'] = "{$unitLabel} • Authorized for Property";
            } else {
                $personItem['canManage'] = true;
            }

            $isShortTermDel = (stripos($d->authorization_type ?? '', 'short') !== false)
                || (stripos($d->access_level ?? '', 'short') !== false)
                || (stripos($d->relationship ?? '', 'airbnb') !== false);

            if ($isShortTermDel && ! $isRenter && ! $isShortTerm) {
                $personItem['categoryKey'] = 'short_term_rentals';
                $personItem['categoryLabel'] = 'Short-Term Rental (Guest)';
                $personItem['categoryBadgeColor'] = 'bg-pink-500/10 text-pink-400 border-pink-500/30';
                $shortTermRentalsList->push($personItem);
            } elseif ($d->authorization_type === 'long_term_occupant' || $d->access_level === 'Long-Term Occupant') {
                if (! $isShortTerm) {
                    $personItem['categoryKey'] = 'long_term_occupants';
                    $longTermOccupantsList->push($personItem);
                }
            } elseif ($d->authorization_type === 'caregiver' || $d->access_level === 'Caregiver') {
                $personItem['categoryKey'] = 'caregivers';
                $caregiversList->push($personItem);
            } elseif ($d->authorization_type === 'property_manager' || $d->access_level === 'Property Delegate') {
                $personItem['categoryKey'] = 'property_managers';
                $propertyManagersList->push($personItem);
            } elseif ($d->authorization_type === 'domestic_staff' || $d->access_level === 'Domestic Staff') {
                $personItem['categoryKey'] = 'domestic_staff';
                $domesticStaffList->push($personItem);
            } elseif ($d->authorization_type === 'legacy_contact' || in_array($d->access_level, ['Emergency Contact', 'Legacy Delegate', 'Emergency Delegate', 'Limited Delegate'])) {
                if (! $isShortTerm) {
                    $personItem['categoryKey'] = 'legacy_contacts';
                    $legacyContactsList->push($personItem);
                }
            } elseif ($d->authorization_type === 'family_member' || in_array($d->relationship, ['Family member', 'Family/Friend', 'Child', 'Spouse', 'Parent'])) {
                if (! $isRenter && ! $isShortTerm) {
                    $personItem['categoryKey'] = 'family_members';
                    $familyMembersList->push($personItem);
                } elseif ($isRenter) {
                    $personItem['categoryKey'] = 'legacy_contacts';
                    $legacyContactsList->push($personItem);
                }
            } elseif ($d->authorization_type === 'contractor' || $d->access_level === 'Contractor') {
                $personItem['categoryKey'] = 'contractors';
                $contractorsList->push($personItem);
            } else {
                if (! $isShortTerm) {
                    $personItem['categoryKey'] = 'legacy_contacts';
                    $legacyContactsList->push($personItem);
                }
            }
        }

        foreach ($visitors as $v) {
            $isFormer = $v->is_blocked || in_array($v->status->value, ['CheckedOut', 'Expired']) || ($v->expired_at !== null);
            $visitorItem = $this->mapper->mapVisitor($v, $unitLabel, $isFormer);
            $isContractor = ($v->type === 'Contractor') || ($v->gatePass?->category === PassCategory::Contractor);

            if ($isFormer) {
                $formerOccupantsList->push($visitorItem);
            } elseif ($isContractor) {
                $contractorsList->push($visitorItem);
            } else {
                $visitorsList->push($visitorItem);
            }
        }

        $staffList = collect()->concat($domesticStaffList)->concat($caregiversList)->concat($propertyManagersList)->values();

        if ($isShortTerm) {
            $checkoutDateFormatted = $renterRecord?->lease_end ? $renterRecord->lease_end->format('M j, Y') : now()->addDays(4)->format('M j, Y');
            $checkinDateFormatted = $renterRecord?->lease_start ? $renterRecord->lease_start->format('M j, Y') : now()->format('M j, Y');
            $hostName = $property?->owner?->name ?? $renterRecord?->homeowner?->name ?? 'Property Host';

            $myStayList = collect([[
                'id' => 'stay-'.$user->id,
                'sourceType' => 'renter',
                'sourceId' => $renterRecord?->id ?? $user->id,
                'categoryKey' => 'my_stay',
                'categoryLabel' => 'Active Reservation',
                'categoryBadgeColor' => 'bg-pink-500/10 text-pink-400 border-pink-500/30',
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'relationship' => 'Primary Guest (Reservation Lead)',
                'roleOrTitle' => 'Airbnb Guest',
                'propertyLabel' => $unitLabel,
                'propertyId' => $propertyId,
                'status' => 'active',
                'startsAt' => $renterRecord?->lease_start?->toIso8601String() ?? now()->toIso8601String(),
                'expiresAt' => $renterRecord?->lease_end?->toIso8601String() ?? now()->addDays(4)->toIso8601String(),
                'activationMethod' => 'reservation_checkin',
                'approvalStatus' => 'approved',
                'permissions' => [
                    'property' => $unitLabel,
                    'reservation_dates' => "{$checkinDateFormatted} – {$checkoutDateFormatted}",
                    'host' => $hostName,
                    'stay_type' => 'Short-Term Rental / Airbnb',
                    'gate_clearance' => 'Main Gate Ingress Authorized',
                ],
                'accessRules' => [
                    'gate_access' => true,
                    'pool_access' => true,
                    'gym_access' => true,
                    'entry_start_time' => '00:00',
                    'entry_end_time' => '23:59',
                ],
                'credential' => [
                    'passId' => $userPass->pass_id,
                    'category' => 'SHORT_TERM_OCCUPANT',
                    'categoryCode' => 'GP-AIR',
                    'shape' => 'RoundedSquare',
                    'status' => 'ACTIVE',
                    'isActive' => true,
                    'validFrom' => $renterRecord?->lease_start?->toIso8601String() ?? now()->toIso8601String(),
                    'validUntil' => $renterRecord?->lease_end?->toIso8601String() ?? now()->addDays(4)->toIso8601String(),
                ],
                'canManage' => false,
                'raw' => $renterRecord,
            ]]);

            $myAccessList = collect([[
                'id' => 'access-'.$userPass->pass_id,
                'sourceType' => 'user',
                'sourceId' => $user->id,
                'categoryKey' => 'my_access',
                'categoryLabel' => 'Digital Gate Pass',
                'categoryBadgeColor' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'relationship' => 'Gate Clearance',
                'roleOrTitle' => 'Verified Ingress Credential',
                'propertyLabel' => $unitLabel,
                'propertyId' => $propertyId,
                'status' => 'active',
                'startsAt' => $renterRecord?->lease_start?->toIso8601String() ?? now()->toIso8601String(),
                'expiresAt' => $renterRecord?->lease_end?->toIso8601String() ?? now()->addDays(4)->toIso8601String(),
                'activationMethod' => 'gate_pass_clearance',
                'approvalStatus' => 'approved',
                'permissions' => [
                    'qr_credential' => 'Active Dynamic QR (Anti-Replay / 60s Token)',
                    'gate_access' => 'Main Gate & Guest Ingress Cleared',
                    'validity_period' => "{$checkinDateFormatted} – {$checkoutDateFormatted}",
                ],
                'accessRules' => [
                    'gate_access' => true,
                    'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    'entry_start_time' => '00:00',
                    'entry_end_time' => '23:59',
                ],
                'credential' => [
                    'passId' => $userPass->pass_id,
                    'category' => 'SHORT_TERM_OCCUPANT',
                    'categoryCode' => 'GP-AIR',
                    'shape' => 'RoundedSquare',
                    'status' => 'ACTIVE',
                    'isActive' => true,
                    'validFrom' => $renterRecord?->lease_start?->toIso8601String() ?? now()->toIso8601String(),
                    'validUntil' => $renterRecord?->lease_end?->toIso8601String() ?? now()->addDays(4)->toIso8601String(),
                ],
                'canManage' => false,
                'raw' => $userPass,
            ]]);

            $propertyServicesList = collect()->concat($staffList)->concat($contractorsList)->values()->map(function ($item) {
                $item['categoryKey'] = 'property_services';
                $item['categoryLabel'] = 'Authorized Property Service';
                $item['categoryBadgeColor'] = 'bg-amber-500/10 text-amber-400 border-amber-500/30';
                $item['canManage'] = false;

                return $item;
            });

            $allPeople = collect()->concat($myStayList)->concat($visitorsList)->concat($myAccessList)->concat($propertyServicesList)->unique('id')->values();
        } else {
            $myStayList = collect();
            $myAccessList = collect();
            $propertyServicesList = collect();

            $allPeople = collect()
                ->concat($residentsList)
                ->concat($longTermOccupantsList)
                ->concat($shortTermRentalsList)
                ->concat($legacyContactsList)
                ->concat($familyMembersList)
                ->concat($caregiversList)
                ->concat($propertyManagersList)
                ->concat($domesticStaffList)
                ->concat($contractorsList)
                ->concat($visitorsList)
                ->unique('id')
                ->values();
        }

        $built = $this->sectionsBuilder->build(
            $user,
            $unitLabel,
            $isShortTerm,
            $isRenter,
            $isHomeowner,
            $isAdmin,
            $scopeBadge,
            $renterRecord,
            [
                'allPeople' => $allPeople,
                'myStayList' => $myStayList,
                'visitorsList' => $visitorsList,
                'myAccessList' => $myAccessList,
                'propertyServicesList' => $propertyServicesList,
                'residentsList' => $residentsList,
                'longTermOccupantsList' => $longTermOccupantsList,
                'shortTermRentalsList' => $shortTermRentalsList,
                'legacyContactsList' => $legacyContactsList,
                'familyMembersList' => $familyMembersList,
                'caregiversList' => $caregiversList,
                'propertyManagersList' => $propertyManagersList,
                'domesticStaffList' => $domesticStaffList,
                'staffList' => $staffList,
                'contractorsList' => $contractorsList,
                'formerOccupantsList' => $formerOccupantsList,
            ]
        );

        $categoryCounts = $built['categoryCounts'];
        $sections = $built['sections'];
        $permissions = $built['permissions'];

        $contacts = $allDelegations->filter(fn ($d) => $d->access_level === 'Emergency Contact')->values();
        $longTermOccupants = $allDelegations->filter(fn ($d) => $d->access_level === 'Long-Term Occupant' || $d->authorization_type === 'long_term_occupant')->values();
        $delegates = $allDelegations->filter(fn ($d) => $d->access_level !== 'Emergency Contact' && $d->access_level !== 'Long-Term Occupant' && $d->authorization_type !== 'long_term_occupant')->values();
        $continuityPlan = $user->emergencyContinuityPlan()->with(['primaryDelegate', 'secondaryDelegate'])->first();

        $authTypes = collect(AuthorizationType::cases())->map(fn ($type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'tier' => $type->tier()->label(),
            'isResident' => $type->isResident(),
            'defaultRules' => $type->defaultAccessRules(),
        ]);

        return [
            'authorizedPeople' => $allPeople,
            'categoryCounts' => $categoryCounts,
            'categorized' => [
                'residents' => $residentsList,
                'long_term_occupants' => $longTermOccupantsList,
                'short_term_rentals' => $shortTermRentalsList,
                'legacy_contacts' => $legacyContactsList,
                'family_members' => $familyMembersList,
                'caregivers' => $caregiversList,
                'property_managers' => $propertyManagersList,
                'domestic_staff' => $domesticStaffList,
                'staff' => $staffList,
                'contractors' => $contractorsList,
                'visitors' => $visitorsList,
                'former_occupants' => $formerOccupantsList,
            ],
            'homeownerSections' => $sections,
            'sections' => $sections,
            'permissions' => $permissions,
            'scopeBadge' => $scopeBadge,
            'pageSubtitle' => $pageSubtitle,
            'unitLabel' => $unitLabel,
            'pageTitle' => $pageTitle,
            'contacts' => $contacts,
            'delegates' => $delegates,
            'longTermOccupants' => $longTermOccupants,
            'allDelegations' => $allDelegations,
            'continuityPlan' => $continuityPlan,
            'properties' => $user->properties()->get(['id', 'lot_number', 'street_address']),
            'accessLevels' => DelegatedAccess::ACCESS_LEVELS,
            'relationships' => DelegatedAccess::RELATIONSHIPS,
            'availablePermissions' => DelegatedAccess::PERMISSIONS,
            'authorizationTypes' => $authTypes,
            'continuityConditions' => EmergencyContinuityPlan::CONDITIONS,
            'continuityVerifications' => EmergencyContinuityPlan::VERIFICATIONS,
            'continuityActions' => EmergencyContinuityPlan::ACTIONS,
            'durationTypes' => DelegatedAccess::DURATION_TYPES,
            'ownershipEntity' => $property?->ownershipEntity ? [
                'id' => $property->ownershipEntity->id,
                'legal_name' => $property->ownershipEntity->legal_name,
                'entity_type' => $property->ownershipEntity->entity_type->value,
                'jurisdiction' => $property->ownershipEntity->jurisdiction,
                'ownerLabel' => $property->ownerName(),
                'authorizedPeople' => $property->ownershipEntity->activeAuthorizedPeople()->with('user')->get()->map(fn ($p) => [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'name' => $p->full_name ?: $p->user?->name,
                    'role' => $p->role->label(),
                    'role_key' => $p->role->value,
                    'permissions' => $p->permissions,
                    'is_primary' => $p->is_primary_contact,
                    'is_current_user' => $p->user_id === $user->id,
                ])->values(),
            ] : null,
        ];
    }
}
