<?php

namespace App\Services\Safety;

use App\Enums\GateId;
use App\Models\DistressAlert;
use App\Models\DistressAlertEvent;
use App\Models\GatePass;
use App\Models\SafetyProfile;
use App\Models\User;
use App\Services\GatePassEngine;
use App\Services\SmsService;
use Illuminate\Support\Collection;

/**
 * Discreet distress / silent assistance coordinator.
 *
 * Three ways in, none of which changes anything an observer can see:
 *  - the app: a plain "Request assistance?" confirmation;
 *  - the QR gesture: holding the QR code raises an alert and arms the
 *    credential, so its next gate scan tells the guard where the resident is;
 *  - the duress PIN: a second gate PIN that opens the gate like the normal
 *    one and raises an alert.
 *
 * Alerts go to security and administration only, never to the resident's own
 * notifications, which someone beside them could be looking at.
 */
class SilentAssistanceService
{
    private readonly DistressAccessContextResolver $contextResolver;

    private readonly DistressDispatchNotifier $notifier;

    private readonly DistressEscalationService $escalationService;

    private readonly DuressCredentialService $credentialService;

    public function __construct(
        private readonly SmsService $sms,
        ?DistressAccessContextResolver $contextResolver = null,
        ?DistressDispatchNotifier $notifier = null,
        ?DistressEscalationService $escalationService = null,
        ?DuressCredentialService $credentialService = null,
    ) {
        $this->contextResolver = $contextResolver ?? app(DistressAccessContextResolver::class);
        $this->notifier = $notifier ?? app(DistressDispatchNotifier::class);
        $this->escalationService = $escalationService ?? app(DistressEscalationService::class);
        $this->credentialService = $credentialService ?? app(DuressCredentialService::class);
    }

    public function profileFor(User $user): SafetyProfile
    {
        return $this->contextResolver->profileFor($user);
    }

    /** @return list<string> */
    public function configuredOptions(string $holderType): array
    {
        return $this->contextResolver->configuredOptions($holderType);
    }

    /** @return array{holderType:string,label:string,holderName:string,property:?string,assistanceOptions:list<string>,accountHolder:bool,isEntityRepresentative?:bool,capacity?:string,representativeRole?:?string,ownerEntity?:?string,ownerEntityType?:?string} */
    public function accessContextFor(User $user): array
    {
        return $this->contextResolver->accessContextFor($user);
    }

    public function accessContextForPass(GatePass $pass): ?array
    {
        return $this->contextResolver->accessContextForPass($pass);
    }

    /** Validate requested help against both the credential class and its own profile choices. */
    public function allowsAssistance(User $user, string $assistance): bool
    {
        return $this->contextResolver->allowsAssistance($user, $assistance);
    }

    /** @return list<string> */
    public function optionsForUser(User $user): array
    {
        return $this->contextResolver->optionsForUser($user);
    }

    /** Resolve an active, signed pass to a narrowly scoped guest credential. */
    public function guestContextForPass(GatePass $pass): ?array
    {
        return $this->contextResolver->guestContextForPass($pass);
    }

    /** Resolve a private guest-pass link to its currently valid visitor pass. */
    public function passForVisitorToken(string $token): ?GatePass
    {
        return $this->contextResolver->passForVisitorToken($token);
    }

    /** Resolve an authorized non-resident pass by its short-lived signed QR token. */
    public function passForCredentialToken(string $rawToken, GatePassEngine $engine): ?GatePass
    {
        return $this->contextResolver->passForCredentialToken($rawToken, $engine);
    }

    /** Resolve a currently usable guest or contractor pass without requiring a resident account. */
    public function guestProfile(GatePass $pass, array $context): SafetyProfile
    {
        return $this->contextResolver->guestProfile($pass, $context);
    }

    public function raiseForGuest(GatePass $pass, array $context, string $assistance, array $requestContext = []): ?DistressAlert
    {
        if (! $pass->isActive() || ! $pass->isWithinValidity(now())
            || (! empty($context['visitor']) && ($context['visitor']->is_blocked || $context['visitor']->status->value === 'Checked Out' || $context['visitor']->expired_at?->isPast()))
            || ! in_array($assistance, $context['assistanceOptions'], true)
            || ! in_array($assistance, $this->contextResolver->configuredOptions($context['holderType']), true)
            || (in_array($assistance, ['police', 'medical', 'fire', 'emergency_services'], true) && ! config('safety.emergency_services_enabled', false))
            || (! empty($context['user']) && ! in_array($assistance, $this->contextResolver->optionsForAccount(
                $context['user'],
                $this->contextResolver->profileFor($context['user']),
                $context['holderType'],
            ), true))) {
            return null;
        }

        $user = $context['user'] ?? null;
        $profile = $user ? $this->contextResolver->profileFor($user) : $this->contextResolver->guestProfile($pass, $context);
        $requestContext['gate_pass_id'] = $pass->id;
        $requestContext['credential_reference'] = $pass->pass_id;

        $alert = DistressAlert::create([
            'user_id' => $user?->id,
            'safety_profile_id' => $profile->id,
            'visitor_id' => $pass->visitor_id,
            'gate_pass_id' => $pass->id,
            'incident_id' => DistressAlert::newIncidentId(),
            'channel' => 'credential',
            'assistance_type' => $assistance,
            'severity_level' => DistressAlert::SEVERITY_URGENT_ASSISTANCE,
            'status' => 'open',
            'credential_reference' => $pass->pass_id,
            'triggered_at' => now(),
            'acknowledgment_deadline_at' => now()->addSeconds((int) config('safety.acknowledgment_timeout_seconds', 60)),
            'escalation_level' => DistressAlert::ESCALATION_SILENT_ALERT,
            'notes' => $requestContext['notes'] ?? null,
        ]);

        $this->notifier->recordAudit($alert, DistressAlertEvent::ACTIVATED, snapshot: $this->notifier->incidentSnapshot($alert) + [
            'resident' => $context['holderName'],
            'holderType' => $context['holderType'],
            'property' => $context['property'],
            'credential' => 'Verified active credential',
            'credentialReference' => $pass->pass_id,
            'assistance' => ucfirst(str_replace('_', ' ', $assistance)),
        ]);

        if ($user) {
            $this->notifier->dispatch($alert, $profile);
        } else {
            $this->notifier->dispatchForCredential($alert, $context);
        }

        return $alert;
    }

    public function accountIncidents(User $user): Collection
    {
        $this->contextResolver->profileFor($user);

        return DistressAlert::query()->with('auditEvents')
            ->where('user_id', $user->id)
            ->latest('triggered_at')
            ->limit(50)
            ->get();
    }

    public function raiseForAccountCredential(User $user, GatePass $pass, string $assistance, array $requestContext = []): ?DistressAlert
    {
        if ($pass->user_id !== $user->id || $pass->visitor_id || ! $pass->isActive() || ! $pass->isWithinValidity(now())) {
            return null;
        }

        $context = $this->contextResolver->guestContextForPass($pass);
        if (! $context || ! in_array($assistance, $context['assistanceOptions'], true)) {
            return null;
        }
        $context['user'] = $user;

        return $this->raiseForGuest($pass, $context, $assistance, $requestContext);
    }

    public function guestIncidents(GatePass $pass): Collection
    {
        if ($pass->visitor_id) {
            return DistressAlert::query()->with('auditEvents')->where('visitor_id', $pass->visitor_id)->latest('triggered_at')->limit(50)->get();
        }

        if ($pass->user_id) {
            return DistressAlert::query()->with('auditEvents')->where('user_id', $pass->user_id)->latest('triggered_at')->limit(50)->get();
        }

        return DistressAlert::query()->with('auditEvents')->where('gate_pass_id', $pass->id)->latest('triggered_at')->limit(50)->get();
    }

    /**
     * Raises a request. A hidden trigger (QR gesture, duress PIN) does nothing
     * for a resident who has turned silent alerts off; asking in the app always
     * works.
     *
     * @param  array{gate?: string|null, event_type?: string|null, credential_reference?: string|null, vehicle_context?: array<int, string>, severity_level?: string|null, latitude?: float|null, longitude?: float|null, notes?: string|null, gate_pass_id?: int|null}  $context
     */
    public function raise(User $user, string $channel, ?string $assistance = null, array $context = []): ?DistressAlert
    {
        $profile = $this->contextResolver->profileFor($user);
        $accountContext = $this->contextResolver->accessContextFor($user);
        $effectiveOptions = $accountContext['assistanceOptions'];
        if ($user->role->isOperational() && $channel !== 'app') {
            return null;
        }

        if ($channel !== 'app' && ! $profile->silent_alerts_enabled) {
            return null;
        }

        if ($assistance !== null && ! in_array($assistance, $effectiveOptions, true)) {
            return null;
        }

        $alert = DistressAlert::create([
            'user_id' => $user->id,
            'safety_profile_id' => $profile->id,
            'incident_id' => DistressAlert::newIncidentId(),
            'gate_pass_id' => $context['gate_pass_id'] ?? null,
            'channel' => $channel,
            'assistance_type' => $assistance ?? $profile->preferred_assistance,
            'severity_level' => in_array($context['severity_level'] ?? null, [
                DistressAlert::SEVERITY_ASSISTANCE,
                DistressAlert::SEVERITY_URGENT_ASSISTANCE,
                DistressAlert::SEVERITY_SILENT_EMERGENCY,
            ], true)
                ? $context['severity_level']
                : ($channel === 'duress_pin' ? DistressAlert::SEVERITY_SILENT_EMERGENCY : DistressAlert::SEVERITY_ASSISTANCE),
            'status' => 'open',
            'gate' => $context['gate'] ?? null,
            'event_type' => $context['event_type'] ?? null,
            'event_at' => $context['event_at'] ?? null,
            'credential_reference' => $context['credential_reference'] ?? null,
            'vehicle_context' => $context['vehicle_context'] ?? null,
            'latitude' => $context['latitude'] ?? null,
            'longitude' => $context['longitude'] ?? null,
            'notes' => $context['notes'] ?? null,
            'triggered_at' => now(),
            'acknowledgment_deadline_at' => now()->addSeconds((int) config('safety.acknowledgment_timeout_seconds', 60)),
            'escalation_level' => DistressAlert::ESCALATION_SILENT_ALERT,
        ]);

        $this->notifier->recordAudit($alert, DistressAlertEvent::ACTIVATED, snapshot: $this->notifier->incidentSnapshot($alert));

        $this->notifier->dispatch($alert, $profile);

        return $alert;
    }

    /**
     * The QR gesture: raise an alert now, and arm the credential so that the
     * resident's next gate scan places them at that gate.
     */
    public function arm(User $user, array $context = []): ?DistressAlert
    {
        $alert = $this->raise($user, 'qr_gesture', null, $context);

        if ($alert) {
            $this->contextResolver->profileFor($user)->forceFill([
                'armed_until' => now()->addMinutes((int) config('safety.armed_minutes', 15)),
            ])->save();
        }

        return $alert;
    }

    /**
     * Called for every scan the gate accepts or reviews. An armed credential
     * is disarmed and its alert told which gate; the guard's screen says so.
     */
    public function consumeArmedScan(GatePass $pass, GateId $gate, string $direction = 'in'): ?DistressAlert
    {
        $user = $pass->user;
        if (! $user || $pass->visitor_id) {
            return null;
        }

        $profile = SafetyProfile::where('user_id', $user->id)->first();
        if (! $profile?->isArmed()) {
            return null;
        }

        $profile->forceFill(['armed_until' => null])->save();

        $alert = DistressAlert::where('user_id', $user->id)
            ->where('channel', 'qr_gesture')
            ->active()
            ->latest('triggered_at')
            ->first();

        if (! $alert) {
            return $this->raise($user, 'qr_scan', null, $this->credentialService->scanContext($pass, $gate, $direction));
        }

        $alert->update([
            ...$this->credentialService->scanContext($pass, $gate, $direction),
            'notes' => trim(($alert->notes ? $alert->notes."\n" : '').'Credential scanned at '.$gate->label().' at '.now()->format('H:i').'.'),
        ]);
        $this->notifier->recordAudit($alert, DistressAlertEvent::GATE_CONTEXT_CAPTURED, snapshot: $this->notifier->incidentSnapshot($alert));
        $this->notifier->broadcast($alert);

        return $alert;
    }

    /** Capture verified scan details and registered vehicle descriptions for responders. */
    public function scanContext(GatePass $pass, GateId $gate, string $direction): array
    {
        return $this->credentialService->scanContext($pass, $gate, $direction);
    }

    public function setDuressPin(User $user, string $pin): void
    {
        $this->credentialService->setDuressPin($user, $pin);
    }

    public function clearDuressPin(User $user): void
    {
        $this->credentialService->clearDuressPin($user);
    }

    public function profileForDuressPin(string $pin): ?SafetyProfile
    {
        return $this->credentialService->profileForDuressPin($pin);
    }

    public function rotateCredential(User $user): SafetyProfile
    {
        return $this->credentialService->rotateCredential($user);
    }

    public function acknowledge(DistressAlert $alert, User $responder): void
    {
        $this->escalationService->acknowledge($alert, $responder);
    }

    public function resolve(DistressAlert $alert, User $responder, string $outcome, ?string $notes): void
    {
        $this->escalationService->resolve($alert, $responder, $outcome, $notes);
    }

    public function escalateToSecurity(DistressAlert $alert, User $responder, ?string $notes = null): void
    {
        $this->escalationService->escalateToSecurity($alert, $responder, $notes);
    }

    public function escalateOnAcknowledgmentTimeout(DistressAlert $alert, ?string $reason = null): void
    {
        $this->escalationService->escalateOnAcknowledgmentTimeout($alert, $reason);
    }

    public function escalateUnacknowledgedAlerts(): int
    {
        return $this->escalationService->escalateUnacknowledgedAlerts();
    }

    public function escalateToEmergencyServices(DistressAlert $alert, User $responder, string $confirmedService, ?string $notes = null): void
    {
        $this->escalationService->escalateToEmergencyServices($alert, $responder, $confirmedService, $notes);
    }
}
