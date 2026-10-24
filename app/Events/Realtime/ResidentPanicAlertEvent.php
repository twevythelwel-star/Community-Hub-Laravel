<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResidentPanicAlertEvent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $alertId,
        public readonly int $residentId,
        public readonly string $residentName,
        public readonly ?string $lot,
        public readonly ?string $phone,
        public readonly string $emergencyType, // 'medical', 'security', 'fire', 'general'
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?string $notes = null,
        public readonly array $metadata = []
    ) {}

    public function broadcastOn(): array
    {
        return [
            // Not the public community-alerts channel: this carries the resident's
            // phone, email and location.
            new PrivateChannel('security-dispatch'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'resident.panic-alert';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'alert_id' => $this->alertId,
            'resident_id' => $this->residentId,
            'resident_name' => $this->residentName,
            'lot' => $this->lot,
            'phone' => $this->phone,
            'emergency_type' => $this->emergencyType,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
