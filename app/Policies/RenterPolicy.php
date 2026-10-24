<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Renter;
use App\Models\User;

class RenterPolicy
{
    /**
     * Determine whether the user can view the renters list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('accessHomeownerFunctions');
    }

    /**
     * Determine whether the user can create renters.
     */
    public function create(User $user): bool
    {
        return $user->can('manageUsers');
    }

    /**
     * Determine whether the user can update the renter.
     */
    public function update(User $user, Renter $renter): bool
    {
        if ($user->role->isAdministrative()) {
            return true;
        }

        return $user->role === UserRole::Homeowner && (int) $renter->homeowner_id === (int) $user->id;
    }

    /**
     * Determine whether the user can delete the renter.
     */
    public function delete(User $user, Renter $renter): bool
    {
        return $this->update($user, $renter);
    }
}
