<?php

namespace App\Enums;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Scopes granted to a Sanctum personal access token. A stolen or misused
 * mobile/scanner token is limited to these abilities even when the owning
 * user's role would permit more through the Gate checks in
 * AuthServiceProvider — the token is a narrower credential than the account.
 */
enum TokenAbility: string
{
    case GatePassRead = 'gate-pass:read';
    case GatePassScan = 'gate-pass:scan';
    case VisitorsManage = 'visitors:manage';
    case SecurityManage = 'security:manage';
    case MapRead = 'map:read';

    /** Abilities for a device token issued to $user, based on what their account is allowed to do. */
    public static function forUser(User $user): array
    {
        $abilities = [self::GatePassRead, self::VisitorsManage, self::MapRead];

        if (Gate::forUser($user)->allows('scanPasses')) {
            $abilities[] = self::GatePassScan;
        }

        if (Gate::forUser($user)->allows('manageSecurity')) {
            $abilities[] = self::SecurityManage;
        }

        return array_map(fn (self $ability) => $ability->value, $abilities);
    }
}
