<?php

namespace App\Services;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\ScanDecision;
use App\Enums\ValidationStatus;
use App\Events\VisitorCheckedInEvent;
use App\Exceptions\InvalidPassTransition;
use App\Exceptions\ScanNotConfirmable;
use App\Models\AccessAuthorization;
use App\Models\AccessLogEntry;
use App\Models\AuthorizedPerson;
use App\Models\BlocklistEntry;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Credentials\WalletCredentialCode;
use App\Services\Safety\SilentAssistanceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The guard's side of the gate: scan, then confirm or refuse.
 *
 * scan() runs the engine and, for a CHECK_IN or CHECK_OUT decision, parks the
 * decision under a random scan ID for a short time. confirm() applies it. The
 * confirm call carries only that ID. The action comes from the server's own
 * record, so the browser cannot choose to check someone in or out, and a
 * decision can be confirmed once, by the guard who scanned, before it lapses.
 *
 * Replaces GatePassController::confirmAction(), which took a pass ID and an
 * action straight from the client with no token and no validation.
 */
class GateScanner
{
    public function __construct(
        private readonly GatePassEngine $engine,
        private readonly AccessRiskEngine $riskEngine,
        private readonly AccessAuditTimelineService $timelineService,
        private readonly SilentAssistanceService $safety,
        private readonly WalletCredentialCode $walletCodes,
    ) {}

    /**
     * @return array{report: array<string, mixed>, decision: string, scanId: string|null, accessLogId: int, expiresAt: string|null}
     */
    public function scan(
        string $token,
        GateId $gate,
        User $guard,
        string $method = 'Digital Pass',
        string $direction = 'auto',
        bool $overridePassback = false,
        ?string $overrideReason = null
    ): array {
        $trimmed = trim(str_replace('-', '', $token));

        // A duress PIN is taken exactly like the resident's normal offline PIN
        // (same method, same outcome) and raises silent assistance below.
        $duressProfile = preg_match('/^\d{6}$/', $trimmed) ? $this->safety->profileForDuressPin($trimmed) : null;
        if ($duressProfile?->user) {
            $candidate = $duressProfile->user->gatePasses()
                ->whereNull('visitor_id')
                ->whereNull('delegated_access_id')
                ->whereIn('status', [PassStatus::Active, PassStatus::CheckedIn])
                ->latest('id')
                ->first();

            if ($candidate) {
                $token = $this->engine->issueToken($candidate, $gate)['token'];
                $method = 'Offline Gate PIN';
                $trimmed = '';
            }
        }

        // A PIN is six digits derived from the pass, so two active passes can
        // share one. Then it names no one: taking the first match would check
        // in the wrong person, so the guard is asked for the QR code instead.
        $ambiguousPin = false;
        if (preg_match('/^\d{6}$/', $trimmed)) {
            $matches = GatePass::whereIn('status', [PassStatus::Active, PassStatus::CheckedIn])
                ->get()
                ->filter(fn ($p) => $p->offline_pin === $trimmed);

            if ($matches->count() === 1) {
                $issued = $this->engine->issueToken($matches->first(), $gate);
                $token = $issued['token'];
                $method = 'Offline Gate PIN';
            }

            $ambiguousPin = $matches->count() > 1;
        }

        // ── 0. Multi-Modal Token Translation (wallet pass, NFC tap, guard override, or card UID) ──
        // A wallet pass (QR or NFC) carries a signed code. A bare pass ID or an
        // unsigned NFC string is refused: anyone who has seen a pass ID could
        // otherwise print one. The one exception is a guard's explicit
        // anti-passback override, which is typed by security with a reason.
        if (($walletPassId = $this->walletCodes->passIdFrom($token)) !== null) {
            $candidate = GatePass::where('pass_id', $walletPassId)->first();
            if ($candidate) {
                $isNfc = $this->walletCodes->isNfcMessage($token);
                $token = $this->engine->issueToken($candidate, $gate)['token'];
                if ($isNfc) {
                    $method = 'NFC Contactless Tap';
                } elseif ($method === 'Digital Pass') {
                    $method = 'Mobile Wallet Pass';
                }
            }
        } elseif ($overridePassback && filled($overrideReason) && str_starts_with($token, 'GP-') && ! str_contains($token, '.')) {
            $candidate = GatePass::where('pass_id', $token)->first();
            if ($candidate) {
                $issued = $this->engine->issueToken($candidate, $gate);
                $token = $issued['token'];
                if ($method === 'Digital Pass') {
                    $method = 'Mobile Wallet Pass';
                }
            }
        } elseif (str_starts_with($token, '04:') && strlen($token) >= 20) {
            // NFC Card UID match
            $candidate = GatePass::whereIn('status', [PassStatus::Active, PassStatus::CheckedIn, PassStatus::Revoked])
                ->get()
                ->first(function ($p) use ($token) {
                    $hash = md5($p->pass_id.':'.config('gatepass.secret', 'community_hub'));
                    $computedUid = sprintf('04:%s:%s:%s:%s:%s:%s',
                        substr($hash, 0, 2), substr($hash, 2, 2), substr($hash, 4, 2),
                        substr($hash, 6, 2), substr($hash, 8, 2), substr($hash, 10, 2)
                    );

                    return strcasecmp($computedUid, $token) === 0;
                });

            if ($candidate) {
                $issued = $this->engine->issueToken($candidate, $gate);
                $token = $issued['token'];
                $method = 'NFC Contactless Tap';
            }
        }

        $report = $this->engine->validate($token, $gate);
        if ($ambiguousPin) {
            $report['primaryReason'] = 'This PIN matches more than one pass. Scan the QR code or check ID instead.';
        }
        $pass = GatePass::with(['user', 'visitor'])->where('pass_id', $report['passId'])->first();
        $decision = ScanDecision::from($report['decision']);

        // A pass issued under a delegation stops when the delegation does:
        // suspended, expired, still awaiting approval, or an emergency-only
        // delegation outside an emergency. Only revocation used to reach the gate.
        $delegationId = $pass?->delegated_access_id ?? ($pass?->metadata['delegated_access_id'] ?? null);
        if ($decision !== ScanDecision::Reject && $delegationId) {
            $delegation = DelegatedAccess::find($delegationId);
            if (! $delegation || ! $delegation->isActive()) {
                $decision = ScanDecision::Reject;
                $report['decision'] = ScanDecision::Reject->value;
                $report['status'] = 'REJECTED';
                $report['primaryReason'] = 'The authorization behind this pass is not currently active';
            }
        }

        // An AccessAuthorization governs scope, permissions, approval, expiry, revocation,
        // and enforces cross-property isolation (cross-property access denied unless explicitly authorized).
        $authId = $pass?->access_authorization_id ?? ($pass?->metadata['authorization_id'] ?? null);
        if ($decision !== ScanDecision::Reject && $authId) {
            $authorization = AccessAuthorization::find($authId);
            if (! $authorization || ! $authorization->isCurrentlyActive()) {
                $decision = ScanDecision::Reject;
                $report['decision'] = ScanDecision::Reject->value;
                $report['status'] = 'REJECTED';
                $report['primaryReason'] = 'The access authorization behind this pass is expired, revoked, or pending approval';
            } elseif ($targetPropId = ($pass->metadata['target_property_id'] ?? null)) {
                if (! $authorization->isPermittedForProperty($targetPropId)) {
                    $decision = ScanDecision::Reject;
                    $report['decision'] = ScanDecision::Reject->value;
                    $report['status'] = 'REJECTED';
                    $report['primaryReason'] = 'Cross-property access denied: Pass is not authorized for the requested property';
                }
            }
        }

        // ── Rule-based access control engine (time windows, days permitted, curfew, gate privileges) ──
        if ($decision !== ScanDecision::Reject && $pass) {
            $rules = $pass->metadata['access_rules'] ?? [];
            $schedule = $pass->metadata['access_schedule'] ?? [];

            // 1. Day of week rule
            $permittedDays = $rules['allowed_days'] ?? $rules['days_permitted'] ?? $schedule['days'] ?? null;
            if (! empty($permittedDays) && ! in_array('all', array_map('strtolower', $permittedDays))) {
                $todayShort = strtolower(now()->format('D'));
                $normalized = array_map(fn ($d) => strtolower(substr($d, 0, 3)), $permittedDays);
                if (! in_array(strtolower(substr($todayShort, 0, 3)), $normalized, true)) {
                    $decision = ScanDecision::Reject;
                    $report['decision'] = ScanDecision::Reject->value;
                    $report['status'] = 'REJECTED';
                    $report['primaryReason'] = sprintf('Access not permitted on %s (authorized days: %s)', now()->format('l'), implode(', ', array_map('ucfirst', $permittedDays)));
                }
            }

            // 2. Time window / Shift schedule rule
            $startTime = $rules['entry_start_time'] ?? $rules['time_window']['start'] ?? null;
            $endTime = $rules['entry_end_time'] ?? $rules['time_window']['end'] ?? null;

            // Also check schedule['hours'] format: '8:00 AM – 5:00 PM' or '08:00 - 17:00'
            if (empty($startTime) && ! empty($schedule['hours'])) {
                $cleanHours = str_replace(['–', '—'], '-', $schedule['hours']);
                $hParts = explode('-', $cleanHours);
                if (count($hParts) === 2) {
                    $startTime = date('H:i', strtotime(trim($hParts[0])));
                    $endTime = date('H:i', strtotime(trim($hParts[1])));
                }
            }

            if ($decision !== ScanDecision::Reject && ! empty($startTime) && ! empty($endTime) && ($startTime !== '00:00' || $endTime !== '23:59')) {
                $nowTime = now()->format('H:i');
                if ($nowTime < $startTime || $nowTime > $endTime) {
                    $decision = ScanDecision::Reject;
                    $report['decision'] = ScanDecision::Reject->value;
                    $report['status'] = 'REJECTED';
                    $report['primaryReason'] = sprintf('Outside authorized operational hours (%s - %s)', $startTime, $endTime);
                }
            }

            // 3. Child / Dependent Curfew rule
            if ($decision !== ScanDecision::Reject && ! empty($schedule['curfew_enabled'])) {
                $curfewHours = str_replace(['–', '—'], '-', $schedule['curfew_hours'] ?? '06:00 AM - 9:00 PM');
                $cParts = explode('-', $curfewHours);
                if (count($cParts) === 2) {
                    $curfewStart = date('H:i', strtotime(trim($cParts[0])));
                    $curfewEnd = date('H:i', strtotime(trim($cParts[1])));
                    $nowTime = now()->format('H:i');
                    if ($nowTime < $curfewStart || $nowTime > $curfewEnd) {
                        $decision = ScanDecision::Reject;
                        $report['decision'] = ScanDecision::Reject->value;
                        $report['status'] = 'REJECTED';
                        $report['primaryReason'] = sprintf('Curfew restriction: Dependent access prohibited between %s and %s', date('g:i A', strtotime($curfewEnd)), date('g:i A', strtotime($curfewStart)));
                    }
                }
            }

            // 4. Gate privilege rule
            if ($decision !== ScanDecision::Reject && isset($rules['gate_access']) && $rules['gate_access'] === false) {
                $decision = ScanDecision::Reject;
                $report['decision'] = ScanDecision::Reject->value;
                $report['status'] = 'REJECTED';
                $report['primaryReason'] = 'Gate entry privilege not authorized on this credential';
            }

            // 5. Anti-Passback Enforcement Engine
            // Prevent someone from using the same credential to enter twice without leaving.
            $isEntryAttempt = ($direction === 'in');

            if ($decision !== ScanDecision::Reject && $pass->status === PassStatus::CheckedIn && $isEntryAttempt) {
                if ($overridePassback) {
                    $decision = ScanDecision::CheckIn;
                    $report['decision'] = ScanDecision::CheckIn->value;
                    $report['status'] = 'ALLOW';
                    $report['isPassbackOverride'] = true;
                    $report['overrideReason'] = $overrideReason ?: 'Security officer override authorized.';
                    $report['primaryReason'] = sprintf(
                        'Security Override: Anti-passback cleared by %s. Reason: %s',
                        $guard->name,
                        $report['overrideReason']
                    );
                } else {
                    $decision = ScanDecision::Reject;
                    $report['decision'] = ScanDecision::Reject->value;
                    $report['status'] = 'REJECTED';
                    $report['denyReason'] = 'ALREADY_CHECKED_IN';
                    $report['isPassbackViolation'] = true;
                    $report['canOverride'] = true;
                    $checkedInTime = $pass->checked_in_at ? $pass->checked_in_at->format('g:i A') : 'prior entry';
                    $report['checkedInAt'] = $pass->checked_in_at?->toIso8601String() ?? $pass->status_changed_at?->toIso8601String();
                    $report['primaryReason'] = sprintf(
                        '⚠️ Already Checked In: Passholder is recorded inside property since %s. Duplicate entry without prior departure prohibited.',
                        $checkedInTime
                    );
                }
            } elseif ($direction === 'out' && $decision !== ScanDecision::Reject) {
                $decision = ScanDecision::CheckOut;
                $report['decision'] = ScanDecision::CheckOut->value;
                $report['status'] = 'ALLOW';
                $report['primaryReason'] = 'CHECK_OUT: Departure verified; passholder exited property.';
            }
        }

        $entry = AccessLogEntry::create([
            'user_id' => $pass?->user_id ?? $pass?->visitor?->homeowner_id,
            'user_name' => $report['userName'],
            'user_role' => $pass?->user?->role->value ?? $report['category'],
            'method' => $method,
            'gate' => $this->gateName($gate),
            'pass_id' => $report['passId'],
            'result' => $report['status'],
            'deny_reason' => $decision === ScanDecision::Reject ? $report['primaryReason'] : null,
            'validation_report' => $report,
            'scanned_by' => $guard->id,
            'occurred_at' => now(),
        ]);

        // ── Silent assistance ──
        // A duress PIN, or a credential armed by the resident's discreet QR
        // gesture. Added to what the guard sees after the access log is
        // written, so the log reads exactly like a normal entry.
        $silentAlert = null;
        if ($pass?->user) {
            $silentAlert = $duressProfile && (int) $pass->user_id === (int) $duressProfile->user_id
                ? $this->safety->raise($pass->user, 'duress_pin', null, $this->safety->scanContext($pass, $gate, $direction))
                : $this->safety->consumeArmedScan($pass, $gate, $direction);
        }
        if ($silentAlert) {
            $report['silentAssistance'] = [
                'alertId' => $silentAlert->id,
                'person' => $silentAlert->personName(),
                'capacity' => $silentAlert->capacityLabel(),
                'representativeRole' => $silentAlert->representativeRole(),
                'ownerEntity' => $silentAlert->ownerEntityName(),
                'ownerEntityType' => $silentAlert->ownerEntityType(),
                'property' => $silentAlert->propertyLabel(),
                'assistance' => $silentAlert->assistance_type,
                'assistanceLabel' => $silentAlert->assistanceLabel(),
                'severity' => $silentAlert->severity_level,
                'severityLabel' => $silentAlert->severityLabel(),
                'channel' => $silentAlert->channelLabel(),
                'status' => in_array($silentAlert->status, ['open', 'acknowledged'], true) ? 'ACTIVE' : 'SECURITY ALERTED',
                'event' => $silentAlert->event_type ? strtoupper(str_replace('_', ' ', $silentAlert->event_type)) : null,
                'gate' => $silentAlert->gate ? $this->gateName(GateId::tryFrom($silentAlert->gate) ?? $gate) : null,
                'triggeredAt' => $silentAlert->triggered_at->toIso8601String(),
                'eventAt' => $silentAlert->event_at?->toIso8601String(),
                'credentialReference' => $silentAlert->credential_reference,
                'vehicles' => $silentAlert->vehicle_context ?? [],
                'instruction' => 'Act normally. Do not acknowledge this to anyone present. Security has been alerted.',
            ];
        }

        // ── Access Risk Engine & Forensic Timeline Analysis ──
        $detectedIncident = $this->riskEngine->analyzeScanAttempt($token, $gate, $guard, [
            'report' => $report,
            'decision' => $decision->value,
        ]);

        $passIdForTimeline = $report['passId'] ?? ($pass?->pass_id ?? (str_starts_with($token, 'GP-') ? explode('|', $token)[0] : 'UNKNOWN'));

        $this->timelineService->recordEvent(
            passId: $passIdForTimeline,
            eventType: $decision === ScanDecision::Reject ? 'CREDENTIAL_DENIED' : 'CREDENTIAL_SCANNED',
            headline: sprintf('%s credential scanned at %s (%s)', $report['userName'] ?? 'Visitor', $this->gateName($gate), $method),
            description: $decision === ScanDecision::Reject
                ? 'Scan REJECTED: '.($report['primaryReason'] ?? 'Validation failed')
                : 'Scan processed with status '.$report['status'],
            gate: $gate->value,
            severity: $decision === ScanDecision::Reject ? 'WARNING' : 'INFO',
            actorType: 'SECURITY_OFFICER',
            actor: $guard,
            telemetry: [
                'method' => $method,
                'decision' => $decision->value,
                'direction' => $direction,
                'status' => $report['status'],
                'access_log_id' => $entry->id,
                'risk_incident_id' => $detectedIncident?->id,
            ]
        );

        if ($decision === ScanDecision::Reject) {
            Log::channel('security')->warning('Gate pass rejected', [
                'pass_id' => $report['passId'],
                'gate' => $gate->value,
                'reason' => $report['primaryReason'],
                'scanned_by' => $guard->uid,
            ]);

            return [
                'report' => $report,
                'decision' => $decision->value,
                'scanId' => null,
                'accessLogId' => $entry->id,
                'expiresAt' => null,
                'isPassbackViolation' => $report['isPassbackViolation'] ?? false,
                'canOverride' => $report['canOverride'] ?? false,
                'checkedInAt' => $report['checkedInAt'] ?? null,
            ];
        }

        // ── Smart Security Categorical Breakdown ──
        if ($pass) {
            $isLongTerm = ($pass->category === PassCategory::LongTermOccupant || ($pass->metadata['authorization_type'] ?? '') === 'long_term_occupant');
            $rules = $pass->metadata['access_rules'] ?? [];

            $report['accessRulesSummary'] = [
                'Gate' => $rules['gate_access'] ?? true,
                'Pool' => $rules['amenity_access']['pool'] ?? ($isLongTerm ? true : false),
                'Gym' => $rules['amenity_access']['gym'] ?? false,
                'Parking' => $rules['parking_access'] ?? true,
                'Create Visitors' => $rules['can_create_visitors'] ?? false,
                'Emergency Access' => $rules['emergency_access'] ?? false,
                'Verify Photo ID' => $rules['verify_id'] ?? false,
            ];
            $report['requiresIdVerification'] = (bool) ($rules['verify_id'] ?? false);

            $repAppointment = $pass->user_id ? AuthorizedPerson::with('ownershipEntity')
                ->where('user_id', $pass->user_id)
                ->current()
                ->first() : null;

            if ($repAppointment && $repAppointment->ownershipEntity) {
                $entity = $repAppointment->ownershipEntity;
                $report['isEntityRepresentative'] = true;
                $report['representedEntity'] = $entity->legal_name;
                $report['representativeRole'] = $repAppointment->role->label();
                $report['roleLabel'] = "Authorized Representative of {$entity->legal_name}";
                $report['actingFor'] = $entity->legal_name;
                $report['authorizedBy'] = $entity->legal_name;
                $report['relationship'] = "Authorized Representative ({$repAppointment->role->label()})";
                $report['accessTier'] = 'ENTITY REPRESENTATIVE';
                $report['delegatedTitle'] = strtoupper($repAppointment->role->label());
                $report['authorizationType'] = 'AUTHORIZED REPRESENTATIVE';
                $report['permissionSummary'] = 'Property Access';
                if (empty($report['property']) || $report['property'] === 'Unassigned') {
                    $report['property'] = $repAppointment->authorizedProperties()->first()?->label() ?? $report['property'];
                }
            }

            if ($isLongTerm) {
                $report['isDelegated'] = true;
                $report['accessTier'] = 'RESIDENT ACCESS';
                $report['delegatedTitle'] = 'LONG-TERM OCCUPANT';
                $report['authorizationType'] = 'LONG-TERM OCCUPANT';
                $report['actingFor'] = $pass->metadata['grantor_name'] ?? $pass->user?->name;
                $report['authorizedBy'] = $pass->metadata['grantor_name'] ?? $pass->user?->name;
                $report['relationship'] = $pass->metadata['relationship'] ?? 'Family/Friend';
                $report['accessLevel'] = 'Long-Term Occupant';
                $report['property'] = $pass->property;
                $report['validWindow'] = sprintf('%s – %s', $pass->valid_from?->format('M d, Y') ?? 'Immediate', $pass->valid_until?->format('M d, Y') ?? 'Permanent');
                $report['status'] = $pass->status->value;
            } elseif ($pass->category === PassCategory::Delegate || ! empty($pass->metadata['delegated_access_id'])) {
                $delegatedAccess = ! empty($pass->metadata['delegated_access_id']) ? DelegatedAccess::find($pass->metadata['delegated_access_id']) : null;
                $accessLevel = $pass->metadata['access_level'] ?? $delegatedAccess?->access_level ?? 'Emergency Delegate';
                $isEmergency = (bool) ($pass->metadata['is_emergency'] ?? $delegatedAccess?->is_emergency_active ?? false);

                $report['isDelegated'] = true;
                $report['accessTier'] = 'DELEGATED ACCESS';
                $report['delegatedTitle'] = 'AUTHORIZED DELEGATE';
                $report['authorizationType'] = match ($accessLevel) {
                    'Legacy Delegate', 'Legacy Contact' => 'LEGACY CONTACT',
                    'Caregiver' => 'CAREGIVER',
                    'Property Delegate' => 'PROPERTY MANAGER',
                    'Emergency Delegate' => 'EMERGENCY DELEGATE',
                    'Full Authorized Representative' => 'AUTHORIZED REPRESENTATIVE',
                    default => 'AUTHORIZED DELEGATE',
                };
                $report['actingFor'] = $pass->metadata['grantor_name'] ?? $delegatedAccess?->grantor?->name;
                $report['authorizedBy'] = $report['actingFor'];
                $report['relationship'] = $pass->metadata['relationship'] ?? $delegatedAccess?->relationship ?? 'Delegate';
                $report['accessLevel'] = $accessLevel;
                $report['isEmergency'] = $isEmergency;
                $report['property'] = $pass->property;
                $report['validWindow'] = sprintf('%s – %s', $pass->valid_from?->format('M d, Y') ?? 'Immediate', $pass->valid_until?->format('M d, Y') ?? 'Permanent');
                $report['status'] = $pass->status->value;

                // Check suspicious time (late night e.g. between 23:00 and 05:00)
                $currentHour = (int) now()->format('H');
                $isLateNight = ($currentHour >= 23 || $currentHour < 5);

                if ($delegatedAccess) {
                    $delegatedAccess->logEvent(
                        $isLateNight ? 'suspicious_attempt' : 'gate_scanned',
                        sprintf('Gate %s scanned by %s (%s) at %s%s', $decision->value, $guard->name, $gate->label(), now()->format('H:i'), $isLateNight ? ' [Late Night Window]' : ''),
                        [
                            'decision' => $decision->value,
                            'gate' => $gate->value,
                            'is_late_night' => $isLateNight,
                        ],
                        $guard
                    );

                    if ($isLateNight && $delegatedAccess->grantor) {
                        InAppNotification::create([
                            'user_id' => $delegatedAccess->grantor_user_id,
                            'category' => 'security',
                            'title' => "⚠️ Notice: Delegate {$delegatedAccess->name} Gate Access",
                            'body' => "Delegate {$delegatedAccess->name} ({$delegatedAccess->relationship}) accessed {$gate->label()} at {$pass->property} at ".now()->format('h:i A').'.',
                            'action_url' => '/dashboard/delegation',
                            'priority' => 'high',
                        ]);
                    }
                }
            } elseif ($pass->category === PassCategory::Visitor) {
                $report['isDelegated'] = false;
                $report['accessTier'] = 'TEMPORARY ACCESS';
                $report['delegatedTitle'] = 'PRE-REGISTERED GUEST';
                $report['authorizationType'] = 'GUEST';
                $report['authorizedBy'] = $pass->visitor?->homeowner_name ?? $pass->visitor?->homeowner?->name ?? $pass->user?->name ?? 'Homeowner';
                $report['actingFor'] = $report['authorizedBy'];
                $report['relationship'] = 'Pre-Registered Guest';
                $report['property'] = $pass->property;
                $report['validWindow'] = sprintf('%s – %s', $pass->valid_from?->format('M d, g:i A') ?? 'Immediate', $pass->valid_until?->format('M d, g:i A') ?? 'Close');
                $report['arrivalWindow'] = $pass->visitor?->arrivalWindowLabel() ?? $pass->metadata['arrival_window'] ?? $report['validWindow'];
                $report['parkingInstructions'] = $pass->visitor?->parkingInstructions() ?? $pass->metadata['parking_instructions'] ?? null;
                $report['communityRules'] = $pass->visitor?->communityRules() ?? $pass->metadata['community_rules'] ?? [];
                $report['emergencyInfo'] = $pass->visitor?->emergencyInfo() ?? $pass->metadata['emergency_info'] ?? [];
                $report['status'] = $pass->status->value;
            } elseif ($pass->category === PassCategory::Contractor) {
                $report['isDelegated'] = false;
                $report['accessTier'] = 'TEMPORARY ACCESS';
                $report['delegatedTitle'] = 'CONTRACTOR';
                $report['authorizationType'] = 'CONTRACTOR';
                $report['authorizedBy'] = $pass->user?->name ?? 'Community Administration';
                $report['actingFor'] = $report['authorizedBy'];
                $report['relationship'] = 'Contractor';
                $report['property'] = $pass->property;
                $report['validWindow'] = sprintf('%s – %s', $pass->valid_from?->format('M d, Y') ?? 'Immediate', $pass->valid_until?->format('M d, Y') ?? 'Close');
                $report['status'] = $pass->status->value;
            }
        }

        $ttl = (int) config('gatepass.scan_confirm_seconds', 120);
        $scanId = (string) Str::uuid();

        Cache::put($this->cacheKey($scanId), [
            'pass_id' => $pass->id,
            'decision' => $decision->value,
            'from_status' => $pass->status->value,
            'gate' => $gate->value,
            'guard_id' => $guard->id,
            'method' => $method,
            'direction' => $direction,
            'is_override' => $overridePassback,
            'override_reason' => $overrideReason,
        ], $ttl);

        return [
            'report' => $report,
            'decision' => $decision->value,
            'scanId' => $scanId,
            'accessLogId' => $entry->id,
            'expiresAt' => now()->addSeconds($ttl)->toIso8601String(),
            'isPassbackViolation' => $report['isPassbackViolation'] ?? false,
            'canOverride' => $report['canOverride'] ?? false,
            'checkedInAt' => $report['checkedInAt'] ?? null,
        ];
    }

    /**
     * Applies (or, with $accept false, refuses) a scanned decision.
     *
     * @return array{action: string, passStatus: string, time: string, message: string}
     *
     * @throws ScanNotConfirmable
     */
    public function confirm(string $scanId, User $guard, bool $accept = true, ?string $refusalReason = null): array
    {
        // pull, not get: a decision is used up by the first attempt to confirm it.
        $pending = Cache::pull($this->cacheKey($scanId));

        if (! is_array($pending) || $pending['guard_id'] !== $guard->id) {
            // Unknown, lapsed, already used, or someone else's scan: all the same to the caller.
            throw new ScanNotConfirmable('This scan has expired or was already confirmed. Scan the pass again.');
        }

        $pass = GatePass::with(['user', 'visitor'])->findOrFail($pending['pass_id']);
        $gate = GateId::from($pending['gate']);
        $decision = ScanDecision::from($pending['decision']);
        $now = now();

        if (! $accept) {
            $this->log($pass, $gate, $guard, ValidationStatus::Deny->value, $pending['method'],
                'REFUSED_BY_OFFICER: '.($refusalReason ?: 'Guard refused entry after inspection.'));

            $this->timelineService->recordEvent(
                passId: $pass->pass_id,
                eventType: 'ENTRY_REFUSED',
                headline: sprintf('Entry refused at %s by %s', $gate->label(), $guard->name),
                description: $refusalReason ?: 'Guard refused entry after physical inspection.',
                gate: $gate->value,
                severity: 'WARNING',
                actorType: 'SECURITY_OFFICER',
                actor: $guard,
                telemetry: ['method' => $pending['method'], 'reason' => $refusalReason]
            );

            return ['action' => 'REFUSED', 'passStatus' => $pass->status->value, 'time' => $now->format('g:i A'), 'message' => 'Entry refused and logged.'];
        }

        // The pass may have been revoked, suspended or used since the scan.
        if ($pass->status->value !== $pending['from_status']) {
            throw new ScanNotConfirmable(sprintf('The pass changed to %s since it was scanned. Scan it again.', $pass->status->label()));
        }

        try {
            if ($decision === ScanDecision::CheckIn && $pass->status === PassStatus::Issued) {
                $pass->transitionTo(PassStatus::Active, $guard, 'First use at the gate', $gate);
            }

            if (($pending['is_override'] ?? false) && $decision === ScanDecision::CheckIn && $pass->status === PassStatus::CheckedIn) {
                $pass->update([
                    'checked_in_at' => $now,
                    'status_changed_at' => $now,
                ]);
                $pass->transitions()->create([
                    'from_status' => PassStatus::CheckedIn,
                    'to_status' => PassStatus::CheckedIn,
                    'actor_id' => $guard->id,
                    'gate' => $gate->value,
                    'reason' => 'Security override: '.($pending['override_reason'] ?? 'Officer authorized re-entry'),
                    'occurred_at' => $now,
                ]);
            } else {
                $pass->transitionTo($decision->targetStatus(), $guard, null, $gate);
            }
        } catch (InvalidPassTransition $e) {
            throw new ScanNotConfirmable($e->getMessage().' Scan the pass again.');
        }

        $this->syncVisitor($pass, $decision, $gate);
        $this->log($pass, $gate, $guard, $decision->value, $pending['method']);

        $isCheckIn = ($decision === ScanDecision::CheckIn);
        $this->timelineService->recordEvent(
            passId: $pass->pass_id,
            eventType: $isCheckIn ? 'SECURITY_APPROVED' : 'CHECK_OUT_CONFIRMED',
            headline: sprintf('Security officer %s approved %s at %s', $guard->name, $isCheckIn ? 'entry' : 'departure', $gate->label()),
            description: sprintf('Barrier opened for %s (%s).', $pass->holder_name ?? $pass->user?->name ?? 'Passholder', $pass->category?->label() ?? 'Pass'),
            gate: $gate->value,
            severity: 'INFO',
            actorType: 'SECURITY_OFFICER',
            actor: $guard,
            telemetry: ['method' => $pending['method'], 'direction' => $pending['direction'] ?? ($isCheckIn ? 'in' : 'out')]
        );

        if ($isCheckIn) {
            $this->timelineService->recordEvent(
                passId: $pass->pass_id,
                eventType: 'GATE_OPENED',
                headline: sprintf('Gate barrier opened at %s', $gate->label()),
                description: 'Physical boom barrier gate opened for vehicle / pedestrian entry.',
                gate: $gate->value,
                severity: 'INFO',
                actorType: 'BARRIER_HARDWARE',
                actor: $guard,
                telemetry: ['gate' => $gate->value]
            );
        }

        return [
            'action' => $decision->value,
            'passStatus' => $pass->status->value,
            'time' => $now->format('g:i A'),
            'message' => ($decision === ScanDecision::CheckIn ? 'Checked in at ' : 'Checked out at ').$now->format('g:i A'),
        ];
    }

    /**
     * Checks a pass holder in without a code, for a visitor at the gate with a
     * flat phone. The same pass rules as a scan apply: state, validity window
     * and blocklist. Only the token checks are skipped, because there is no
     * token.
     *
     * @throws ScanNotConfirmable
     */
    public function manualCheckIn(GatePass $pass, User $guard, GateId $gate): void
    {
        $blocked = $pass->visitor && ($pass->visitor->is_blocked || BlocklistEntry::blocks($pass->holder_name));

        $canEnter = match ($pass->status) {
            PassStatus::Issued, PassStatus::Active => true,
            PassStatus::CheckedOut => ! $pass->single_entry,
            default => false,
        };

        $reason = match (true) {
            $pass->isRevoked() => 'The pass has been revoked.',
            $blocked => 'The holder is on the community blocklist.',
            ! $canEnter => "The pass is {$pass->status->label()}.",
            ! $pass->isWithinValidity(now()) => 'The pass is outside its validity window.',
            default => null,
        };

        if ($reason !== null) {
            $this->log($pass, $gate, $guard, ValidationStatus::Deny->value, 'Manual (no code)', "MANUAL_CHECK_IN_REFUSED: {$reason}");

            throw new ScanNotConfirmable($reason);
        }

        if ($pass->status === PassStatus::Issued) {
            $pass->transitionTo(PassStatus::Active, $guard, 'First use at the gate', $gate);
        }

        $pass->transitionTo(PassStatus::CheckedIn, $guard, 'Checked in without a code', $gate);
        $this->syncVisitor($pass, ScanDecision::CheckIn, $gate);
        $this->log($pass, $gate, $guard, ScanDecision::CheckIn->value, 'Manual (no code)');
    }

    /** @throws ScanNotConfirmable */
    public function manualCheckOut(GatePass $pass, User $guard, GateId $gate): void
    {
        if (! $pass->canTransitionTo(PassStatus::CheckedOut)) {
            throw new ScanNotConfirmable("The pass is {$pass->status->label()}, not checked in.");
        }

        $pass->transitionTo(PassStatus::CheckedOut, $guard, 'Checked out without a code', $gate);
        $this->syncVisitor($pass, ScanDecision::CheckOut, $gate);
        $this->log($pass, $gate, $guard, ScanDecision::CheckOut->value, 'Manual (no code)');
    }

    /** Keeps the visitor record, which the Visitors page reads, in step with its pass. */
    private function syncVisitor(GatePass $pass, ScanDecision $decision, GateId $gate): void
    {
        $visitor = $pass->visitor;

        if (! $visitor) {
            return;
        }

        if ($decision === ScanDecision::CheckIn) {
            $visitor->checkIn();
            event(new VisitorCheckedInEvent($visitor, $this->gateName($gate)));
        } else {
            $visitor->checkOut();
        }
    }

    private function log(GatePass $pass, GateId $gate, User $guard, string $result, string $method, ?string $denyReason = null): void
    {
        AccessLogEntry::create([
            'user_id' => $pass->user_id ?? $pass->visitor?->homeowner_id,
            'user_name' => $pass->holder_name,
            'user_role' => $pass->user?->role->value ?? $pass->category->value,
            'method' => $method,
            'gate' => $this->gateName($gate),
            'pass_id' => $pass->pass_id,
            'result' => $result,
            'deny_reason' => $denyReason,
            'scanned_by' => $guard->id,
            'occurred_at' => now(),
        ]);
    }

    private function gateName(GateId $gate): string
    {
        return config('gatepass.gates.'.$gate->value, $gate->value);
    }

    private function cacheKey(string $scanId): string
    {
        return "gate-scan:{$scanId}";
    }
}
