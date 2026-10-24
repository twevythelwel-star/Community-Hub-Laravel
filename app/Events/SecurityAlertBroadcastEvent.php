<?php

namespace App\Events;

use App\Models\Warning;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityAlertBroadcastEvent implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;

    public function __construct(public Warning $warning)
    {
        $this->payload = [
            'id' => $warning->id,
            'title' => $warning->title,
            'description' => $warning->description,
            'severity' => $warning->severity ?? 'high',
            'created_at' => $warning->created_at->toIso8601String(),
        ];
    }

    public function broadcastOn(): array
    {
        // Private: a public channel never runs the auth rule in routes/channels.php,
        // so anyone holding a websocket connection, signed in or not, received it.
        return [
            new PrivateChannel('community-alerts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'security.alert';
    }
}
