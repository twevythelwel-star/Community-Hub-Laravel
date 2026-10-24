<?php

namespace App\Services\Properties;

use App\Enums\OwnershipEntityType;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\OwnershipEntity;
use App\Models\ParkingPass;
use App\Models\Property;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Visitor;
use App\Models\DelegatedAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The company dashboard: a representative sees only the properties their
 * appointment covers; administrators see every company.
 */
class CompanyDashboardPresenter
{
    private CompanyPortfolioPeopleProvider $peopleProvider;
    private CompanyPortfolioOperationsProvider $operationsProvider;

    public function __construct(
        ?CompanyPortfolioPeopleProvider $peopleProvider = null,
        ?CompanyPortfolioOperationsProvider $operationsProvider = null
    ) {
        $this->peopleProvider = $peopleProvider ?? new CompanyPortfolioPeopleProvider();
        $this->operationsProvider = $operationsProvider ?? new CompanyPortfolioOperationsProvider();
    }

    /**
     * Resolve the active corporate entity for the user.
     */
    public function resolveCompanyForUser(User $user, ?string $requestedCompanyId = null): OwnershipEntity
    {
        // 1. If a specific company is requested and valid
        if ($requestedCompanyId) {
            $candidate = OwnershipEntity::find($requestedCompanyId);
            if ($candidate) {
                $this->authorizeAccessToCompany($user, $candidate);

                return $candidate;
            }
        }

        // 2. If the user is an authorized representative of any company
        $userEntity = OwnershipEntity::whereHas('authorizedPeople', function ($q) use ($user) {
            $q->where('user_id', $user->id)->current();
        })->where('entity_type', OwnershipEntityType::Company)->first();

        if ($userEntity) {
            return $userEntity;
        }

        // 3. An administrator starts on the first company.
        if ($user->role->isAdministrative()) {
            $primaryCompany = OwnershipEntity::where('entity_type', OwnershipEntityType::Company)->orderBy('id')->first();

            if ($primaryCompany) {
                return $primaryCompany;
            }
        }

        // 4. Any active authorized entity (including trust/partnership if applicable)
        $anyAuthorized = OwnershipEntity::whereHas('authorizedPeople', function ($q) use ($user) {
            $q->where('user_id', $user->id)->current();
        })->first();

        if ($anyAuthorized) {
            return $anyAuthorized;
        }

        // 5. Fallback for admins: first entity or abort 403 for non-admins
        $fallback = OwnershipEntity::first();
        if ($fallback && $user->role->isAdministrative()) {
            return $fallback;
        }

        abort(403, 'You are not an authorized representative of any company ownership entity.');
    }

    /**
     * Check if user is authorized to view this company entity.
     */
    private function authorizeAccessToCompany(User $user, OwnershipEntity $entity): void
    {
        Gate::forUser($user)->authorize('view', $entity);
    }

    /**
     * Render the company dashboard data with strict property-level authorization scoping.
     */
    public function props(Request $request, OwnershipEntity $company): array
    {
        $user = $request->user();

        // ── STRICT PROPERTY SCOPING ──────────────────────────────────────
        // "The company representative sees only properties they are authorized to manage."
        $authorizedProperties = $user->authorizedPropertiesForEntity($company);

        // If the company has properties but representative has none authorized:
        if ($authorizedProperties->isEmpty() && ! $user->role->isAdministrative()) {
            // Representative has no properties assigned
            $authorizedPropertyIds = [];
        } else {
            $authorizedPropertyIds = $authorizedProperties->pluck('id')->all();
        }

        // Property filtering: single unit vs entire authorized portfolio
        $requestedPropertyParam = $request->string('property_id')->trim()->toString();
        $selectedProperty = null;

        if ($requestedPropertyParam && $requestedPropertyParam !== 'all') {
            $selectedProperty = $authorizedProperties->first(function ($p) use ($requestedPropertyParam) {
                return (string) $p->id === $requestedPropertyParam
                    || strcasecmp($p->lot_number, $requestedPropertyParam) === 0
                    || strcasecmp($p->property_code, $requestedPropertyParam) === 0;
            });

            if (! $selectedProperty) {
                // Deny unauthorized property access attempt
                abort(403, 'You are not authorized to manage the requested property.');
            }

            $scopedProperties = collect([$selectedProperty]);
        } else {
            $scopedProperties = $authorizedProperties;
        }

        $scopedPropertyIds = $scopedProperties->pluck('id')->all();
        $scopedLotNumbers = $scopedProperties->pluck('lot_number')->filter()->all();

        // ── Available companies for the switcher ──────────────────────────
        $availableCompanies = ($user->role->isAdministrative())
            ? OwnershipEntity::where('entity_type', OwnershipEntityType::Company)
                ->select(['id', 'legal_name', 'trade_name', 'entity_type', 'status'])
                ->get()
            : OwnershipEntity::whereHas('authorizedPeople', function ($q) use ($user) {
                $q->where('user_id', $user->id)->current();
            })->select(['id', 'legal_name', 'trade_name', 'entity_type', 'status'])->get();

        // ── PROPERTIES LIST ──────────────────────────────────────────────
        $propertiesList = $authorizedProperties->map(function (Property $property) use ($selectedProperty) {
            $statusRaw = strtolower($property->status ?: 'vacant');
            $statusLabel = match ($statusRaw) {
                'occupied' => 'Occupied',
                'rented' => 'Rented',
                default => 'Vacant',
            };

            $statusColor = match ($statusRaw) {
                'occupied' => 'emerald',
                'rented' => 'blue',
                default => 'amber',
            };

            $currentOccupant = $property->primaryOccupant;
            $currentRenter = $property->renters()->where('status', 'Active')->first();

            return [
                'id' => $property->id,
                'lot_number' => $property->lot_number ?: "Unit {$property->id}",
                'property_code' => $property->property_code,
                'street_address' => $property->street_address ?: 'Royal Palm Way',
                'property_type' => $property->property_type ?: 'Residential Unit',
                'status' => $statusRaw,
                'status_label' => $statusLabel,
                'status_color' => $statusColor,
                'is_selected' => $selectedProperty?->id === $property->id,
                'occupant_name' => $property->occupantName() ?? ($currentRenter?->name ?: 'Vacant'),
                'occupant' => $currentOccupant ? [
                    'id' => $currentOccupant->id,
                    'name' => $currentOccupant->name,
                    'phone' => $currentOccupant->phone,
                    'email' => $currentOccupant->email,
                ] : null,
                'renter' => $currentRenter ? [
                    'id' => $currentRenter->id,
                    'name' => $currentRenter->name,
                    'stay_type' => $currentRenter->stay_type,
                    'lease_end' => $currentRenter->lease_end?->format('M d, Y'),
                ] : null,
                'stats' => [
                    'residents_count' => ($property->primary_occupant_id ? 1 : 0) + ($property->primaryOccupant ? Household::where('primary_homeowner_id', $property->primary_occupant_id)->first()?->members()->count() ?? 0 : 0),
                    'renters_count' => $property->renters()->count(),
                    'visitors_count' => Visitor::where('property_id', $property->id)->count(),
                    'staff_count' => DelegatedAccess::where('property_id', $property->id)->whereIn('access_level', ['Domestic Staff', 'Caregiver', 'Staff', 'Housekeeper'])->count(),
                    'contractors_count' => DelegatedAccess::where('property_id', $property->id)->where('access_level', 'Contractor')->count(),
                    'vehicles_count' => Vehicle::where('property', $property->lot_number)->orWhere('property', "Unit {$property->lot_number}")->count(),
                    'parking_count' => ParkingPass::where('property', $property->lot_number)->orWhere('property', "Unit {$property->lot_number}")->count(),
                    'pending_balance_minor' => Invoice::where('property_id', $property->id)->where('status', 'pending')->sum('amount_minor'),
                ],
            ];
        })->values();

        // ── PEOPLE & ACCESS (The 7 Pillars) ──────────────────────────────
        $peopleAndAccess = $this->peopleProvider->getPeopleAndAccess(
            $company,
            $authorizedProperties,
            $scopedProperties,
            $scopedPropertyIds,
            $scopedLotNumbers
        );

        // ── OPERATIONS (The 6 Pillars) ───────────────────────────────────
        $operations = $this->operationsProvider->getOperations(
            $company,
            $scopedProperties,
            $scopedPropertyIds,
            $scopedLotNumbers
        );

        // ── Aggregate Summary KPIs ───────────────────────────────────────
        $summary = [
            'total_properties' => $authorizedProperties->count(),
            'occupied_count' => $authorizedProperties->where('status', 'occupied')->count(),
            'vacant_count' => $authorizedProperties->where('status', 'vacant')->count(),
            'rented_count' => $authorizedProperties->where('status', 'rented')->count(),
            'total_residents' => count($peopleAndAccess['residents']),
            'total_renters' => count($peopleAndAccess['renters']),
            'total_visitors' => count($peopleAndAccess['visitors']),
            'total_staff' => count($peopleAndAccess['staff']),
            'total_contractors' => count($peopleAndAccess['contractors']),
            'total_legacy_contacts' => count($peopleAndAccess['legacy_contacts']),
            'total_representatives' => count($peopleAndAccess['representatives']),
            'total_vehicles' => count($operations['vehicles']),
            'total_parking' => count($operations['parking']),
            'total_credentials' => count($operations['credentials']),
            'total_incidents' => count($operations['incidents']),
            'total_documents' => count($operations['documents']),
            'total_pending_dues_minor' => Invoice::whereIn('property_id', $scopedPropertyIds)->where('status', 'pending')->sum('amount_minor'),
            'total_paid_dues_minor' => Invoice::whereIn('property_id', $scopedPropertyIds)->where('status', 'paid')->sum('amount_minor'),
        ];

        // User's specific representative record for this company
        $userPerson = $company->activeAuthorizedPeople()->where('user_id', $user->id)->first();

        return [
            'company' => [
                'id' => $company->id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'entity_type' => $company->entity_type->value,
                'entity_type_label' => $company->entity_type->label(),
                'registration_number' => $company->registration_number,
                'jurisdiction' => $company->jurisdiction,
                'primary_email' => $company->primary_email,
                'primary_phone' => $company->primary_phone,
                'billing_address' => $company->billing_address,
                'status' => $company->status,
                'verification_status' => $company->verification_status ?: 'verified',
                'is_verified' => $company->isVerified(),
                'verification_source' => $company->verification_source ?: 'Companies Office of Jamaica (COJ)',
                'metadata' => $company->metadata,
            ],
            'verification' => $company->getVerificationSummary(),
            'beneficial_ownership' => $company->getBeneficialOwnershipProfile($user),
            'can_view_beneficial_ownership' => $company->canUserViewBeneficialOwnership($user),
            'available_companies' => $availableCompanies,
            'properties' => $propertiesList,
            'selected_property_id' => $selectedProperty?->id ?? 'all',
            'selected_property' => $selectedProperty ? [
                'id' => $selectedProperty->id,
                'lot_number' => $selectedProperty->lot_number,
                'property_code' => $selectedProperty->property_code,
                'street_address' => $selectedProperty->street_address,
                'status' => $selectedProperty->status,
                'occupant_name' => $selectedProperty->occupantName(),
            ] : null,
            'summary' => $summary,
            'people_and_access' => $peopleAndAccess,
            'operations' => $operations,
            'current_user_authorization' => [
                'is_admin' => $user->role->isAdministrative(),
                'role' => $userPerson?->role?->value ?? ($user->role->isAdministrative() ? 'admin' : 'representative'),
                'role_label' => $userPerson?->role?->label() ?? ($user->role->isAdministrative() ? 'System Administrator' : 'Authorized Representative'),
                'is_signatory' => (bool) ($userPerson?->is_signatory ?? $user->role->isAdministrative()),
                'is_primary_contact' => (bool) ($userPerson?->is_primary_contact ?? false),
                'can_view_beneficial_ownership' => $company->canUserViewBeneficialOwnership($user),
                'permissions' => $userPerson?->effectivePermissions() ?? ['*'],
                'can_manage_visitors' => $userPerson?->canManageVisitors() ?? true,
                'can_manage_renters' => $userPerson?->canManageRenters() ?? true,
                'can_manage_staff' => $userPerson?->canManageStaff() ?? true,
                'can_manage_contractors' => $userPerson?->canManageContractors() ?? true,
                'can_manage_vehicles' => $userPerson?->canManageVehicles() ?? true,
                'can_manage_billing' => $userPerson?->canManageBilling() ?? true,
            ],
        ];
    }
}
