<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Events\Realtime\OperationsCommandCenterEvent;
use App\Events\Realtime\ResidentPanicAlertEvent;
use App\Http\Controllers\Controller;
use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResidentEmergencyController extends Controller
{
    /**
     * Dispatch an emergency resident SOS panic alert in real-time.
     */
    public function panic(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('triggerEmergencyPanic')) {
            abort(403, 'Unauthorized to trigger emergency panic alerts.');
        }

        $validated = $request->validate([
            'emergency_type' => ['required', 'string', 'in:medical,security,fire,general'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $alertId = sprintf('SOS-%s-%s', date('Ymd'), strtoupper(bin2hex(random_bytes(3))));
        $location = $user->lot ? sprintf('%s, %s', $user->lot, $user->street ?? 'Community') : ($user->street ?? 'Main Community Premises');

        // Broadcast dedicated Resident Panic Event
        ResidentPanicAlertEvent::dispatch(
            alertId: $alertId,
            residentId: $user->id,
            residentName: $user->name,
            lot: $location,
            phone: $user->phone,
            emergencyType: $validated['emergency_type'],
            latitude: isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            longitude: isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            notes: $validated['notes'] ?? null,
            metadata: [
                'role' => $user->role->value,
                'email' => $user->email,
            ]
        );

        // Broadcast to Command Center & Operations Dashboard
        OperationsCommandCenterEvent::dispatch(
            alertId: $alertId,
            type: 'emergency',
            severity: 'critical',
            headline: sprintf('🚨 RESIDENT SOS: %s ALERT (%s)', strtoupper($validated['emergency_type']), $user->name),
            location: $location,
            operatorName: $user->name,
            details: [
                'resident_id' => $user->id,
                'resident_phone' => $user->phone,
                'emergency_type' => $validated['emergency_type'],
                'notes' => $validated['notes'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]
        );

        // Notify active security officers & administrators
        $responders = User::query()
            ->where(function ($q) {
                $q->where('role', UserRole::Security->value)
                    ->orWhere('role', UserRole::Admin->value)
                    ->orWhere('role', UserRole::SystemAdmin->value);
            })
            ->where('status', 'Active')
            ->get();

        foreach ($responders as $responder) {
            InAppNotification::create([
                'user_id' => $responder->id,
                'tenant_id' => $user->tenant_id ?? null,
                'category' => 'emergency',
                'title' => sprintf('🚨 [CRITICAL SOS] %s Alert - %s', strtoupper($validated['emergency_type']), $user->name),
                'body' => sprintf(
                    'Resident %s (%s) triggered an SOS panic at %s. Contact: %s. %s',
                    $user->name,
                    $user->role->value,
                    $location,
                    $user->phone ?? 'No phone listed',
                    ! empty($validated['notes']) ? 'Notes: '.$validated['notes'] : ''
                ),
                'action_url' => '/dashboard/command-center',
                'priority' => 'critical',
                'data' => [
                    'alert_id' => $alertId,
                    'resident_id' => $user->id,
                    'location' => $location,
                    'emergency_type' => $validated['emergency_type'],
                ],
            ]);
        }

        Log::channel('security')->critical('Resident emergency panic triggered', [
            'alert_id' => $alertId,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'location' => $location,
            'type' => $validated['emergency_type'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            // Keep the resident-side response intentionally generic so it
            // doesn't expose distress state on a screen someone else may see.
            'message' => 'Your request has been received.',
        ]);
    }
}
