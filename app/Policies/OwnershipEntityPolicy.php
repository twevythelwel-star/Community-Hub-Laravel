<?php

namespace App\Policies;

use App\Models\OwnershipEntity;
use App\Models\User;

/**
 * Who may see and act for a legal owner (company, trust, estate, individual).
 *
 * Changing who owns a property, and vouching that an entity is who it says
 * it is, stay with the estate's administrators: an entity's own directors
 * could otherwise claim a neighbour's house or verify themselves.
 */
class OwnershipEntityPolicy
{
    /** Administrators, the entity's current representatives, and owners of its properties. */
    public function view(User $user, OwnershipEntity $entity): bool
    {
        return $user->can('manageUsers')
            || $entity->hasAuthority($user)
            || $entity->properties()->where('owner_user_id', $user->id)->exists();
    }

    /** Appoint and revoke representatives, record beneficial owners. */
    public function manage(User $user, OwnershipEntity $entity): bool
    {
        return $user->can('manageUsers') || $entity->isUserSignatoryOrDirector($user);
    }

    /** Confidential beneficial owners: administrators and the entity's directors or signatories. */
    public function viewBeneficialOwnership(User $user, OwnershipEntity $entity): bool
    {
        return $entity->canUserViewBeneficialOwnership($user);
    }

    /** Register entities, verify them, and assign properties to them. */
    public function administer(User $user): bool
    {
        return $user->can('manageUsers');
    }
}
