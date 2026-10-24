<?php

namespace App\Services\Properties;

use App\Enums\AuthorizedPersonRole;
use App\Enums\OwnershipEntityType;
use App\Models\AuthorizedPerson;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OwnershipEntityService
{
    /**
     * Create a new legal Ownership Entity.
     */
    public function createEntity(array $attributes): OwnershipEntity
    {
        $type = $attributes['entity_type'] ?? OwnershipEntityType::Individual;
        if (is_string($type)) {
            $type = OwnershipEntityType::from($type);
        }

        return OwnershipEntity::create([
            'community_id' => $attributes['community_id'] ?? null,
            'entity_type' => $type,
            'legal_name' => trim($attributes['legal_name']),
            'trade_name' => $attributes['trade_name'] ?? null,
            'registration_number' => $attributes['registration_number'] ?? null,
            'jurisdiction' => $attributes['jurisdiction'] ?? ($type === OwnershipEntityType::Company ? 'Jamaica' : null),
            'primary_email' => $attributes['primary_email'] ?? null,
            'primary_phone' => $attributes['primary_phone'] ?? null,
            'billing_address' => $attributes['billing_address'] ?? null,
            'status' => $attributes['status'] ?? 'active',
            'verification_status' => $attributes['verification_status'] ?? 'unverified',
            'verified_at' => $attributes['verified_at'] ?? null,
            'verified_by_user_id' => $attributes['verified_by_user_id'] ?? null,
            'verification_source' => $attributes['verification_source'] ?? null,
            'verification_notes' => $attributes['verification_notes'] ?? null,
            'verification_checklist' => $attributes['verification_checklist'] ?? null,
            'beneficial_ownership' => $attributes['beneficial_ownership'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);
    }

    /**
     * Appoint an authorized person / representative to an ownership entity.
     * Validates that the appointed role is permissible for the entity type.
     */
    public function addAuthorizedPerson(OwnershipEntity $entity, array $attributes): AuthorizedPerson
    {
        $role = $attributes['role'] ?? AuthorizedPersonRole::AuthorizedRepresentative;
        if (is_string($role)) {
            $role = AuthorizedPersonRole::from($role);
        }

        // Validate role compatibility
        $allowed = $entity->entity_type->allowedRoles();
        if (! in_array($role, $allowed, true) && $role !== AuthorizedPersonRole::Other) {
            throw new InvalidArgumentException(
                "Role '{$role->label()}' is not permissible for entity type '{$entity->entity_type->label()}'."
            );
        }

        $isPrimary = (bool) ($attributes['is_primary_contact'] ?? false);

        return DB::transaction(function () use ($entity, $attributes, $role, $isPrimary) {
            if ($isPrimary) {
                // Clear any other primary contact if requested
                $entity->authorizedPeople()->update(['is_primary_contact' => false]);
            }

            return $entity->authorizedPeople()->create([
                'user_id' => $attributes['user_id'] ?? null,
                'role' => $role,
                'full_name' => trim($attributes['full_name']),
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'id_document_type' => $attributes['id_document_type'] ?? null,
                'id_document_number' => $attributes['id_document_number'] ?? null,
                'permissions' => $attributes['permissions'] ?? $role->defaultPermissions(),
                'is_primary_contact' => $isPrimary,
                'is_signatory' => (bool) ($attributes['is_signatory'] ?? $role->canExecuteLegalDocuments()),
                'valid_from' => $attributes['valid_from'] ?? null,
                'valid_until' => $attributes['valid_until'] ?? null,
                'status' => $attributes['status'] ?? 'active',
                'verification_status' => $attributes['verification_status'] ?? 'unverified',
            ]);
        });
    }

    /**
     * Revoke authorization of a representative (e.g. employee departure).
     * Property ownership remains completely unchanged.
     */
    public function revokeAuthorizedPerson(AuthorizedPerson $person, ?string $reason = null): void
    {
        DB::transaction(function () use ($person, $reason) {
            $person->revoke($reason);

            // assignProperty() records an entity's primary contact as the
            // properties' account holder (owner_user_id), and that account
            // holder has full authority. Revoking them must take it away:
            // the next contact in force takes over, or nobody.
            $entity = $person->ownershipEntity;
            if ($person->user_id && $entity && $entity->entity_type !== OwnershipEntityType::Individual) {
                $entity->properties()
                    ->where('owner_user_id', $person->user_id)
                    ->update(['owner_user_id' => $entity->primaryContact()?->user_id]);
            }
        });
    }

    /**
     * Appoint a representative, limited to the given properties of the
     * entity when any are listed (otherwise all of them).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function appointRepresentative(OwnershipEntity $entity, array $attributes): AuthorizedPerson
    {
        $person = $this->addAuthorizedPerson($entity, $attributes);

        $propertyIds = array_values(array_map('intval', $attributes['property_ids'] ?? []));
        if ($propertyIds !== []) {
            $person->update(['metadata' => [...($person->metadata ?? []), 'authorized_property_ids' => $propertyIds]]);
        }

        return $person;
    }

    /**
     * Assign a property to an ownership entity.
     */
    public function assignProperty(OwnershipEntity $entity, Property $property): Property
    {
        $property->update([
            'ownership_entity_id' => $entity->id,
            // If the entity has an individual owner with a user account, maintain owner_user_id for backwards compatibility
            'owner_user_id' => $entity->primaryContact()?->user_id ?? $property->owner_user_id,
        ]);

        return $property->fresh();
    }

    /**
     * Retrieve all authorized people for a given property.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function getAuthorizedPeopleForProperty(Property $property): Collection
    {
        $entity = $property->ownershipEntity ?? $property->effectiveOwnershipEntity();

        if (! $entity) {
            return new Collection;
        }

        return $entity->activeAuthorizedPeople()->get();
    }

    /**
     * Generic permission check on a property for a given user.
     */
    public function canUserPerform(User $user, Property $property, string $permission): bool
    {
        // Direct individual legacy owner has full authority
        if ($property->owner_user_id === $user->id) {
            return true;
        }

        $entity = $property->ownershipEntity;
        if (! $entity) {
            return false;
        }

        return $entity->hasAuthority($user, $permission);
    }

    public function canManageVisitors(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_visitors') || $this->canUserPerform($user, $property, 'manage_passes');
    }

    public function canManageRenters(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_renters_long_term') || $this->canUserPerform($user, $property, 'manage_renters_short_term');
    }

    public function canManageStaff(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_staff');
    }

    public function canManageContractors(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_contractors');
    }

    public function canManageVehicles(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_vehicles');
    }

    public function canManageParking(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_parking');
    }

    public function canManageEmergencyContacts(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_emergency_contacts');
    }

    public function canManageLegacyContacts(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_legacy_contacts');
    }

    public function canManageDelegatedAccess(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_delegated_access');
    }

    public function canReceiveNotifications(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'receive_notifications');
    }

    public function canReceiveSecurityAlerts(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'receive_security_alerts');
    }

    public function canManagePasses(User $user, Property $property): bool
    {
        return $this->canManageVisitors($user, $property);
    }

    public function canManageBilling(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_billing');
    }

    public function canManageOwnership(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'manage_ownership');
    }

    public function canTransferOwnership(User $user, Property $property): bool
    {
        return $this->canUserPerform($user, $property, 'transfer_ownership');
    }

    /**
     * Ensures an Individual OwnershipEntity exists for an individual user account.
     * Used for transparent backwards compatibility with legacy homeowners.
     */
    public function ensureIndividualEntityForUser(User $user): OwnershipEntity
    {
        $existing = OwnershipEntity::whereHas('authorizedPeople', function ($q) use ($user) {
            $q->where('user_id', $user->id)->where('role', AuthorizedPersonRole::IndividualOwner);
        })->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user) {
            $entity = OwnershipEntity::create([
                'entity_type' => OwnershipEntityType::Individual,
                'legal_name' => $user->name,
                'primary_email' => $user->email,
                'primary_phone' => $user->phone ?? null,
                'status' => 'active',
            ]);

            $entity->authorizedPeople()->create([
                'user_id' => $user->id,
                'role' => AuthorizedPersonRole::IndividualOwner,
                'full_name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'permissions' => AuthorizedPersonRole::IndividualOwner->defaultPermissions(),
                'is_primary_contact' => true,
                'is_signatory' => true,
                'status' => 'active',
                'verification_status' => 'verified',
            ]);

            return $entity;
        });
    }

    /**
     * Verify an Ownership Entity according to its legal type.
     *
     * Company:
     *   Verifies corporate information via Companies Office of Jamaica (COJ),
     *   identifies directors, shareholders, and corporate documentation.
     *   Beneficial ownership information is stored with separate confidentiality rules.
     *
     * Trust:
     *   Requires appropriate trust documentation (Trust Deed) and trustee authorization.
     *
     * Estate:
     *   Requires appropriate estate/executor documentation (Grant of Probate or Letters of Administration).
     *
     * Individual:
     *   Verifies identity (government ID/TRN) and property ownership/authorization (Certificate of Title).
     */
    public function verifyOwnershipEntity(OwnershipEntity $entity, array $payload, User $verifier): OwnershipEntity
    {
        return DB::transaction(function () use ($entity, $payload, $verifier) {
            match ($entity->entity_type) {
                OwnershipEntityType::Company => $entity->verifyCompany(
                    $payload,
                    $verifier,
                    $payload['beneficial_ownership'] ?? null
                ),
                OwnershipEntityType::Trust => $entity->verifyTrust($payload, $verifier),
                OwnershipEntityType::Estate => $entity->verifyEstate($payload, $verifier),
                default => $entity->verifyIndividual($payload, $verifier),
            };

            // If supporting legal documents were supplied, record or link them
            if (! empty($payload['documents']) && is_array($payload['documents'])) {
                foreach ($payload['documents'] as $doc) {
                    $entity->documents()->create([
                        'title' => $doc['title'] ?? 'Verification Legal Document',
                        'document_type' => $doc['document_type'] ?? 'verification_document',
                        'holder_name' => $entity->legal_name,
                        'file_path' => $doc['file_path'] ?? 'documents/legal/verified.pdf',
                        'file_name' => $doc['file_name'] ?? 'document.pdf',
                        'file_size_bytes' => $doc['file_size_bytes'] ?? 1024000,
                        'mime_type' => $doc['mime_type'] ?? 'application/pdf',
                        'issued_at' => $doc['issued_at'] ?? now()->toDateString(),
                        'expires_at' => $doc['expires_at'] ?? now()->addYears(10)->toDateString(),
                        'status' => 'valid',
                        'verification_status' => 'verified',
                        'verified_by' => $verifier->id,
                        'verified_at' => now(),
                    ]);
                }
            }

            return $entity->fresh();
        });
    }

    /**
     * Update confidential beneficial ownership records.
     * Accessible only by authorized corporate directors / signatories and compliance administrators.
     */
    public function updateBeneficialOwnership(OwnershipEntity $entity, array $data, User $actor): OwnershipEntity
    {
        if (! $entity->canUserViewBeneficialOwnership($actor)) {
            throw new InvalidArgumentException(
                'Unauthorized: Beneficial ownership information is confidential under Companies Office of Jamaica regulations and restricted to corporate directors and compliance officers.'
            );
        }

        $existing = $entity->beneficial_ownership ?? [];
        $existing['declaration_reference'] = $data['declaration_reference'] ?? ($existing['declaration_reference'] ?? "COJ-BO-{$entity->registration_number}");
        $existing['filing_date'] = $data['filing_date'] ?? ($existing['filing_date'] ?? now()->toDateString());
        $existing['coj_form_reference'] = $data['coj_form_reference'] ?? 'COJ Form 38 (Beneficial Ownership Declaration)';
        $existing['updated_by_user_id'] = $actor->id;
        $existing['updated_at'] = now()->toIso8601String();

        if (isset($data['beneficial_owners'])) {
            $existing['beneficial_owners'] = $data['beneficial_owners'];
        }

        $entity->update(['beneficial_ownership' => $existing]);

        return $entity->fresh();
    }

    /**
     * Retrieve beneficial ownership information, strictly enforcing confidentiality.
     */
    public function getBeneficialOwnership(OwnershipEntity $entity, User $actor): array
    {
        return $entity->getBeneficialOwnershipProfile($actor);
    }
}
