<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\GateId;
use App\Http\Controllers\Controller;
use App\Models\DistressAlert;
use App\Models\User;
use App\Services\Safety\SilentAssistanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A resident's side of silent assistance: their safety profile, the duress
 * PIN, and the two ways of asking for help from the app.
 *
 * Every request answers the same plain way whatever it does, so nothing on
 * the screen tells someone nearby that help was called.
 */
class SafetyController extends Controller
{
    public function __construct(private readonly SilentAssistanceService $safety) {}

    public function show(Request $request): Response
    {
        $user = $this->resident($request);
        $context = $this->safety->accessContextFor($user);
        $context['assistanceOptions'] = $this->safety->optionsForUser($user);
        $profile = $this->safety->profileFor($user);
        $mayViewOwnIncidents = $user->role->isResident() || $user->role->isAdministrative();
        $incidents = $mayViewOwnIncidents ? $this->safety->accountIncidents($user)
            ->map(fn (DistressAlert $alert) => [
                'incidentId' => $alert->incident_id,
                'assistance' => $alert->assistance_type,
                'status' => $alert->status,
                'assistanceLabel' => ucfirst($alert->assistance_type),
                'activatedAt' => $alert->triggered_at?->toIso8601String(),
                'closedAt' => $alert->resolved_at?->toIso8601String(),
                'auditTrail' => $alert->auditEvents->map(fn ($event) => [
                    'eventType' => $event->event_type,
                    'occurredAt' => $event->occurred_at?->toIso8601String(),
                ])->values(),
            ]) : collect();

        return Inertia::render('Dashboard/Safety', [
            'incidents' => $incidents,
            'holderLabel' => $context['label'],
            'assistanceOptions' => $context['assistanceOptions'],
            'profile' => [
                'emergencyContactName' => $mayViewOwnIncidents ? $profile->emergency_contact_name : null,
                'emergencyContactPhone' => $mayViewOwnIncidents ? $profile->emergency_contact_phone : null,
                'emergencyContactRelationship' => $mayViewOwnIncidents ? $profile->emergency_contact_relationship : null,
                'preferredAssistance' => $profile->preferred_assistance,
                'assistancePreferences' => $mayViewOwnIncidents
                    ? (collect($profile->assistance_preferences ?: [$profile->preferred_assistance])
                        ->filter(fn ($type) => in_array($type, $context['assistanceOptions'], true))
                        ->values()
                        ->all() ?: ['security'])
                    : ['security'],
                'emergencyContacts' => $mayViewOwnIncidents ? ($profile->emergency_contacts ?: array_values(array_filter([[
                    'name' => $profile->emergency_contact_name,
                    'phone' => $profile->emergency_contact_phone,
                    'relationship' => $profile->emergency_contact_relationship,
                ]], fn ($contact) => filled($contact['name']) || filled($contact['phone'])))) : [],
                'preferredGate' => $mayViewOwnIncidents ? $profile->preferred_gate : null,
                'preferredVehicleId' => $mayViewOwnIncidents ? $profile->preferred_vehicle_id : null,
                'medicalNotes' => $mayViewOwnIncidents ? $profile->medical_notes : null,
                'silentAlertsEnabled' => $profile->silent_alerts_enabled,
                'hasDuressPin' => ($user->role->isResident() || $user->role->isAdministrative()) && $profile->hasDuressPin(),
            ],
            'publicEmergencyNumber' => config('safety.public_emergency_number'),
            'gateOptions' => $mayViewOwnIncidents ? collect(GateId::cases())
                ->reject(fn (GateId $gate) => $gate === GateId::Any)
                ->map(fn (GateId $gate) => ['value' => $gate->value, 'label' => config('gatepass.gates.'.$gate->value, $gate->label())])
                ->values() : [],
            'vehicleOptions' => $mayViewOwnIncidents ? $user->vehicles->map(fn ($vehicle) => [
                'value' => (string) $vehicle->id,
                'label' => $vehicle->fullDescription().' · '.$vehicle->formattedPlate(),
            ])->values() : [],
            'severityOptions' => $mayViewOwnIncidents ? [
                ['value' => DistressAlert::SEVERITY_ASSISTANCE, 'label' => 'Assistance'],
                ['value' => DistressAlert::SEVERITY_URGENT_ASSISTANCE, 'label' => 'Urgent Assistance'],
                ['value' => DistressAlert::SEVERITY_SILENT_EMERGENCY, 'label' => 'Silent Emergency'],
            ] : [],
            'profileEnabled' => $mayViewOwnIncidents,
            'holdSeconds' => (int) config('safety.qr_hold_seconds', 3),
            'armedMinutes' => (int) config('safety.armed_minutes', 15),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $this->requester($request);
        abort_unless($user->role->isResident() || $user->role->isAdministrative(), 403);

        $incomingContacts = $request->input('emergency_contacts');
        if (is_array($incomingContacts)) {
            $request->merge(['emergency_contacts' => collect($incomingContacts)
                ->filter(fn ($contact) => is_array($contact) && (filled($contact['name'] ?? null) || filled($contact['phone'] ?? null)))
                ->values()
                ->all()]);
        }

        $allowedOptions = $this->safety->optionsForUser($user);
        $validated = $request->validate([
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:60'],
            'preferred_assistance' => ['sometimes', 'required', Rule::in($allowedOptions)],
            'assistance_preferences' => ['sometimes', 'array', 'min:1', 'max:5'],
            'assistance_preferences.*' => ['required', Rule::in($allowedOptions)],
            'emergency_contacts' => ['sometimes', 'array', 'max:5'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:120'],
            'emergency_contacts.*.phone' => ['required', 'string', 'max:40'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:60'],
            'preferred_gate' => ['nullable', Rule::in(array_map(fn (GateId $gate) => $gate->value, array_filter(GateId::cases(), fn (GateId $gate) => $gate !== GateId::Any)))],
            'preferred_vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('user_id', $user->id)],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
            'silent_alerts_enabled' => ['required', 'boolean'],
        ]);

        $profile = $this->safety->profileFor($user);
        $assistancePreferences = $validated['assistance_preferences'] ?? $profile->assistance_preferences ?? [$profile->preferred_assistance];
        $assistancePreferences = array_values(array_intersect($assistancePreferences, $allowedOptions));
        if (! $assistancePreferences) {
            $assistancePreferences = [$allowedOptions[0] ?? 'security'];
        }
        $preferredAssistance = in_array($profile->preferred_assistance, $assistancePreferences, true)
            ? $profile->preferred_assistance
            : $assistancePreferences[0];
        abort_unless($assistancePreferences && count(array_diff($assistancePreferences, $allowedOptions)) === 0, 422);
        $contacts = $validated['emergency_contacts'] ?? ($profile->emergency_contacts ?: array_values(array_filter([[
            'name' => $profile->emergency_contact_name,
            'phone' => $profile->emergency_contact_phone,
            'relationship' => $profile->emergency_contact_relationship,
        ]], fn ($contact) => filled($contact['name']) || filled($contact['phone']))));

        if (! array_key_exists('emergency_contacts', $validated) && array_key_exists('emergency_contact_name', $validated)) {
            $contacts = filled($validated['emergency_contact_name']) && filled($validated['emergency_contact_phone']) ? [[
                'name' => $validated['emergency_contact_name'],
                'phone' => $validated['emergency_contact_phone'],
                'relationship' => $validated['emergency_contact_relationship'] ?? null,
            ]] : [];
        }

        $primaryContact = $contacts[0] ?? ['name' => null, 'phone' => null, 'relationship' => null];
        $profile->update([
            'preferred_assistance' => $preferredAssistance,
            'assistance_preferences' => array_values(array_unique($assistancePreferences)),
            'emergency_contacts' => $contacts,
            'emergency_contact_name' => $primaryContact['name'] ?? null,
            'emergency_contact_phone' => $primaryContact['phone'] ?? null,
            'emergency_contact_relationship' => $primaryContact['relationship'] ?? null,
            'preferred_gate' => array_key_exists('preferred_gate', $validated) ? $validated['preferred_gate'] : $profile->preferred_gate,
            'preferred_vehicle_id' => array_key_exists('preferred_vehicle_id', $validated) ? $validated['preferred_vehicle_id'] : $profile->preferred_vehicle_id,
            'medical_notes' => array_key_exists('medical_notes', $validated) ? $validated['medical_notes'] : $profile->medical_notes,
            'silent_alerts_enabled' => $validated['silent_alerts_enabled'],
        ]);

        return back()->with('success', 'Safety profile saved.');
    }

    public function setDuressPin(Request $request): RedirectResponse
    {
        $user = $this->resident($request);
        abort_unless($this->mayUseResidentTriggers($user), 403);

        $validated = $request->validate([
            'pin' => ['required', 'digits:6', 'confirmed'],
        ]);

        try {
            $this->safety->setDuressPin($user, $validated['pin']);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        }

        return back()->with('success', 'Duress PIN set.');
    }

    public function clearDuressPin(Request $request): RedirectResponse
    {
        $user = $this->resident($request);
        abort_unless($this->mayUseResidentTriggers($user), 403);
        $this->safety->clearDuressPin($user);

        return back()->with('success', 'Duress PIN removed.');
    }

    /**
     * "Request assistance?" from the app. The answer is a plain
     * acknowledgement whichever kind of help was asked for.
     */
    public function requestAssistance(Request $request): JsonResponse
    {
        $user = $this->resident($request);

        $validated = $request->validate([
            'assistance' => ['required', Rule::in($this->safety->optionsForUser($user))],
            'severity' => ['sometimes', Rule::in([
                DistressAlert::SEVERITY_ASSISTANCE,
                DistressAlert::SEVERITY_URGENT_ASSISTANCE,
                DistressAlert::SEVERITY_SILENT_EMERGENCY,
            ])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless(in_array($validated['assistance'], $this->safety->optionsForUser($user), true), 422);

        $severity = $validated['severity'] ?? DistressAlert::SEVERITY_ASSISTANCE;
        if ($user->role->isResident() || $user->role->isAdministrative()) {
            abort_unless($this->safety->raise($user, 'app', $validated['assistance'], [...$validated, 'severity_level' => $severity]), 422);
        } else {
            $pass = $user->gatePasses()->whereNull('visitor_id')->whereNull('delegated_access_id')->active()->latest('id')->first();
            abort_unless($pass && $user->passCategory() === $pass->category && in_array($pass->category->value, ['STAFF', 'SECURITY', 'HOMEOWNER_STAFF'], true), 404);
            abort_unless($pass && $pass->isActive() && $pass->isWithinValidity(now()), 404);
            $context = $this->safety->guestContextForPass($pass);
            abort_unless($context, 404);
            abort_unless($this->safety->raiseForAccountCredential($user, $pass, $validated['assistance'], [...$validated, 'severity_level' => $severity]), 422);
        }

        return response()->json(['message' => 'Request sent.']);
    }

    public function credentialSafety(Request $request): Response
    {
        $user = $this->requester($request);
        abort_unless($user->role->isOperational(), 403);
        $pass = $user->gatePasses()->whereNull('visitor_id')->whereNull('delegated_access_id')->active()->latest('id')->first();
        abort_unless($pass && $user->passCategory() === $pass->category && in_array($pass->category->value, ['STAFF', 'SECURITY', 'HOMEOWNER_STAFF'], true), 404);
        abort_unless($pass && $pass->isActive() && $pass->isWithinValidity(now()), 404);
        $context = $this->safety->accessContextForPass($pass);
        abort_unless($context, 404);

        return Inertia::render('Dashboard/CredentialSafety', [
            'credential' => 'account',
            'holderLabel' => $context['label'],
            'assistanceOptions' => $context['assistanceOptions'],
            'emergencyNumber' => config('safety.public_emergency_number'),
            'accountHolder' => true,
        ]);
    }

    public function requestAccountCredentialAssistance(Request $request): JsonResponse
    {
        $user = $this->requester($request);
        abort_unless($user->role->isOperational(), 403);
        $pass = $user->gatePasses()->whereNull('visitor_id')->whereNull('delegated_access_id')->active()->latest('id')->first();
        abort_unless($pass && $user->passCategory() === $pass->category && in_array($pass->category->value, ['STAFF', 'SECURITY', 'HOMEOWNER_STAFF'], true), 404);
        abort_unless($pass && $pass->isActive() && $pass->isWithinValidity(now()), 404);
        $context = $this->safety->guestContextForPass($pass);
        abort_unless($context, 404);
        $validated = $request->validate([
            'assistance' => ['required', Rule::in($this->safety->optionsForUser($user))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless(in_array($validated['assistance'], $context['assistanceOptions'], true), 422);

        abort_unless($this->safety->raiseForAccountCredential($user, $pass, $validated['assistance'], $validated), 422);

        return response()->json(['message' => 'Request received.']);
    }

    /**
     * The discreet QR gesture. No content back, success or not: the screen
     * the resident is holding up must not change.
     */
    public function hold(Request $request): HttpResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->noContent();
        }

        if ($user && $this->mayUseResidentTriggers($user)) {
            $validated = $request->validate([
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ]);

            $this->safety->arm($user, $validated);
        }

        return response()->noContent();
    }

    private function resident(Request $request): User
    {
        $user = $request->user();
        abort_unless($this->mayUseResidentTriggers($user) || $user->activeAuthorizedAppointments()->exists(), 403);

        return $user;
    }

    private function requester(Request $request): User
    {
        $user = $request->user();
        abort_unless($this->mayUse($user), 403);

        return $user;
    }

    public function showCredential(Request $request, string $token): Response
    {
        $pass = $this->safety->passForVisitorToken($token);
        abort_unless($pass, 404);
        $context = $this->safety->accessContextForPass($pass);
        abort_unless($context, 404);

        abort_if($context['accountHolder'], 404);

        return Inertia::render('Dashboard/CredentialSafety', [
            'credential' => $token,
            'holderLabel' => $context['label'],
            'assistanceOptions' => $context['assistanceOptions'],
            'emergencyNumber' => config('safety.public_emergency_number'),
        ]);
    }

    public function requestCredentialAssistance(Request $request, string $token): JsonResponse
    {
        $pass = $this->safety->passForVisitorToken($token);
        abort_unless($pass, 404);
        $context = $this->safety->accessContextForPass($pass);
        abort_unless($context, 404);

        $validated = $request->validate([
            'assistance' => ['required', Rule::in($context['assistanceOptions'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        abort_if($context['accountHolder'], 404);
        abort_unless($this->safety->raiseForGuest($pass, $context, $validated['assistance'], $validated), 422);

        return response()->json(['message' => 'Request received.']);
    }

    /** Residents, staff and administrators may use account-based assistance. */
    private function mayUse(User $user): bool
    {
        return $user->role->isResident() || $user->role->isAdministrative() || $user->role->isOperational();
    }

    private function mayUseResidentTriggers(User $user): bool
    {
        return $user->role->isResident() || $user->role->isAdministrative();
    }
}
