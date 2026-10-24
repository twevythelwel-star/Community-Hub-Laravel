<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Realtime\RealtimeBroadcasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeApiController extends Controller
{
    public function __construct(
        protected RealtimeBroadcasterService $realtime
    ) {}

    /**
     * Get WebSocket & Echo connection parameters.
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Real-time WebSocket configuration retrieved successfully.',
            'data' => $this->realtime->getEchoConfig(),
        ]);
    }

    /**
     * Get catalog of real-time channels, event signatures, and presence rooms.
     */
    public function channels(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Real-time channel catalog retrieved successfully.',
            'data' => [
                'infrastructure' => 'Laravel Reverb (WebSocket Server)',
                'client_library' => 'Laravel Echo + Pusher JS',
                'channels' => [
                    [
                        'name' => 'community-chat-stream',
                        'type' => 'public',
                        'events' => ['chat.message'],
                        'use_case' => 'Estate-wide chat and incident bulletin stream',
                    ],
                    [
                        'name' => 'chat.room.{roomId}',
                        'type' => 'presence',
                        'events' => ['chat.message', 'whisper:typing'],
                        'use_case' => 'Room collaboration and active participant presence',
                    ],
                    [
                        'name' => 'dashboard-telemetry',
                        'type' => 'public',
                        'events' => ['dashboard.telemetry-updated'],
                        'use_case' => 'Live counters and operational KPI widgets',
                    ],
                    [
                        'name' => 'users.{id}',
                        'type' => 'private',
                        'events' => ['notification.received', 'gatepass.status-updated'],
                        'use_case' => 'User-specific real-time toast alerts and pass notifications',
                    ],
                    [
                        'name' => 'gatehouse-stream',
                        'type' => 'public',
                        'events' => ['gatepass.status-updated', 'visitor.checked-in'],
                        'use_case' => 'Gatehouse traffic feed and automated barrier clearance',
                    ],
                    [
                        'name' => 'operations-center',
                        'type' => 'presence',
                        'events' => ['operations.command-event'],
                        'use_case' => 'Command center operator presence and security monitor dispatch',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Dispatch a test or production real-time broadcast event.
     *
     * Chat is any active account's, posting as itself. The rest speak for
     * the estate — a security alert, a pass changing state, the dashboard's
     * figures — so they are the security desk's (`manageSecurity`), and a
     * notification pushed to another user is an administrator's
     * (`broadcastNotices`).
     */
    public function broadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:chat,telemetry,notification,pass,operations'],
            'room_id' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'pass_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:40'],
            'severity' => ['nullable', 'string', 'in:info,warning,critical'],
            'headline' => ['nullable', 'string', 'max:160'],
            'telemetry' => ['nullable', 'array'],
        ]);

        $ability = match ($validated['type']) {
            'chat' => null,
            'notification' => 'broadcastNotices',
            default => 'manageSecurity',
        };

        if ($ability !== null) {
            $this->authorize($ability);
        }

        $currentUser = $request->user();

        switch ($validated['type']) {
            case 'chat':
                $event = $this->realtime->broadcastChatMessage(
                    roomId: $validated['room_id'] ?? 'general',
                    user: $currentUser,
                    message: $validated['message'] ?? 'Real-time broadcast test message'
                );
                break;

            case 'telemetry':
                $event = $this->realtime->broadcastDashboardTelemetry(
                    telemetry: $validated['telemetry'] ?? [
                        'active_visitors_count' => rand(12, 38),
                        'active_passes_count' => rand(40, 95),
                        'system_health' => 'optimal',
                        'gate_clearance_seconds' => rand(15, 30),
                    ]
                );
                break;

            case 'notification':
                $event = $this->realtime->broadcastLiveNotification(
                    userId: $validated['user_id'] ?? $currentUser->id,
                    title: $validated['title'] ?? 'Real-time Alert',
                    body: $validated['body'] ?? 'Live notification dispatched via Laravel Reverb.'
                );
                break;

            case 'pass':
                $event = $this->realtime->broadcastGatePassUpdate(
                    passId: $validated['pass_id'] ?? 101,
                    passCode: 'GP-'.rand(1000, 9999),
                    status: $validated['status'] ?? 'scanned',
                    visitorName: 'Visitor Demo',
                    gate: 'Main Gate 1',
                    ownerId: $currentUser->id
                );
                break;

            case 'operations':
            default:
                $event = $this->realtime->broadcastOperationsAlert(
                    type: 'perimeter_monitoring',
                    severity: $validated['severity'] ?? 'warning',
                    headline: $validated['headline'] ?? 'Sensor Alert: North Sector Perimeter Activity',
                    location: 'North Zone Gate 3',
                    operatorName: $currentUser->name
                );
                break;
        }

        return response()->json([
            'success' => true,
            'message' => 'Real-time event successfully broadcasted to WebSocket channels.',
            'event' => class_basename($event),
            'channels' => array_map(fn ($ch) => (string) $ch, $event->broadcastOn()),
            'payload' => method_exists($event, 'broadcastWith') ? $event->broadcastWith() : null,
        ]);
    }
}
