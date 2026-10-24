<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DashboardTelemetryUpdatedEvent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $telemetry
    ) {
        $this->telemetry['timestamp'] = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard-telemetry'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dashboard.telemetry-updated';
    }

    public function broadcastWith(): array
    {
        return $this->telemetry;
    }
}
