<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\GateId;
use App\Http\Controllers\Controller;
use App\Models\DistressAlert;
use App\Models\DistressAlertEvent;
use App\Models\SafetyProfile;
use App\Services\Safety\SilentAssistanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Security's side of silent assistance: open requests, with the resident's
 * safety profile, to acknowledge and resolve. Behind `can:manageSecurity`.
 */
class SilentAssistanceController extends Controller
{
    public function __construct(private readonly SilentAssistanceService $safety) {}

    public function index(): Response
    {
        $alerts = DistressAlert::with(['user.vehicles', 'visitor.property', 'visitor.homeowner', 'gatePass', 'safetyProfile', 'acknowledger', 'resolver', 'auditEvents.actor'])
            ->orderByRaw("case when status in ('open', 'acknowledged') then 0 else 1 end")
            ->latest('triggered_at')
            ->limit(100)
            ->get();

        $profiles = SafetyProfile::whereIn('user_id', $alerts->pluck('user_id')->filter()->unique())->get()->keyBy('user_id');

        return Inertia::render('Dashboard/SilentAssistance', [
            'alerts' => $alerts->map(function (DistressAlert $a) use ($profiles) {
                $profile = $a->safetyProfile ?? $profiles[$a->user_id] ?? null;
                $isActive = in_array($a->status, ['open', 'acknowledged'], true);

                return [
                    'id' => $a->id,
                    'incidentId' => $a->incident_id,
                    'auditTrail' => $a->auditEvents->map(fn ($event) => [
                        'eventType' => $event->event_type,
                        'occurredAt' => $event->occurred_at?->toIso8601String(),
                        'actor' => $event->actor?->display_name,
                        'snapshot' => $event->snapshot ?? [],
                    ])->values(),
                    'status' => $a->status,
                    'statusLabel' => $a->status === 'acknowledged' ? 'Security responding' : ($a->status === 'open' ? 'Security alerted' : ucfirst(str_replace('_', ' ', $a->status))),
                    'assistance' => $a->assistance_type,
                    'severity' => $a->severity_level,
                    'severityLabel' => $a->severityLabel(),
                    'channel' => $a->channelLabel(),
                    'gate' => $a->gate
                        ? config('gatepass.gates.'.$a->gate, GateId::tryFrom($a->gate)?->label() ?? $a->gate)
                        : null,
                    'event' => $a->event_type ? strtoupper(str_replace('_', ' ', $a->event_type)) : null,
                    'eventAt' => $a->event_at?->toIso8601String(),
                    'credentialReference' => $a->credential_reference,
                    'vehicles' => $a->vehicle_context ?? [],
                    'latitude' => $a->latitude,
                    'longitude' => $a->longitude,
                    'notes' => $a->notes,
                    'triggeredAt' => $a->triggered_at->toIso8601String(),
                    'escalationLevel' => $a->escalation_level,
                    'escalationLabel' => $a->escalationLabel(),
                    'escalatedAt' => $a->escalated_at?->toIso8601String(),
                    'escalatedBy' => $a->escalator?->display_name,
                    'canEscalateToSecurity' => $a->canEscalateToSecurity(),
                    'canEscalateToEmergencyServices' => $a->canEscalateToEmergencyServices(),
                    'isEmergencyServicesEligible' => $a->isEmergencyServicesEligible(),
                    'resident' => [
                        'name' => $a->personName() ?? $a->user?->display_name ?? $a->visitor?->name ?? $a->gatePass?->holder_name,
                        'person' => $a->personName(),
                        'capacity' => $a->capacityLabel(),
                        'representativeRole' => $a->representativeRole(),
                        'ownerEntity' => $a->ownerEntityName(),
                        'ownerEntityType' => $a->ownerEntityType(),
                        'isEntityRepresentative' => $a->isEntityRepresentative(),
                        'phone' => $a->user?->phone,
                        'property' => $a->propertyLabel() ?? $a->user?->propertyLabel() ?? $a->gatePass?->property ?? $a->visitor?->property?->label() ?? $a->visitor?->homeowner?->propertyLabel(),
                        'holderType' => $a->auditEvents->firstWhere('event_type', DistressAlertEvent::ACTIVATED)?->snapshot['holderType'] ?? null,
                    ],
                    // The profile is for responding to an active request; once
                    // closed, the medical notes and contact are no longer shown.
                    'profile' => $isActive && $profile ? [
                        'safetyCredentialId' => $profile->safety_credential_id,
                        'emergencyContactName' => $profile->user_id !== null ? $profile->emergency_contact_name : null,
                        'emergencyContactPhone' => $profile->user_id !== null ? $profile->emergency_contact_phone : null,
                        'emergencyContactRelationship' => $profile->user_id !== null ? $profile->emergency_contact_relationship : null,
                        'preferredAssistance' => $profile->preferred_assistance,
                        'assistancePreferences' => collect($profile->assistance_preferences ?: [$profile->preferred_assistance])
                            ->filter(fn ($type) => in_array($type, ['security', 'police', 'medical', 'fire', 'emergency_services'], true))
                            ->values()
                            ->all() ?: ['security'],
                        'emergencyContacts' => $profile->user_id !== null ? ($profile->emergency_contacts ?: array_values(array_filter([[
                            'name' => $profile->emergency_contact_name,
                            'phone' => $profile->emergency_contact_phone,
                            'relationship' => $profile->emergency_contact_relationship,
                        ]], fn ($contact) => filled($contact['name']) || filled($contact['phone'])))) : [],
                        'preferredGate' => $profile->preferred_gate
                            ? config('gatepass.gates.'.$profile->preferred_gate, GateId::tryFrom($profile->preferred_gate)?->label() ?? $profile->preferred_gate)
                            : null,
                        'preferredVehicle' => ($preferredVehicle = $a->user?->vehicles?->firstWhere('id', $profile->preferred_vehicle_id))
                            ? $preferredVehicle->fullDescription().' · '.$preferredVehicle->formattedPlate()
                            : null,
                        'medicalNotes' => $profile->user_id !== null ? $profile->medical_notes : null,
                    ] : null,
                    'acknowledgedBy' => $a->acknowledger?->display_name,
                    'acknowledgedAt' => $a->acknowledged_at?->toIso8601String(),
                    'resolvedBy' => $a->resolver?->display_name,
                    'resolvedAt' => $a->resolved_at?->toIso8601String(),
                    'resolutionNotes' => $a->resolution_notes,
                ];
            }),
        ]);
    }

    public function acknowledge(Request $request, DistressAlert $alert): RedirectResponse
    {
        $this->safety->acknowledge($alert, $request->user());

        return back()->with('success', 'Request acknowledged.');
    }

    public function resolve(Request $request, DistressAlert $alert): RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', 'in:resolved,false_alarm'],
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        $this->safety->resolve($alert, $request->user(), $validated['outcome'], $validated['notes']);

        return back()->with('success', 'Request closed.');
    }

    public function escalateToSecurity(Request $request, DistressAlert $alert): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->safety->escalateToSecurity($alert, $request->user(), $validated['notes'] ?? null);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['escalation' => $e->getMessage()]);
        }

        return back()->with('success', 'Escalated to security level.');
    }

    public function escalateToEmergencyServices(Request $request, DistressAlert $alert): RedirectResponse
    {
        $validated = $request->validate([
            'service' => ['required', 'in:police,medical,fire'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // An explicit yes, so a stray click cannot escalate. A flash message
            // used to be returned instead, which a caller could ignore.
            'confirmed' => config('safety.require_emergency_confirmation', true) ? ['accepted'] : ['nullable'],
        ], [
            'confirmed.accepted' => 'Confirm before escalating to emergency services.',
        ]);

        try {
            $this->safety->escalateToEmergencyServices($alert, $request->user(), $validated['service'], $validated['notes'] ?? null);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['escalation' => $e->getMessage()]);
        }

        return back()->with('success', 'Escalated to emergency services.');
    }
}
