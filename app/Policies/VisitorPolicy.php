<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visitor;

class VisitorPolicy
{
    /**
     * Determine whether the user can view any visitors.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('accessEstateInformation') || $user->can('manageSecurity');
    }

    /**
     * Determine whether the user can view the specific visitor.
     */
    public function view(User $user, Visitor $visitor): bool
    {
        if ($user->can('manageSecurity') || $user->id === $visitor->homeowner_id) {
            return true;
        }

        return $visitor->property_id !== null && $user->canManageVisitorsForProperty($visitor->property_id);
    }

    /**
     * Determine whether the user can create/register visitors.
     */
    public function create(User $user): bool
    {
        return $user->can('registerVisitors');
    }

    /**
     * Determine whether the user can update the visitor pass.
     */
    public function update(User $user, Visitor $visitor): bool
    {
        if ($user->can('manageSecurity') || $user->id === $visitor->homeowner_id) {
            return true;
        }

        return $visitor->property_id !== null && $user->canManageVisitorsForProperty($visitor->property_id);
    }

    /**
     * Determine whether the user can delete/cancel the visitor.
     */
    public function delete(User $user, Visitor $visitor): \Illuminate\Auth\Access\Response
    {
        if ($user->can('manageSecurity') || $user->id === $visitor->homeowner_id) {
            return \Illuminate\Auth\Access\Response::allow();
        }

        if ($visitor->property_id !== null && $user->canManageVisitorsForProperty($visitor->property_id)) {
            return \Illuminate\Auth\Access\Response::allow();
        }

        return \Illuminate\Auth\Access\Response::deny('You can only manage visitors you registered or are authorized to manage.');
    }

    /**
     * Determine whether security can check in the visitor.
     */
    public function checkIn(User $user, Visitor $visitor): bool
    {
        return $user->can('scanPasses');
    }
}
