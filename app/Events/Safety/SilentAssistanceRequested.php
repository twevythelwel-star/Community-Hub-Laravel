<?php

namespace App\Events\Safety;

use App\Models\DistressAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to security and administrators only (`security-dispatch` is
 * authorised for Security and administrators). Carries what a responder needs
 * and nothing more: the resident, where, which help, and how it was raised.
 */
class SilentAssistanceRequested implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DistressAlert $alert) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('security-dispatch')];
    }

    public function broadcastAs(): string
    {
        return 'silent-assistance.requested';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $user = $this->alert->user;
        $visitor = $this->alert->visitor;

        return [
            'alertId' => $this->alert->id,
            'incidentId' => $this->alert->incident_id,
            'resident' => $user?->display_name ?? $visitor?->name ?? $this->alert->gatePass?->holder_name,
            'property' => $user?->propertyLabel() ?? $this->alert->gatePass?->property ?? $visitor?->property?->label() ?? $visitor?->homeowner?->propertyLabel(),
            'assistance' => $this->alert->assistance_type,
            'severity' => $this->alert->severity_level,
            'severityLabel' => $this->alert->severityLabel(),
            'channel' => $this->alert->channel,
            'channelLabel' => $this->alert->channelLabel(),
            'gate' => $this->alert->gate
                ? config('gatepass.gates.'.$this->alert->gate, $this->alert->gate)
                : null,
            'event' => $this->alert->event_type,
            'eventAt' => $this->alert->event_at?->toIso8601String(),
            'credentialReference' => $this->alert->credential_reference,
            'vehicles' => $this->alert->vehicle_context ?? [],
            'status' => $this->alert->status === 'acknowledged' ? 'SECURITY RESPONDING' : 'SECURITY ALERTED',
            'triggeredAt' => $this->alert->triggered_at->toIso8601String(),
        ];
    }
}
