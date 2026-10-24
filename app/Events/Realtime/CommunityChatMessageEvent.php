<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommunityChatMessageEvent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $roomId,
        public int $userId,
        public string $userName,
        public string $userRole,
        public string $message,
        public ?string $avatarUrl = null,
        public ?string $sentAt = null
    ) {
        $this->sentAt = $this->sentAt ?? now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            // The room's members only. A public all-rooms stream also carried
            // every message, from every room, to any websocket client.
            new PresenceChannel("chat.room.{$this->roomId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'user_role' => $this->userRole,
            'message' => $this->message,
            'avatar_url' => $this->avatarUrl,
            'sent_at' => $this->sentAt,
        ];
    }
}
