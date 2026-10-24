<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveNotificationEvent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public string $priority = 'normal',
        public string $category = 'general',
        public int $unreadCount = 1,
        public ?int $notificationId = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("users.{$this->userId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.received';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificationId,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'priority' => $this->priority,
            'category' => $this->category,
            'unread_count' => $this->unreadCount,
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
