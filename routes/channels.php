<?php

use App\Models\GatePass;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Estate-wide security alerts for every active account. A private channel:
// these rules are only consulted for private and presence channels.
Broadcast::channel('community-alerts', function (User $user) {
    return $user->isActive();
});

// Private channel for user-specific real-time notifications (e.g. visitor arrival notices)
// Every rule checks isActive() itself: /broadcasting/auth does not run the
// `active` middleware, so a deactivated account's session could otherwise
// keep subscribing until it next loads a dashboard page.
Broadcast::channel('users.{id}', function (User $user, $id) {
    return $user->isActive() && (int) $user->id === (int) $id;
});

// Private channel for security gate personnel
Broadcast::channel('gate.{gateId}', function (User $user, $gateId) {
    return $user->isActive() && ($user->role->isSecurity() || $user->role->isAdministrative());
});

// Gate traffic: pass status changes and visitor check-ins, for gate staff.
Broadcast::channel('gatehouse-stream', function (User $user) {
    return $user->isActive() && ($user->role->isSecurity() || $user->role->isAdministrative());
});

// Live dashboard telemetry, for administrators.
Broadcast::channel('dashboard-telemetry', function (User $user) {
    return $user->isActive() && $user->role->isAdministrative();
});

// Private channel for pass-specific real-time status tracking
Broadcast::channel('passes.{passId}', function (User $user, $passId) {
    if (! $user->isActive()) {
        return false;
    }

    if ($user->role->isAdministrative() || $user->role->isSecurity()) {
        return true;
    }

    return GatePass::where('id', $passId)
        ->where('user_id', $user->id)
        ->exists();
});

// Presence channel for real-time community chat and collaboration rooms
Broadcast::channel('chat.room.{roomId}', function (User $user, $roomId) {
    if ($user->isActive()) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role instanceof BackedEnum ? $user->role->value : (string) $user->role,
            'avatar_url' => $user->avatar_url,
        ];
    }

    return false;
});

// Presence channel for operations command center
Broadcast::channel('operations-center', function (User $user) {
    if ($user->isActive() && ($user->role->isAdministrative() || $user->role->isSecurity())) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role instanceof BackedEnum ? $user->role->value : (string) $user->role,
            'title' => $user->title ?? 'Operator',
        ];
    }

    return false;
});

// Highly sensitive resident distress events are visible to responders only.
Broadcast::channel('security-dispatch', function (User $user) {
    return $user->isActive() && ($user->role->isAdministrative() || $user->role->isSecurity());
});
