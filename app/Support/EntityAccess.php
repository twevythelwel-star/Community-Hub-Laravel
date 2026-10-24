<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Which estate-wide record types an account may browse in bulk, through
 * universal search or the query API.
 *
 * Each type sits behind the gate of the dashboard page that already lists
 * it: residents' contact details are for administrators, the ledger for
 * billing administrators, and the estate's passes and visitors for the
 * security desk. Community alerts are public to any active account. With no
 * account, or an inactive one, nothing is visible.
 */
class EntityAccess
{
    /** @var array<string, string|null> entity key => gate, null meaning any active account */
    private const GATES = [
        'users' => 'manageUsers',
        'gate_passes' => 'manageSecurity',
        'warnings' => null,
        'transactions' => 'manageBilling',
        'visitors' => 'manageSecurity',
    ];

    /** @return list<string> */
    public static function visibleTo(?User $user): array
    {
        if (! $user || ! $user->isActive()) {
            return [];
        }

        $gate = Gate::forUser($user);

        return array_keys(array_filter(
            self::GATES,
            fn (?string $ability): bool => $ability === null || $gate->allows($ability),
        ));
    }

    public static function allows(?User $user, string $entity): bool
    {
        return in_array($entity, self::visibleTo($user), true);
    }
}
