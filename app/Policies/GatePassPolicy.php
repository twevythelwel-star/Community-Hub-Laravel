<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\GatePass;
use App\Models\User;

class GatePassPolicy
{
    /**
     * Determine whether the user can view the gate pass.
     */
    public function view(User $user, GatePass $pass): bool
    {
        if ($user->id === $pass->user_id || $user->can('scanPasses') || $user->role->isAdministrative()) {
            return true;
        }

        return \App\Models\Household::where('primary_homeowner_id', $user->id)
            ->whereHas('members', fn ($q) => $q->where('gate_pass_id', $pass->id))
            ->exists();
    }

    /**
     * Determine whether the user can scan passes at gates.
     */
    public function scan(User $user): bool
    {
        return $user->can('scanPasses');
    }

    /**
     * Determine whether the user can rotate their pass secret.
     */
    public function rotate(User $user, GatePass $pass): bool
    {
        return $user->id === $pass->user_id || $user->can('manageSecurity');
    }

    /**
     * Determine whether the user can revoke the pass.
     */
    public function revoke(User $user, GatePass $pass): bool
    {
        return $user->can('manageSecurity') || $user->role === UserRole::SystemAdmin;
    }
}
