<?php

namespace App\Http\Controllers\Api;

use App\Actions\GatePass\SyncOfflineScansAction;
use App\Enums\PassStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\GateDevice\GateDeviceHeartbeatRequest;
use App\Http\Requests\GateDevice\GateDeviceSensorEventRequest;
use App\Http\Requests\GateDevice\GateDeviceSyncScansRequest;
use App\Models\GateDevice;
use App\Models\GatePass;
use App\Services\GateTailgatingDetectionService;
use Illuminate\Http\JsonResponse;

class GateDeviceApiController extends Controller
{
    /**
     * Report heartbeat telemetry from physical gate scanner/turnstile.
     */
    public function heartbeat(GateDeviceHeartbeatRequest $request): JsonResponse
    {
        /** @var GateDevice $device */
        $device = $request->attributes->get('gate_device');

        $device->updateQuietly([
            'last_heartbeat_at' => now(),
            'firmware_version' => $request->validated('firmware_version', $device->firmware_version),
            'metadata' => array_merge($device->metadata ?? [], $request->validated('telemetry', [])),
        ]);

        return response()->json([
            'success' => true,
            'device_identifier' => $device->device_identifier,
            'gate_id' => $device->gate_id,
            'status' => $device->status,
            'server_time' => now()->toIso8601String(),
            'active_credentials_count' => GatePass::whereIn('status', [PassStatus::Active, PassStatus::CheckedIn])->count(),
        ]);
    }

    /**
     * Ingest offline scans queued by an authenticated hardware scanner.
     */
    public function syncScans(GateDeviceSyncScansRequest $request, SyncOfflineScansAction $action): JsonResponse
    {
        /** @var GateDevice $device */
        $device = $request->attributes->get('gate_device');

        $result = $action->execute(
            $request->validated('scans'),
            $device->gate_id,
            null,
            $device
        );

        return response()->json($result);
    }

    /**
     * Record one barrier cycle counted by the gate's occupancy sensor.
     */
    public function sensorEvent(GateDeviceSensorEventRequest $request, GateTailgatingDetectionService $tailgating): JsonResponse
    {
        /** @var GateDevice $device */
        $device = $request->attributes->get('gate_device');

        $event = $tailgating->recordDeviceReading($request->validated(), $device);

        return response()->json([
            'success' => true,
            'event_id' => $event->id,
            'gate' => $event->gate,
            'is_tailgating' => $event->is_tailgating,
            'severity' => $event->severity,
        ], 201);
    }

    /**
     * Download revoked credentials blacklist for device offline cache.
     */
    public function revocations(): JsonResponse
    {
        $revoked = GatePass::query()
            ->where('status', PassStatus::Revoked)
            ->where('updated_at', '>=', now()->subDays(30))
            ->pluck('pass_id');

        return response()->json([
            'success' => true,
            'revoked_count' => $revoked->count(),
            'revoked_pass_ids' => $revoked,
            'synced_at' => now()->toIso8601String(),
        ]);
    }
}
