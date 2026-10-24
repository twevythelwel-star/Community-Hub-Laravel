<?php

namespace App\Services\Properties;

use App\Enums\AuthorizedPersonRole;
use App\Enums\OwnershipEntityType;
use App\Models\AuthorizedPerson;
use App\Models\HouseholdMember;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Models\User;
use App\Services\GatePassEngine;
use App\Services\HouseholdManagementService;

/**
 * What the Ownership page and the entity API show a given viewer. Contact and
 * identity details are only for those who run the entity (the policy's
 * manage ability).
 */
class OwnershipPagePresenter
{
    public function __construct(
        private readonly OwnershipEntityService $entityService,
        private readonly HouseholdManagementService $householdService,
        private readonly GatePassEngine $engine,
        private readonly EnterpriseAuthorizationRegistry $authRegistry,
    ) {}

    /**
     * Props for Dashboard/Ownership: the entity asked for (if the viewer may
     * see it), else the first they represent, else their own.
     *
     * @return array<string, mixed>
     */
    public function page(User $user, ?string $requestedType, ?int $requestedEntityId): array
    {
        // 1. Resolve all entities accessible to this user
        $availableEntities = $this->resolveAvailableEntities($user);

        // 2. Determine target ownership type and active entity
        $activeEntity = null;
        $ownershipType = 'individual';

        if ($requestedEntityId) {
            $entity = OwnershipEntity::with(['properties', 'authorizedPeople.user', 'verifiedByUser'])->find($requestedEntityId);
            if ($entity && $user->can('view', $entity)) {
                $activeEntity = $entity;
                $ownershipType = $entity->entity_type->value;
            }
        }

        if (! $activeEntity && $requestedType) {
            // Find first accessible entity matching requested type
            $matching = $availableEntities->first(fn ($e) => ($e->entity_type->value ?? $e->entity_type) === $requestedType);
            if ($matching) {
                $activeEntity = OwnershipEntity::with(['properties', 'authorizedPeople.user', 'verifiedByUser'])->find($matching->id);
                $ownershipType = $requestedType;
            } elseif ($requestedType === 'individual') {
                $ownershipType = 'individual';
                $activeEntity = $this->ownIndividualEntity($user);
            }
        }

        // Default resolution if still not set
        if (! $activeEntity) {
            // Check if user is appointed to a company, trust, or estate
            $firstRepresented = $user->representedEntities()->first();
            if ($firstRepresented) {
                $activeEntity = $firstRepresented;
                $ownershipType = $firstRepresented->entity_type->value;
            } else {
                $directProperty = $user->properties()->with('ownershipEntity')->first();
                if ($directProperty?->ownershipEntity) {
                    $activeEntity = $directProperty->ownershipEntity;
                    $ownershipType = $activeEntity->entity_type->value;
                } else {
                    $ownershipType = 'individual';
                    $activeEntity = $this->ownIndividualEntity($user);
                }
            }
        }

        $canManage = $activeEntity ? $user->can('manage', $activeEntity) : false;

        if ($activeEntity) {
            $activeEntity->loadMissing(['properties', 'authorizedPeople.user', 'verifiedByUser']);
        }

        // 3. Dynamic Title, Subtitle, and Icon metadata
        $metadata = $this->getOwnershipMetadata($ownershipType);

        // 4. Resolve Representatives / Appointees
        $representatives = $activeEntity ? $activeEntity->authorizedPeople->map(function (AuthorizedPerson $p) use ($canManage) {
            $scopeProperties = $p->authorizedProperties();

            return [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'name' => $p->full_name,
                'role' => $p->role->value,
                'role_label' => $p->role->label(),
                // Contact and identity details are for those who run the entity.
                'email' => $canManage ? $p->email : null,
                'phone' => $canManage ? $p->phone : null,
                'id_document_type' => $canManage ? $p->id_document_type : null,
                'id_document_number' => $canManage ? $p->id_document_number : null,
                'permissions' => $p->permissions ?? $p->role->defaultPermissions(),
                'is_primary_contact' => $p->is_primary_contact,
                'is_signatory' => $p->is_signatory,
                'status' => $p->status,
                'is_active' => $p->isActive(),
                'valid_until' => $p->valid_until?->toFormattedDateString(),
                'authorized_properties' => $scopeProperties->map(fn (Property $prop) => [
                    'id' => $prop->id,
                    'lot_number' => $prop->lot_number,
                    'street_address' => $prop->street_address,
                ]),
            ];
        }) : collect();

        // 5. Resolve Household Data (for Individual ownership)
        $householdData = null;
        if ($user->role->isResident() || $user->properties()->exists()) {
            $household = $this->householdService->getOrCreateHousehold($user);
            $householdData = [
                'id' => $household->id,
                'name' => $household->name,
                'property_number' => $household->property_number,
                'address' => $household->address,
                'members' => $household->members->map(function (HouseholdMember $m) {
                    $token = null;
                    if ($m->gatePass && $m->gatePass->isActive()) {
                        try {
                            $token = $this->engine->issueToken($m->gatePass)['token'];
                        } catch (\Throwable $e) {
                            $token = $m->gatePass->pass_id;
                        }
                    }

                    return [
                        'id' => $m->id,
                        'name' => $m->name,
                        'email' => $m->email,
                        'phone' => $m->phone,
                        'role_in_household' => $m->role_in_household,
                        'relationship_label' => $m->relationship_label,
                        'pass_category' => $m->pass_category,
                        'credential_label' => $m->credentialTypeLabel(),
                        'role_badge_class' => $m->roleBadgeColor(),
                        'permissions' => $m->permissions ?? [],
                        'status' => $m->status,
                        'valid_until' => $m->valid_until?->toFormattedDateString(),
                        'gate_pass' => $m->gatePass ? [
                            'id' => $m->gatePass->id,
                            'pass_id' => $m->gatePass->pass_id,
                            'category' => $m->gatePass->category->value,
                            'status' => $m->gatePass->status->value,
                            'token' => $token,
                        ] : null,
                    ];
                }),
            ];
        }

        // 6. Properties associated with this entity / user
        $properties = $activeEntity ? $activeEntity->properties->map(fn (Property $p) => [
            'id' => $p->id,
            'lot_number' => $p->lot_number,
            'property_code' => $p->property_code,
            'street_address' => $p->street_address,
            'property_type' => $p->property_type,
            'status' => $p->status,
            'primary_occupant' => $p->primaryOccupant ? [
                'name' => $p->primaryOccupant->name,
                'email' => $p->primaryOccupant->email,
            ] : null,
        ]) : collect();

        // 7. Statutory Verification & Beneficial Ownership Protection
        $verificationSummary = $activeEntity ? $activeEntity->getVerificationSummary() : null;
        $canViewBeneficialOwnership = $activeEntity ? $activeEntity->canUserViewBeneficialOwnership($user) : false;
        $beneficialOwnership = $activeEntity ? $activeEntity->getBeneficialOwnershipProfile($user) : null;

        // 8. Permissible Roles for this Entity Type
        $allowedRoleEnums = $activeEntity ? $activeEntity->entity_type->allowedRoles() : OwnershipEntityType::Individual->allowedRoles();
        $availableRoles = collect($allowedRoleEnums)->map(fn (AuthorizedPersonRole $r) => [
            'value' => $r->value,
            'label' => $r->label(),
        ]);

        $hierarchy = $activeEntity ? $this->authRegistry->resolveHierarchy($activeEntity) : null;

        return [
            'ownershipType' => $ownershipType,
            'moduleTitle' => $metadata['title'],
            'moduleSubtitle' => $metadata['subtitle'],
            'moduleBadge' => $metadata['badge'],
            'moduleIcon' => $metadata['icon'],
            'activeEntity' => $activeEntity ? [
                'id' => $activeEntity->id,
                'legal_name' => $activeEntity->legal_name,
                'trade_name' => $activeEntity->trade_name,
                'entity_type' => $activeEntity->entity_type->value,
                'entity_type_label' => $activeEntity->entity_type->label(),
                'registration_number' => $activeEntity->registration_number,
                'tax_id' => $activeEntity->tax_id,
                'jurisdiction' => $activeEntity->jurisdiction,
                'status' => $activeEntity->status,
                'verification_status' => $activeEntity->verification_status ?: 'unverified',
                'is_verified' => $activeEntity->isVerified(),
                'metadata' => $activeEntity->metadata,
            ] : null,
            'hierarchy' => $hierarchy,
            'verification' => $verificationSummary,
            'beneficialOwnership' => $beneficialOwnership,
            'canViewBeneficialOwnership' => $canViewBeneficialOwnership,
            'representatives' => $representatives,
            'household' => $householdData,
            'properties' => $properties,
            'availableEntities' => $availableEntities->map(fn ($e) => [
                'id' => $e->id,
                'legal_name' => $e->legal_name,
                'entity_type' => $e->entity_type->value ?? $e->entity_type,
                'entity_type_label' => is_object($e->entity_type) ? $e->entity_type->label() : ucfirst($e->entity_type),
                'property_count' => $e->properties?->count() ?? 0,
            ]),
            'availableRoles' => $availableRoles,
            'availableHouseholdPermissions' => HouseholdManagementService::AVAILABLE_PERMISSIONS,
            'canManage' => $canManage,
        ];
    }

    /**
     * One entity, its properties, representatives and verification.
     *
     * @return array<string, mixed>
     */
    public function entity(OwnershipEntity $entity, User $user): array
    {
        $canManage = $user->can('manage', $entity);
        $entity->load(['properties', 'authorizedPeople.user', 'verifiedByUser']);

        return [
            'entity' => [
                'id' => $entity->id,
                'legal_name' => $entity->legal_name,
                'trade_name' => $entity->trade_name,
                'entity_type' => $entity->entity_type->value,
                'entity_type_label' => $entity->entity_type->label(),
                'registration_number' => $entity->registration_number,
                'jurisdiction' => $entity->jurisdiction,
                'status' => $entity->status,
                'verification_status' => $entity->verification_status ?: 'unverified',
                'is_verified' => $entity->isVerified(),
                'metadata' => $entity->metadata,
            ],
            'properties' => $entity->properties->map(fn (Property $p) => [
                'id' => $p->id,
                'label' => $p->label(),
                'code' => $p->property_code,
                'lot_number' => $p->lot_number,
                'street_address' => $p->street_address,
            ]),
            'representatives' => $entity->authorizedPeople->map(fn (AuthorizedPerson $p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'name' => $p->full_name,
                'role' => $p->role->value,
                'role_label' => $p->role->label(),
                'email' => $canManage ? $p->email : null,
                'phone' => $canManage ? $p->phone : null,
                'permissions' => $p->permissions ?? $p->role->defaultPermissions(),
                'is_primary_contact' => $p->is_primary_contact,
                'is_signatory' => $p->is_signatory,
                'status' => $p->status,
                'is_active' => $p->isActive(),
            ]),
            'verification' => $entity->getVerificationSummary(),
            'beneficial_ownership' => $entity->getBeneficialOwnershipProfile($user),
            'can_view_beneficial_ownership' => $entity->canUserViewBeneficialOwnership($user),
        ];
    }

    /**
     * Dynamic naming and metadata based on ownership type.
     */
    private function getOwnershipMetadata(string $type): array
    {
        return match ($type) {
            'company' => [
                'title' => 'Ownership & Representatives',
                'subtitle' => 'Corporate ownership structure, registered representatives, Companies Office of Jamaica (COJ) verification, and corporate governance.',
                'badge' => 'Corporate Entity • COJ Registered',
                'icon' => 'Building2',
            ],
            'individual' => [
                'title' => 'Ownership & Household',
                'subtitle' => 'Individual property ownership, registered household members, family dependents, and domestic keyholders.',
                'badge' => 'Individual Title • Private Household',
                'icon' => 'HeartHandshake',
            ],
            'trust' => [
                'title' => 'Trustees & Representatives',
                'subtitle' => 'Fiduciary trust settlement, appointed trustees, powers of administration, and designated representatives.',
                'badge' => 'Fiduciary Trust • Registered Trust Deed',
                'icon' => 'Landmark',
            ],
            'estate' => [
                'title' => 'Executors & Representatives',
                'subtitle' => 'Estate administration, Supreme Court Grant of Probate or Letters of Administration, and appointed executors.',
                'badge' => 'Supreme Court Probate • Estate Administration',
                'icon' => 'FileText',
            ],
            default => [
                'title' => 'Ownership & Representation',
                'subtitle' => 'Property ownership structure and authorized legal representatives.',
                'badge' => 'Property Ownership',
                'icon' => 'Building2',
            ],
        };
    }

    private function resolveAvailableEntities(User $user)
    {
        if ($user->role->isAdministrative()) {
            return OwnershipEntity::with('properties')->get();
        }

        $represented = $user->representedEntities();
        $individual = $this->ownIndividualEntity($user);

        return ($individual ? $represented->push($individual) : $represented)->unique('id')->values();
    }

    /**
     * The individual entity of someone who owns property in their own name.
     * Not created for anyone else: viewing this page used to give every
     * guard and staff member an "ownership entity".
     */
    private function ownIndividualEntity(User $user): ?OwnershipEntity
    {
        return $user->properties()->exists() ? $this->entityService->ensureIndividualEntityForUser($user) : null;
    }
}
