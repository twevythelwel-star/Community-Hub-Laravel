<?php

namespace App\Services\Realtime;

use App\Events\Realtime\CommunityChatMessageEvent;
use App\Events\Realtime\DashboardTelemetryUpdatedEvent;
use App\Events\Realtime\GatePassStatusUpdatedEvent;
use App\Events\Realtime\LiveNotificationEvent;
use App\Events\Realtime\OperationsCommandCenterEvent;
use App\Models\User;
use Illuminate\Support\Str;

class RealtimeBroadcasterService
{
    /**
     * Broadcast a real-time chat message to a community or incident room.
     */
    public function broadcastChatMessage(
        string $roomId,
        User|array $user,
        string $message
    ): CommunityChatMessageEvent {
        $userId = $user instanceof User ? $user->id : ($user['id'] ?? 1);
        $userName = $user instanceof User ? $user->name : ($user['name'] ?? 'Resident');
        $userRole = $user instanceof User ? ($user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role) : ($user['role'] ?? 'Homeowner');
        $avatarUrl = $user instanceof User ? $user->avatar_url : ($user['avatar_url'] ?? null);

        $event = new CommunityChatMessageEvent(
            roomId: $roomId,
            userId: $userId,
            userName: $userName,
            userRole: $userRole,
            message: $message,
            avatarUrl: $avatarUrl
        );

        event($event);

        return $event;
    }

    /**
     * Broadcast live dashboard telemetry and KPI updates.
     */
    public function broadcastDashboardTelemetry(array $telemetry): DashboardTelemetryUpdatedEvent
    {
        $event = new DashboardTelemetryUpdatedEvent($telemetry);
        event($event);

        return $event;
    }

    /**
     * Broadcast an in-app instant notification to a specific user.
     */
    public function broadcastLiveNotification(
        int $userId,
        string $title,
        string $body,
        array $options = []
    ): LiveNotificationEvent {
        $event = new LiveNotificationEvent(
            userId: $userId,
            title: $title,
            body: $body,
            actionUrl: $options['action_url'] ?? null,
            priority: $options['priority'] ?? 'normal',
            category: $options['category'] ?? 'general',
            unreadCount: $options['unread_count'] ?? 1,
            notificationId: $options['notification_id'] ?? null
        );

        event($event);

        return $event;
    }

    /**
     * Broadcast a gate pass status transition (active, scanned, cleared, expired, revoked).
     */
    public function broadcastGatePassUpdate(
        int $passId,
        string $passCode,
        string $status,
        ?string $visitorName = null,
        ?string $gate = null,
        ?int $ownerId = null,
        array $metadata = []
    ): GatePassStatusUpdatedEvent {
        $event = new GatePassStatusUpdatedEvent(
            passId: $passId,
            passCode: $passCode,
            status: $status,
            visitorName: $visitorName,
            gate: $gate,
            ownerId: $ownerId,
            metadata: $metadata
        );

        event($event);

        return $event;
    }

    /**
     * Broadcast an operations center security monitoring alert.
     */
    public function broadcastOperationsAlert(
        string $type,
        string $severity,
        string $headline,
        string $location,
        string $operatorName,
        array $details = []
    ): OperationsCommandCenterEvent {
        $alertId = 'ALT-'.strtoupper(Str::random(8));

        $event = new OperationsCommandCenterEvent(
            alertId: $alertId,
            type: $type,
            severity: $severity,
            headline: $headline,
            location: $location,
            operatorName: $operatorName,
            details: $details
        );

        event($event);

        return $event;
    }

    /**
     * Broadcast an in-app instant notification to a specific user (alias).
     */
    public function broadcastNotification(
        int $userId,
        string $title,
        string $body,
        array $data = [],
        array $options = []
    ): LiveNotificationEvent {
        return $this->broadcastLiveNotification($userId, $title, $body, array_merge($options, ['data' => $data]));
    }

    /**
     * Get frontend Echo connection parameters.
     */
    public function getEchoConfig(): array
    {
        $host = config('broadcasting.connections.reverb.options.host', 'localhost');
        $port = (int) config('broadcasting.connections.reverb.options.port', 8080);
        $scheme = config('broadcasting.connections.reverb.options.scheme', 'http');
        $isTls = $scheme === 'https';

        return [
            'broadcaster' => 'reverb',
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'wsHost' => $host,
            'wsPort' => $port,
            'wssPort' => $port,
            'forceTLS' => $isTls,
            'enabledTransports' => ['ws', 'wss'],
            'transports' => ['ws', 'wss'],
            'cluster' => 'mt1',
        ];
    }
}
