<?php

namespace App\Services\Safety;

use App\Enums\GateId;
use App\Enums\UserRole;
use App\Events\Safety\SilentAssistanceRequested;
use App\Models\DistressAlert;
use App\Models\DistressAlertEvent;
use App\Models\InAppNotification;
use App\Models\SafetyProfile;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

class DistressDispatchNotifier
{
    public function __construct(private readonly SmsService $sms) {}

    public function recordAudit(
        DistressAlert $alert,
        string $eventType,
        ?User $actor = null,
        array $snapshot = [],
    ): DistressAlertEvent {
        return DistressAlertEvent::create([
            'incident_id' => $alert->incident_id,
            'distress_alert_id' => $alert->id,
            'user_id' => $alert->user_id,
            'safety_profile_id' => $alert->safety_profile_id,
            'visitor_id' => $alert->visitor_id,
            'actor_id' => $actor?->id,
            'event_type' => $eventType,
            'occurred_at' => now(),
            'snapshot' => $snapshot,
        ]);
    }

    /** A dead websocket server must not lose the alert: it is already saved and notified. */
    public function broadcast(DistressAlert $alert): void
    {
        rescue(fn () => SilentAssistanceRequested::dispatch($alert->loadMissing('user')), report: true);
    }

    /** Persist a fixed snapshot so later profile or alert edits cannot rewrite the timeline. */
    public function incidentSnapshot(DistressAlert $alert): array
    {
        $user = $alert->user;
        $visitor = $alert->visitor;
        $direction = match ($alert->event_type) {
            'entry_attempt' => 'ENTERING',
            'exit_attempt' => 'EXITING',
            default => null,
        };

        $repAppointment = $user?->activeAuthorizedAppointments()->with('ownershipEntity')->first();
        $ownerEntity = $repAppointment?->ownershipEntity;

        return [
            'resident' => $user?->display_name ?? $visitor?->name ?? $alert->gatePass?->holder_name,
            'person' => $user?->display_name ?? $visitor?->name ?? $alert->gatePass?->holder_name,
            'capacity' => $repAppointment ? 'Authorized Representative' : ($user ? $user->role->label() : 'Visitor'),
            'representativeRole' => $repAppointment?->role?->label(),
            'ownerEntity' => $ownerEntity?->legal_name,
            'ownerEntityType' => $ownerEntity?->entity_type?->label(),
            'property' => $alert->propertyLabel() ?? $user?->propertyLabel() ?? $alert->gatePass?->property ?? $visitor?->property?->label() ?? $visitor?->homeowner?->propertyLabel(),
            'credential' => $alert->credential_reference
                ? 'Verified'
                : $this->determineCredentialStatus($alert->channel),
            'credentialReference' => $alert->credential_reference,
            'gate' => $alert->gate
                ? config('gatepass.gates.'.$alert->gate, GateId::tryFrom($alert->gate)?->label() ?? $alert->gate)
                : null,
            'direction' => $direction,
            'assistance' => ucfirst($alert->assistance_type),
            'status' => in_array($alert->status, ['open', 'acknowledged'], true) ? 'ACTIVE' : ucfirst($alert->status),
            'activatedAt' => $alert->triggered_at?->toIso8601String(),
        ];
    }

    /** Determine priority level based on assistance type. */
    public function determinePriority(string $severity): string
    {
        return match ($severity) {
            DistressAlert::SEVERITY_URGENT_ASSISTANCE => 'high',
            DistressAlert::SEVERITY_SILENT_EMERGENCY, DistressAlert::SEVERITY_EMERGENCY_SERVICES => 'urgent',
            default => 'normal',
        };
    }

    /** Determine the current event description based on channel and gate. */
    public function determineCurrentEvent(string $channel, ?string $gate): string
    {
        if ($gate) {
            return 'Community Entry';
        }

        return match ($channel) {
            'app' => 'In-App Request',
            'credential' => 'Credential Assistance Requested',
            'qr_gesture' => 'QR Gesture Triggered',
            'qr_scan' => 'Credential Scanned',
            'duress_pin' => 'Duress PIN Entered',
            default => 'Assistance Requested',
        };
    }

    /** Determine credential status based on channel. */
    public function determineCredentialStatus(string $channel): string
    {
        return match ($channel) {
            'qr_gesture' => 'Armed; gate scan pending',
            'qr_scan', 'duress_pin' => 'Verified',
            'app' => 'App-Based',
            default => 'Unknown',
        };
    }

    /** Format a structured incident report for security personnel. */
    public function formatIncidentReport(DistressAlert $alert, User $user, string $priority): array
    {
        $gateLabel = $alert->gate
            ? config('gatepass.gates.'.$alert->gate, GateId::tryFrom($alert->gate)?->label() ?? $alert->gate)
            : 'Unknown';
        $currentEvent = $this->determineCurrentEvent($alert->channel, $alert->gate);
        $credentialStatus = $alert->credential_reference
            ? 'Verified'
            : $this->determineCredentialStatus($alert->channel);

        if (! $user) {
            $holder = $alert->visitor?->name ?? 'Credential holder';
            $report = sprintf(
                "DISCREET CREDENTIAL ASSISTANCE\nIncident ID: %s\n\nCredential holder: %s\nProperty: %s\nGate: %s\nCredential: %s\nAssistance: %s\nStatus: SECURITY ALERTED",
                $alert->incident_id,
                $holder,
                $alert->propertyLabel() ?? 'Unknown',
                $gateLabel,
                $credentialStatus,
                ucfirst(str_replace('_', ' ', $alert->assistance_type)),
            );

            return [
                'title' => sprintf('%s - %s ASSISTANCE', strtoupper($alert->severityLabel()), strtoupper($alert->assistance_type)),
                'body' => $report,
                'priority' => $priority,
            ];
        }

        $title = sprintf('%s - %s ASSISTANCE', strtoupper($alert->severityLabel()), strtoupper($alert->assistance_type));

        $repAppointment = $user->activeAuthorizedAppointments()->with('ownershipEntity')->first();
        $isEntityRep = $repAppointment && $repAppointment->ownershipEntity;
        $ownerEntity = $isEntityRep ? $repAppointment->ownershipEntity : null;
        $capacity = $isEntityRep ? 'Authorized Representative' : $user->role->label();
        $ownerEntityName = $ownerEntity?->legal_name;

        if ($isEntityRep) {
            $body = sprintf(
                "DISCREET ASSISTANCE REQUEST\n".
                "Incident ID: %s\n\n".
                "Person: %s\n".
                "Capacity: %s\n".
                "Representative Role: %s\n".
                "Owner Entity: %s\n".
                "Property: %s\n".
                "Current event: %s\n".
                "Gate: %s\n".
                "Credential: %s\n".
                "Assistance: %s\n".
                "Status: %s\n".
                "Severity: %s\n".
                "Priority: %s\n".
                "Escalation Level: %s\n\n".
                "Channel: %s\n".
                'Triggered at: %s',
                $alert->incident_id,
                $user->display_name,
                $capacity,
                $repAppointment->role->label(),
                $ownerEntityName,
                $alert->propertyLabel() ?? $user->propertyLabel(),
                $alert->event_type ? strtoupper(str_replace('_', ' ', $alert->event_type)) : $currentEvent,
                $gateLabel,
                $credentialStatus,
                ucfirst($alert->assistance_type),
                in_array($alert->status, ['open', 'acknowledged'], true) ? 'ACTIVE' : strtoupper(str_replace('_', ' ', $alert->status)),
                strtoupper($alert->severityLabel()),
                strtoupper($priority),
                $alert->escalationLabel(),
                ucfirst(str_replace('_', ' ', $alert->channel)),
                $alert->triggered_at->format('H:i')
            );
        } else {
            $body = sprintf(
                "DISCREET ASSISTANCE REQUEST\n".
                "Incident ID: %s\n\n".
                "Resident: %s\n".
                "Property: %s\n".
                "Current event: %s\n".
                "Gate: %s\n".
                "Credential: %s\n".
                "Assistance: %s\n".
                "Severity: %s\n".
                "Priority: %s\n".
                "Escalation Level: %s\n\n".
                "Channel: %s\n".
                'Triggered at: %s',
                $alert->incident_id,
                $user->display_name,
                $user->propertyLabel(),
                $alert->event_type ? strtoupper(str_replace('_', ' ', $alert->event_type)) : $currentEvent,
                $gateLabel,
                $credentialStatus,
                ucfirst($alert->assistance_type),
                strtoupper($alert->severityLabel()),
                strtoupper($priority),
                $alert->escalationLabel(),
                ucfirst(str_replace('_', ' ', $alert->channel)),
                $alert->triggered_at->format('H:i')
            );
        }
        $body .= "\n".'Event time: '.($alert->event_at?->format('H:i') ?? $alert->triggered_at->format('H:i'));
        if ($alert->credential_reference) {
            $body .= "\n".'Credential reference: '.$alert->credential_reference;
        }
        if ($alert->vehicle_context) {
            $body .= "\n".'Registered vehicles: '.implode('; ', $alert->vehicle_context);
        }
        $body .= "\n".'Status: '.($alert->status === 'acknowledged' ? 'SECURITY RESPONDING' : 'SECURITY ALERTED');

        return [
            'incident_id' => $alert->incident_id,
            'title' => $title,
            'body' => $body,
            'priority' => $priority,
            'incident_type' => $alert->assistance_type,
            'resident_name' => $user->display_name,
            'person' => $user->display_name,
            'capacity' => $capacity,
            'representative_role' => $repAppointment?->role?->label(),
            'owner_entity' => $ownerEntityName,
            'owner_entity_type' => $ownerEntity?->entity_type?->label(),
            'property' => $alert->propertyLabel() ?? $user->propertyLabel(),
            'current_event' => $alert->event_type ? strtoupper(str_replace('_', ' ', $alert->event_type)) : $currentEvent,
            'gate' => $gateLabel,
            'event_at' => $alert->event_at?->toIso8601String(),
            'credential_reference' => $alert->credential_reference,
            'vehicle_context' => $alert->vehicle_context ?? [],
            'credential_status' => $credentialStatus,
            'assistance' => ucfirst($alert->assistance_type),
            'severity' => $alert->severity_level,
            'severity_label' => $alert->severityLabel(),
            'channel' => $alert->channel,
            'escalation_level' => $alert->escalation_level,
            'escalation_label' => $alert->escalationLabel(),
            'status' => in_array($alert->status, ['open', 'acknowledged'], true) ? 'ACTIVE' : $alert->status,
            'status_label' => $alert->status === 'acknowledged' ? 'Security responding' : 'Security alerted',
            'triggered_at' => $alert->triggered_at->toIso8601String(),
        ];
    }

    public function dispatch(DistressAlert $alert, SafetyProfile $profile): void
    {
        $user = $alert->user;
        if (! $user) {
            return;
        }

        $priority = $this->determinePriority($alert->severity_level);
        $incidentReport = $this->formatIncidentReport($alert, $user, $priority);

        $responderIds = User::query()
            ->whereIn('role', [UserRole::Security->value, UserRole::Admin->value, UserRole::SystemAdmin->value])
            ->where('status', 'Active')
            ->when($user, fn ($query) => $query->where('id', '!=', $user->id))
            ->pluck('id')
            ->all();

        $this->recordAudit($alert, DistressAlertEvent::DISPATCH_QUEUED, snapshot: [
            'queuedAt' => now()->toIso8601String(),
            'assistanceType' => $alert->assistance_type,
            'severity' => $alert->severity_level,
            'recipientCount' => count($responderIds),
        ]);

        foreach ($responderIds as $responderId) {
            InAppNotification::create([
                'user_id' => $responderId,
                'category' => 'security',
                'title' => $incidentReport['title'],
                'body' => $incidentReport['body'],
                'action_url' => '/dashboard/silent-assistance',
                'priority' => $priority,
                'data' => [
                    'distress_alert_id' => $alert->id,
                    'incident_id' => $alert->incident_id,
                    'incident_type' => $alert->assistance_type,
                    'severity' => $alert->severity_level,
                    'severity_label' => $alert->severityLabel(),
                    'priority' => $priority,
                    'resident_name' => $user->display_name,
                    'property' => $user->propertyLabel(),
                    'gate' => $alert->gate,
                    'event_type' => $alert->event_type,
                    'event_at' => $alert->event_at?->toIso8601String(),
                    'credential_reference' => $alert->credential_reference,
                    'vehicle_context' => $alert->vehicle_context,
                    'channel' => $alert->channel,
                    'escalation_level' => $alert->escalation_level,
                    'escalation_label' => $alert->escalationLabel(),
                ],
            ]);
        }

        $this->recordAudit($alert, DistressAlertEvent::DISPATCH_SENT, snapshot: [
            'sentAt' => now()->toIso8601String(),
            'recipientCount' => count($responderIds),
            'channels' => ['in_app', 'security_dispatch'],
        ]);

        $this->broadcast($alert);
        $this->recordAudit($alert, DistressAlertEvent::DISPATCH_DELIVERED, snapshot: [
            'deliveredAt' => now()->toIso8601String(),
            'recipientCount' => count($responderIds),
        ]);
        $this->recordAudit($alert, DistressAlertEvent::SECURITY_NOTIFIED, snapshot: [
            'notifiedAt' => now()->toIso8601String(),
            'recipientCount' => count($responderIds),
            'channels' => ['in_app', 'security_dispatch'],
        ]);

        // "Emergency contact" means the resident wants their contact told.
        if ($alert->user_id && $profile->allow_contact_sharing && $alert->assistance_type === 'contact' && $profile->emergency_contact_phone && $this->sms->isConfigured()) {
            rescue(fn () => $this->sms->send(
                $profile->emergency_contact_phone,
                sprintf('%s has asked for you to be contacted through the estate\'s assistance service. Estate security has been alerted.', $user->display_name)
            ), report: true);
        }

        Log::channel('security')->alert('Silent assistance requested', [
            'alert_id' => $alert->id,
            'channel' => $alert->channel,
            'assistance' => $alert->assistance_type,
            'gate' => $alert->gate,
            'event_type' => $alert->event_type,
            'credential_reference' => $alert->credential_reference,
            'vehicle_context' => $alert->vehicle_context,
            'priority' => $priority,
            'incident_report' => $incidentReport,
        ]);
    }

    public function dispatchForCredential(DistressAlert $alert, array $context): void
    {
        $priority = $this->determinePriority($alert->severity_level);
        $incidentReport = sprintf(
            "DISCREET CREDENTIAL ASSISTANCE\nIncident ID: %s\n\nCredential holder: %s (%s)\nProperty: %s\nCredential: %s\nAssistance: %s\nStatus: SECURITY ALERTED",
            $alert->incident_id,
            $context['holderName'] ?? 'Unknown credential holder',
            $context['label'] ?? 'Authorized credential',
            $context['property'] ?? 'Unknown',
            $alert->credential_reference,
            ucfirst(str_replace('_', ' ', $alert->assistance_type)),
        );
        $responderIds = User::query()
            ->whereIn('role', [UserRole::Security->value, UserRole::Admin->value, UserRole::SystemAdmin->value])
            ->where('status', 'Active')
            ->pluck('id');

        $this->recordAudit($alert, DistressAlertEvent::DISPATCH_QUEUED, snapshot: [
            'queuedAt' => now()->toIso8601String(),
            'recipientCount' => $responderIds->count(),
            'channel' => 'credential',
            'assistance' => $alert->assistance_type,
        ]);

        foreach ($responderIds as $responderId) {
            InAppNotification::create([
                'user_id' => $responderId,
                'category' => 'security',
                'title' => sprintf('URGENT - %s ASSISTANCE', strtoupper($alert->assistance_type)),
                'body' => $incidentReport,
                'action_url' => '/dashboard/silent-assistance',
                'priority' => $priority,
                'data' => [
                    'distress_alert_id' => $alert->id,
                    'incident_id' => $alert->incident_id,
                    'credential_reference' => $alert->credential_reference,
                    'holder_type' => $context['holderType'],
                    'assistance' => $alert->assistance_type,
                ],
            ]);
        }

        $this->recordAudit($alert, DistressAlertEvent::DISPATCH_SENT, snapshot: [
            'sentAt' => now()->toIso8601String(),
            'recipientCount' => $responderIds->count(),
            'channels' => ['in_app', 'security_dispatch'],
        ]);

        $this->broadcast($alert);
        $this->recordAudit($alert, DistressAlertEvent::DISPATCH_DELIVERED, snapshot: [
            'deliveredAt' => now()->toIso8601String(),
            'recipientCount' => $responderIds->count(),
        ]);
        $this->recordAudit($alert, DistressAlertEvent::SECURITY_NOTIFIED, snapshot: [
            'notifiedAt' => now()->toIso8601String(),
            'recipientCount' => $responderIds->count(),
            'channels' => ['in_app', 'security_dispatch'],
        ]);

        Log::channel('security')->alert('Credential holder requested assistance', [
            'incident_id' => $alert->incident_id,
            'credential_reference' => $alert->credential_reference,
            'holder_type' => $context['holderType'],
            'assistance' => $alert->assistance_type,
        ]);
    }
}
