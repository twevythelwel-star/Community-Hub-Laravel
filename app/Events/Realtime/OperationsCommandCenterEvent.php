<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OperationsCommandCenterEvent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $alertId,
        public string $type, // gate_traffic, perimeter_sensor, staff_dispatch, emergency
        public string $severity, // info, warning, critical
        public string $headline,
        public string $location,
        public string $operatorName,
        public array $details = []
    ) {}

    public function broadcastOn(): array
    {
        // Operations only. It also went to the public community-alerts channel,
        // which handed anyone listening a resident's phone number and location
        // from an SOS, and every gate's traffic.
        return [
            new PresenceChannel('operations-center'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'operations.command-event';
    }

    public function broadcastWith(): array
    {
        return [
            'alert_id' => $this->alertId,
            'type' => $this->type,
            'severity' => $this->severity,
            'headline' => $this->headline,
            'location' => $this->location,
            'operator' => $this->operatorName,
            'details' => $this->details,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
