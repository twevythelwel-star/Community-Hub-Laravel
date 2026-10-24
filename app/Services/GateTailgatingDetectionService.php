<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Events\Realtime\OperationsCommandCenterEvent;
use App\Models\GateDevice;
use App\Models\GatePass;
use App\Models\GateSensorEvent;
use App\Models\InAppNotification;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class GateTailgatingDetectionService
{
    /**
     * Records one barrier cycle as reported by an authenticated gate device,
     * and raises a tailgating alert when more people passed than the
     * credential allowed.
     *
     * Only what the device reports is stored: the gate is the device's own,
     * and confidence, timing and sensor type are kept as sent or left empty,
     * never filled in.
     *
     * @param  array{direction?: string, pass_id?: ?string, license_plate?: ?string, authorized_occupants?: ?int, detected_occupants: int, sensor_type?: ?string, confidence?: ?float, transit_duration_ms?: ?int, occurred_at?: ?string}  $reading
     */
    public function recordDeviceReading(array $reading, GateDevice $device): GateSensorEvent
    {
        $gate = $device->gate_id;
        $direction = $reading['direction'] ?? 'in';
        $passId = $reading['pass_id'] ?? null;
        $plate = ! empty($reading['license_plate']) ? strtoupper(trim((string) $reading['license_plate'])) : null;
        $occurredAt = ! empty($reading['occurred_at']) ? CarbonImmutable::parse($reading['occurred_at']) : CarbonImmutable::now();

        $authorizedOccupants = max(1, (int) ($reading['authorized_occupants'] ?? 1));
        $detectedOccupants = max(0, (int) $reading['detected_occupants']);

        $gatePass = $passId ? GatePass::where('pass_id', $passId)->first() : null;
        $vehicle = $plate ? Vehicle::where('license_plate', $plate)->first() : null;

        $isTailgating = $detectedOccupants > $authorizedOccupants;
        $severity = 'INFO';
        $alertTitle = null;
        $alertMessage = null;

        if ($isTailgating) {
            $excess = $detectedOccupants - $authorizedOccupants;
            $severity = $excess >= 2 ? 'CRITICAL' : 'WARNING';
            $alertTitle = "Possible tailgating at {$gate}";
            $alertMessage = sprintf(
                'The credential allowed %d person(s), but %s counted %d (%d more) in one barrier cycle.',
                $authorizedOccupants,
                $device->name,
                $detectedOccupants,
                $excess
            );

            Log::channel('security')->warning('Tailgating anomaly flagged', [
                'gate' => $gate,
                'device' => $device->device_identifier,
                'pass_id' => $passId,
                'license_plate' => $plate,
                'authorized' => $authorizedOccupants,
                'detected' => $detectedOccupants,
            ]);
        }

        $event = GateSensorEvent::create([
            'gate_pass_id' => $gatePass?->id,
            'pass_id' => $passId,
            'vehicle_id' => $vehicle?->id,
            'license_plate' => $plate,
            'gate' => $gate,
            'direction' => $direction,
            'sequence_state' => $isTailgating ? 'ALERT' : 'COMPLETED',
            'authorized_occupants' => $authorizedOccupants,
            'detected_occupants' => $detectedOccupants,
            'is_tailgating' => $isTailgating,
            'severity' => $severity,
            'alert_title' => $alertTitle,
            'alert_message' => $alertMessage,
            'sensor_metadata' => array_filter([
                'device_identifier' => $device->device_identifier,
                'device_name' => $device->name,
                'sensor_type' => $reading['sensor_type'] ?? null,
                'confidence' => $reading['confidence'] ?? null,
                'transit_duration_ms' => $reading['transit_duration_ms'] ?? null,
            ], fn ($value) => $value !== null),
            'resolution_status' => $isTailgating ? 'UNRESOLVED' : 'CLEAR',
            'occurred_at' => $occurredAt,
        ]);

        if ($isTailgating) {
            $this->alertSecurity($event, $device);
        }

        return $event;
    }

    /**
     * A device has no one signed in to tell, so every active security officer
     * and administrator is notified, and the operations centre is told live.
     */
    private function alertSecurity(GateSensorEvent $event, GateDevice $device): void
    {
        User::query()
            ->whereIn('role', [UserRole::Security, UserRole::Admin, UserRole::SystemAdmin])
            ->get()
            ->filter(fn (User $user) => $user->isActive())
            ->each(fn (User $user) => InAppNotification::create([
                'user_id' => $user->id,
                'category' => 'security',
                'title' => $event->alert_title,
                'body' => $event->alert_message,
                'action_url' => '/dashboard/gate-scanner',
                'priority' => 'high',
            ]));

        OperationsCommandCenterEvent::dispatch(
            alertId: 'TAILGATE-'.$event->id,
            type: 'perimeter_sensor',
            severity: $event->severity === 'CRITICAL' ? 'critical' : 'warning',
            headline: $event->alert_title,
            location: $event->gate,
            operatorName: "Device: {$device->name}",
            details: [
                'event_id' => $event->id,
                'authorized' => $event->authorized_occupants,
                'detected' => $event->detected_occupants,
                'pass_id' => $event->pass_id,
                'license_plate' => $event->license_plate,
            ],
        );
    }

    /**
     * Get recent sensor & tailgating events.
     *
     * @return Collection<int, GateSensorEvent>
     */
    public function getRecentEvents(int $limit = 10): Collection
    {
        return GateSensorEvent::with(['gatePass', 'vehicle', 'resolvedBy'])
            ->latest('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Resolve or investigate a tailgating anomaly.
     */
    public function resolveEvent(int $eventId, User $guard, string $status, ?string $notes = null): GateSensorEvent
    {
        $event = GateSensorEvent::findOrFail($eventId);

        $event->update([
            'resolution_status' => $status,
            'resolved_by' => $guard->id,
            'resolution_notes' => $notes ?: "Resolution updated by {$guard->name}",
        ]);

        return $event;
    }
}
