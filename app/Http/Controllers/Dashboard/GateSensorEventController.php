<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\GateTailgatingDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GateSensorEventController extends Controller
{
    public function __construct(
        protected GateTailgatingDetectionService $tailgatingService
    ) {}

    /*
     | Sensor readings arrive from the gate's own hardware, authenticated by
     | its device key (POST /api/gate-devices/sensor-events). Guards review
     | and resolve them here; they no longer type head counts in.
     */

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'exists:gate_sensor_events,id'],
            'status' => ['required', 'string', 'in:CLEAR,INVESTIGATING,CONFIRMED_VIOLATION,DISMISSED'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $event = $this->tailgatingService->resolveEvent(
            (int) $validated['event_id'],
            $request->user(),
            $validated['status'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Sensor event status updated.',
            'event' => $event,
        ]);
    }

    public function recent(): JsonResponse
    {
        $events = $this->tailgatingService->getRecentEvents(15);

        return response()->json([
            'events' => $events,
        ]);
    }
}
