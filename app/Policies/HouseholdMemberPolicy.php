<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

class HouseholdMemberPolicy
{
    /**
     * Determine whether the user can view any household members.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isResident() || $user->role->isAdministrative();
    }

    /**
     * Determine whether the user can create a household member.
     */
    public function create(User $user): bool
    {
        return $user->role->isResident() || $user->role->isAdministrative();
    }

    /**
     * Determine whether the user can update the household member.
     */
    public function update(User $user, HouseholdMember $member): bool
    {
        if ($user->role->isAdministrative()) {
            return true;
        }

        if (! $user->role->isResident()) {
            return false;
        }

        $ownHouseholdId = Household::where('primary_homeowner_id', $user->id)->value('id');

        return $ownHouseholdId !== null && (int) $member->household_id === (int) $ownHouseholdId;
    }

    /**
     * Determine whether the user can delete the household member.
     */
    public function delete(User $user, HouseholdMember $member): bool
    {
        return $this->update($user, $member);
    }
}
