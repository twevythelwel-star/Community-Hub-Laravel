<?php

namespace App\Services\Safety;

use App\Models\DistressAlert;
use App\Models\DistressAlertEvent;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

class DistressEscalationService
{
    public function __construct(
        private readonly DistressDispatchNotifier $notifier,
        private readonly DistressAccessContextResolver $contextResolver,
        private readonly SmsService $sms,
    ) {}

    public function acknowledge(DistressAlert $alert, User $responder): void
    {
        if ($alert->status !== 'open') {
            return;
        }

        $alert->forceFill([
            'status' => 'acknowledged',
            'severity_level' => $alert->severity_level === DistressAlert::SEVERITY_ASSISTANCE
                ? DistressAlert::SEVERITY_URGENT_ASSISTANCE
                : $alert->severity_level,
            'acknowledged_by' => $responder->id,
            'acknowledged_at' => now(),
        ])->save();

        $this->notifier->recordAudit($alert, DistressAlertEvent::ACKNOWLEDGED, $responder, [
            'acknowledgedAt' => $alert->acknowledged_at?->toIso8601String(),
            'responder' => $responder->display_name,
        ]);
    }

    public function resolve(DistressAlert $alert, User $responder, string $outcome, ?string $notes): void
    {
        $alert->forceFill([
            'status' => $outcome === 'false_alarm' ? 'false_alarm' : 'resolved',
            'acknowledged_by' => $alert->acknowledged_by ?? $responder->id,
            'acknowledged_at' => $alert->acknowledged_at ?? now(),
            'resolved_by' => $responder->id,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ])->save();

        $this->notifier->recordAudit($alert, DistressAlertEvent::CLOSED, $responder, [
            'closedAt' => $alert->resolved_at?->toIso8601String(),
            'outcome' => $alert->status,
            'responder' => $responder->display_name,
        ]);
    }

    /**
     * Escalate an alert to Level 2 (Security Escalation).
     * Security confirms and begins the emergency procedure.
     */
    public function escalateToSecurity(DistressAlert $alert, User $responder, ?string $notes = null): void
    {
        if (! $alert->canEscalateToSecurity()) {
            throw new \DomainException('Alert cannot be escalated to security. Current level: '.$alert->escalation_level);
        }

        $alert->forceFill([
            'escalation_level' => DistressAlert::ESCALATION_SECURITY,
            'escalated_at' => now(),
            'escalated_by' => $responder->id,
            'notes' => trim(($alert->notes ? $alert->notes."\n" : '').($notes ? 'Escalation note: '.$notes : '')),
        ])->save();

        $this->notifier->recordAudit($alert, DistressAlertEvent::RESPONSE_INITIATED, $responder, [
            'initiatedAt' => $alert->escalated_at?->toIso8601String(),
            'responder' => $responder->display_name,
        ]);

        Log::channel('security')->warning('Distress alert escalated to security', [
            'alert_id' => $alert->id,
            'escalated_by' => $responder->id,
            'escalation_level' => $alert->escalation_level,
        ]);
    }

    /**
     * Escalate an alert that remained unacknowledged past its acknowledgment deadline.
     */
    public function escalateOnAcknowledgmentTimeout(DistressAlert $alert, ?string $reason = null): void
    {
        if ($alert->status !== 'open') {
            return;
        }

        if ($alert->escalation_level !== DistressAlert::ESCALATION_SILENT_ALERT && $alert->escalation_level !== null) {
            return;
        }

        $reason = $reason ?? 'Automatic escalation: Alert remained unacknowledged past deadline.';

        $alert->forceFill([
            'escalation_level' => DistressAlert::ESCALATION_SECURITY,
            'severity_level' => DistressAlert::SEVERITY_SILENT_EMERGENCY,
            'escalated_at' => now(),
            'notes' => trim(($alert->notes ? $alert->notes."\n" : '').'[TIMEOUT ESCALATION] '.$reason),
        ])->save();

        $this->notifier->recordAudit($alert, DistressAlertEvent::ACKNOWLEDGMENT_TIMEOUT_ESCALATED, null, [
            'escalatedAt' => $alert->escalated_at?->toIso8601String(),
            'reason' => $reason,
            'triggeredAt' => $alert->triggered_at?->toIso8601String(),
            'deadlineAt' => $alert->acknowledgment_deadline_at?->toIso8601String(),
            'timeoutSeconds' => (int) config('safety.acknowledgment_timeout_seconds', 60),
        ]);

        $this->notifier->broadcast($alert);

        Log::channel('security')->critical('Distress alert automatically escalated due to unacknowledged timeout', [
            'alert_id' => $alert->id,
            'incident_id' => $alert->incident_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Scan and auto-escalate all unacknowledged open alerts exceeding their acknowledgment deadlines.
     */
    public function escalateUnacknowledgedAlerts(): int
    {
        $timeoutSeconds = (int) config('safety.acknowledgment_timeout_seconds', 60);
        $threshold = now()->subSeconds($timeoutSeconds);

        $alerts = DistressAlert::query()
            ->where('status', 'open')
            ->where(function ($q) {
                $q->whereNull('escalation_level')
                    ->orWhere('escalation_level', DistressAlert::ESCALATION_SILENT_ALERT);
            })
            ->where(function ($q) use ($threshold) {
                $q->where('acknowledgment_deadline_at', '<=', now())
                    ->orWhere(function ($sub) use ($threshold) {
                        $sub->whereNull('acknowledgment_deadline_at')
                            ->where('triggered_at', '<=', $threshold);
                    });
            })
            ->get();

        $count = 0;
        foreach ($alerts as $alert) {
            $this->escalateOnAcknowledgmentTimeout($alert);
            $count++;
        }

        return $count;
    }

    /**
     * Escalate an alert to Level 3 (Emergency Services).
     * Only eligible assistance types (police, medical, fire) can be escalated.
     * Requires explicit confirmation from authorized security personnel.
     */
    public function escalateToEmergencyServices(DistressAlert $alert, User $responder, string $confirmedService, ?string $notes = null): void
    {
        if (! config('safety.emergency_services_enabled', false)) {
            throw new \DomainException('Emergency services escalation is disabled for this community.');
        }

        if (! $alert->canEscalateToEmergencyServices()) {
            throw new \DomainException('Alert cannot be escalated to emergency services. Current level: '.$alert->escalation_level);
        }

        if (! $alert->isEmergencyServicesEligible()) {
            throw new \DomainException('This alert type is not eligible for emergency services escalation. Type: '.$alert->assistance_type);
        }

        if (! in_array($confirmedService, ['police', 'medical', 'fire'], true)) {
            throw new \DomainException('Invalid emergency service. Must be: police, medical, or fire');
        }

        $alert->forceFill([
            'escalation_level' => DistressAlert::ESCALATION_EMERGENCY_SERVICES,
            'severity_level' => DistressAlert::SEVERITY_EMERGENCY_SERVICES,
            'escalated_at' => now(),
            'escalated_by' => $responder->id,
            'notes' => trim(($alert->notes ? $alert->notes."\n" : '').sprintf('Emergency services escalation: %s. %s', $confirmedService, $notes ? 'Note: '.$notes : '')),
        ])->save();

        $this->notifier->recordAudit($alert, DistressAlertEvent::EMERGENCY_SERVICES_ESCALATED, $responder, [
            'escalatedAt' => $alert->escalated_at?->toIso8601String(),
            'service' => $confirmedService,
            'responder' => $responder->display_name,
            'callingRemainsManual' => true,
        ]);

        Log::channel('security')->critical('Distress alert escalated to emergency services', [
            'alert_id' => $alert->id,
            'escalated_by' => $responder->id,
            'escalation_level' => $alert->escalation_level,
            'emergency_service' => $confirmedService,
        ]);

        $this->notifyEmergencyContacts($alert, $confirmedService);
    }

    /**
     * Notify configured emergency contacts when escalation to emergency services occurs.
     */
    public function notifyEmergencyContacts(DistressAlert $alert, string $service): void
    {
        if (! config('safety.emergency_services_enabled', false)) {
            return;
        }

        if (! $alert->user_id || ! $alert->safetyProfile?->allow_contact_sharing) {
            return;
        }

        $user = $alert->user;
        if (! $user) {
            return;
        }
        $profile = $this->contextResolver->profileFor($user);

        if (! $profile->emergency_contact_phone || ! $this->sms->isConfigured()) {
            return;
        }

        rescue(fn () => $this->sms->send(
            $profile->emergency_contact_phone,
            sprintf('EMERGENCY: %s has requested %s assistance. Estate security has escalated to emergency services. Location: %s', $user->display_name, $service, $user->propertyLabel())
        ), report: true);
    }
}
