<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GatePassStatusUpdatedEvent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $passId,
        public string $passCode,
        public string $status, // active, scanned, cleared, expired, revoked
        public ?string $visitorName = null,
        public ?string $gate = null,
        public ?int $ownerId = null,
        public array $metadata = []
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            // Gate staff only: visitor names and pass codes were on a public stream.
            new PrivateChannel('gatehouse-stream'),
            new PrivateChannel("passes.{$this->passId}"),
        ];

        if ($this->ownerId) {
            $channels[] = new PrivateChannel("users.{$this->ownerId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'gatepass.status-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'pass_id' => $this->passId,
            'pass_code' => $this->passCode,
            'status' => $this->status,
            'visitor_name' => $this->visitorName,
            'gate' => $this->gate,
            'occurred_at' => now()->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
