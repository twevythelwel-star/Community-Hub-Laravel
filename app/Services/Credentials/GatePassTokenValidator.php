<?php

namespace App\Services\Credentials;

use App\Enums\DenyReason;
use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\ScanDecision;
use App\Enums\ValidationStatus;
use App\Models\BlocklistEntry;
use App\Models\GatePass;
use Carbon\CarbonImmutable;

class GatePassTokenValidator
{
    public function __construct(
        private readonly GatePassTokenSigner $signer,
        private readonly GatePassPolicyResolver $policyResolver,
    ) {}

    /**
     * Runs the full pipeline and returns a report. `decision` is what the
     * guard is told to do; `status` stays ALLOW/DENY for the access log and
     * older clients.
     *
     * @return array<string, mixed>
     */
    public function validate(
        string $rawQr,
        ?GateId $currentGate = null,
        ?CarbonImmutable $now = null,
        bool $bypassReplayCheck = false,
    ): array {
        $now = $now ?? CarbonImmutable::now();
        $nowSec = $now->getTimestamp();
        $currentGate = $currentGate ?? GateId::Gate01;

        // ── STAGE 1: structure & engine recognition ──
        $accepted = (array) config('gatepass.protocol.accepted_prefixes');
        $recognised = collect($accepted)->contains(fn ($prefix) => str_starts_with($rawQr, $prefix));

        if (! $recognised) {
            return $this->denyReport($now, $currentGate, DenyReason::NotAGpeGatePass,
                'Unrecognized QR token format. Not issued by this Digital Gate Pass Engine.',
                'STRUCTURE', 'Malformed QR envelope format.');
        }

        $parts = explode('.', $rawQr);
        if (count($parts) !== 3) {
            return $this->denyReport($now, $currentGate, DenyReason::InvalidQrStructure,
                'Token envelope requires exactly 3 segments (protocol header, payload, signature).',
                'STRUCTURE', 'Segment count mismatch.');
        }

        $decoded = $this->signer->base64UrlDecode($parts[1]);
        $payload = $decoded === null ? null : json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['pid'], $payload['cat'], $payload['sig'], $payload['vf'], $payload['vu'], $payload['nonce'])) {
            return $this->denyReport($now, $currentGate, DenyReason::InvalidQrStructure,
                'Payload could not be decoded or is missing required claims.',
                'STRUCTURE', 'Payload decode failure.');
        }

        if (($payload['gpe'] ?? null) !== config('gatepass.protocol.engine_id')) {
            return $this->denyReport($now, $currentGate, DenyReason::NotAGpeGatePass,
                'Payload does not carry the Gate Pass Engine identifier.',
                'STRUCTURE', 'Engine identifier missing.');
        }

        if (($payload['cid'] ?? null) !== config('gatepass.default_community_id')) {
            return $this->denyReport($now, $currentGate, DenyReason::UnauthorizedCommunity,
                sprintf('Pass was issued for community %s, not this estate.', $payload['cid'] ?? 'UNKNOWN'),
                'STRUCTURE', 'Community identifier mismatch.', $payload);
        }

        $category = PassCategory::tryFrom((string) $payload['cat']) ?? PassCategory::Homeowner;
        $policy = $this->policyResolver->policyFor($category);

        $report = $this->baseReport($payload, $category, $policy, $now, $currentGate);
        $report['stages']['structure'] = $this->stage(true, 'STRUCTURE', 'Envelope recognised; payload decoded and claims present.', $now);
        $report['checks']['payloadAuthenticity'] = true;
        $report['checks']['communityMatch'] = true;

        // ── STAGE 2: cryptographic signature ──
        $claimed = (string) $payload['sig'];
        $unsigned = $payload;
        unset($unsigned['sig']);

        // The envelope carries the signature twice, inside the payload and as
        // the third segment. Both must be the real one; the trailing copy used
        // to be ignored, so it could be anything.
        if (! hash_equals($this->signer->sign($unsigned), $claimed) || ! hash_equals($claimed, $parts[2])) {
            $report['stages']['cryptography'] = $this->stage(false, 'SIGNATURE', 'HMAC-SHA256 verification failed.', $now);

            return $this->reject($report, DenyReason::SignatureMismatch, 'Payload signature does not match. Token was altered or forged.');
        }

        $report['stages']['cryptography'] = $this->stage(true, 'SIGNATURE', 'HMAC-SHA256 signature verified against server key.', $now);
        $report['checks']['cryptographicSignature'] = true;

        // ── STAGE 3: token window, registry, state, replay ──
        if ($nowSec > (int) $payload['vu']) {
            return $this->rejectAt($report, 'serverCache', DenyReason::TokenExpired,
                sprintf('Dynamic credential expired %d seconds ago.', $nowSec - (int) $payload['vu']),
                sprintf('Token valid window was %s - %s. Current time is %s.',
                    CarbonImmutable::createFromTimestamp((int) $payload['vf'])->toTimeString(),
                    CarbonImmutable::createFromTimestamp((int) $payload['vu'])->toTimeString(),
                    $now->toTimeString()), $now);
        }

        if ($nowSec < (int) $payload['vf'] - (int) config('gatepass.clock_skew_seconds', 5)) {
            return $this->rejectAt($report, 'serverCache', DenyReason::TokenNotYetValid,
                'Credential timestamp is in the future.', 'Token has not yet reached its active validity window.', $now);
        }

        $report['checks']['temporalTimeAuth'] = true;

        // Pass ID: the pass must exist. A signed token for a pass that is no
        // longer on the registry is not a credential.
        $pass = GatePass::with(['user', 'visitor'])->where('pass_id', $payload['pid'])->first();

        if (! $pass) {
            return $this->rejectAt($report, 'serverCache', DenyReason::UnknownPass,
                sprintf('Pass %s is not on the registry.', $payload['pid']), 'Pass ID lookup failed.', $now);
        }

        $report['checks']['passRegistered'] = true;
        $report['passStatus'] = $pass->status->value;
        $report['holderType'] = $pass->visitor_id ? 'VISITOR' : 'ACCOUNT';
        $report['visitorId'] = $pass->visitor_id;
        $report['person'] = $pass->holder_name ?: ($payload['nam'] ?? 'Unknown');
        $report['userName'] = $report['person'];
        $report['profile'] = $pass->category->label();
        $report['property'] = $pass->property ?: ($payload['prop'] ?? 'Unassigned');
        $report['host'] = $pass->visitor?->homeowner_name
            ?? $pass->visitor?->homeowner?->name
            ?? ($pass->user?->name ? 'Self ('.$pass->user->name.')' : 'Resident Host');
        $report['passType'] = $pass->visitor?->type
            ?? ($pass->single_entry ? 'Single Entry' : 'Multi-Entry');
        $report['gate'] = $currentGate->value;
        $report['validUntil'] = $pass->valid_until?->toIso8601String() ?? $report['expiresAt'];
        $report['photoUrl'] = $pass->visitor?->id_image_url ?? $pass->user?->avatar ?? null;

        // Rotation: a token minted before the pass rotated is superseded.
        if ((int) ($payload['seq'] ?? 0) !== $pass->rotation_seq) {
            return $this->rejectAt($report, 'serverCache', DenyReason::PassSuperseded,
                'The pass has been rotated since this code was generated.',
                sprintf('Token sequence %d, current sequence %d.', (int) ($payload['seq'] ?? 0), $pass->rotation_seq), $now);
        }

        // Profile, person, property, zone, gate: the token must agree with the registry.
        $mismatch = $this->claimMismatch($payload, $pass);
        if ($mismatch !== null) {
            return $this->rejectAt($report, 'serverCache', DenyReason::ClaimMismatch,
                "The code's {$mismatch} does not match the pass registry.",
                "Registry disagreement on {$mismatch}.", $now);
        }

        $report['checks']['profileMatch'] = true;
        $report['checks']['personMatch'] = true;
        $report['checks']['propertyMatch'] = true;
        $report['checks']['shapeCategoryMatch'] = true;
        $report['checks']['colorClassMatch'] = true;

        // Revocation and current status.
        if ($pass->isRevoked()) {
            return $this->rejectAt($report, 'serverCache', DenyReason::PassRevoked,
                'Pass ID has been revoked in the central security directory.',
                sprintf('Pass %s was revoked on %s. %s', $pass->pass_id, $pass->revoked_at?->toDayDateTimeString(), $pass->revocation_reason ?? ''), $now);
        }

        $report['checks']['notRevoked'] = true;

        $isExit = $pass->status === PassStatus::CheckedIn;

        if (! $isExit && ! $this->canEnter($pass)) {
            return $this->rejectAt($report, 'serverCache', DenyReason::PassNotUsable,
                sprintf('Pass is %s.', $pass->single_entry && $pass->status === PassStatus::CheckedOut
                    ? 'single-entry and has already been used'
                    : $pass->status->label()),
                sprintf('Status %s cannot be admitted.', $pass->status->value), $now);
        }

        $report['checks']['statusEligible'] = true;

        // Start/end time: entry only inside the pass's own window. Leaving is
        // always allowed; someone who overstays still has to be let out.
        if (! $isExit && ! $pass->isWithinValidity($now)) {
            return $this->rejectAt($report, 'serverCache', DenyReason::OutsideValidity,
                sprintf('Pass is valid %s to %s.',
                    $pass->valid_from?->toDayDateTimeString() ?? 'now',
                    $pass->valid_until?->toDayDateTimeString() ?? 'open-ended'),
                'Outside the pass validity window.', $now);
        }

        $report['checks']['validityPeriod'] = true;

        // Person: the holder must still be allowed in.
        if ($pass->user && ! $pass->user->isActive()) {
            return $this->rejectAt($report, 'serverCache', DenyReason::UserNotActive,
                'Pass holder account is deactivated.', 'Holder account is not active.', $now);
        }

        if (! $isExit && $pass->visitor && ($pass->visitor->is_blocked || BlocklistEntry::blocks($pass->holder_name))) {
            return $this->rejectAt($report, 'serverCache', DenyReason::HolderBlocked,
                'Pass holder is on the community blocklist.', 'Blocklist match.', $now);
        }

        $report['checks']['personVerified'] = true;

        // Replay: the first scan of a nonce wins. The insert is the lock.
        if (! $bypassReplayCheck) {
            $firstSeen = $this->signer->claimNonce((string) $payload['nonce'], (string) $payload['pid'], (int) $payload['vu']);

            if ($firstSeen !== null) {
                return $this->rejectAt($report, 'serverCache', DenyReason::ReplayAttack,
                    'Static screenshot duplicate detected. Nonce has already been checked.',
                    sprintf('Nonce %s was already used at %s.', $payload['nonce'], $firstSeen->toTimeString()), $now);
            }
        }

        $report['checks']['replayFree'] = true;
        $report['stages']['serverCache'] = $this->stage(true, 'SERVER_CACHE',
            sprintf('Pass %s is %s; claims match the registry; nonce %s registered.', $pass->pass_id, $pass->status->label(), $payload['nonce']), $now);

        // ── STAGE 4: access policy (entry only) ──
        if (! $isExit) {
            $gateRule = $this->gateRuleFailure($pass, $payload, $policy, $currentGate);
            if ($gateRule !== null) {
                [$reason, $detail, $stageMessage] = $gateRule;

                return $this->rejectAt($report, 'accessPolicy', $reason, $detail, $stageMessage, $now);
            }

            $report['checks']['physicalGateAuth'] = true;
            $report['checks']['zoneAuth'] = true;

            $hoursFailure = $this->hoursFailure($policy, $category, $now);
            if ($hoursFailure !== null) {
                return $this->rejectAt($report, 'accessPolicy', DenyReason::OutsideHours, $hoursFailure[0], $hoursFailure[1], $now);
            }
        } else {
            $report['checks']['physicalGateAuth'] = true;
            $report['checks']['zoneAuth'] = true;
        }

        // ── Cleared ──
        $decision = $isExit ? ScanDecision::CheckOut : ScanDecision::CheckIn;

        $report['status'] = ValidationStatus::Allow->value;
        $report['decision'] = $decision->value;
        $report['primaryReason'] = $isExit
            ? 'CHECK_OUT: Holder is recorded inside; confirm departure.'
            : 'CHECK_IN: Signature, registry, validity and policy checks all passed.';
        $report['stages']['accessPolicy'] = $this->stage(true, 'ACCESS_POLICY', $isExit
            ? 'Departure: gate, zone and hours are not applied to someone leaving.'
            : sprintf('Policy clearance active. Authorized for: %s...', implode(', ', array_slice($policy['authorized_zones'], 0, 2))), $now);

        $variant = $this->policyResolver->variantFor($pass);
        $report['visualIdentity'] = [
            'shape' => $category->shape()->value,
            'colorVariantId' => $variant['id'],
            'colorVariantName' => $variant['name'],
            'colorHex' => $variant['hex'],
            'isApprovedPalette' => true,
            'contrastRatio' => $variant['contrast_ratio'],
            'wcagPass' => $variant['wcag_pass'],
            'rotationSequence' => $pass->rotation_seq,
            'securityNote' => 'Visual identifier only; access is authorized by the signed token and the pass registry.',
        ];

        return $report;
    }

    /** ISSUED, ACTIVE, or CHECKED_OUT on a pass that allows re-entry. */
    private function canEnter(GatePass $pass): bool
    {
        return match ($pass->status) {
            PassStatus::Issued, PassStatus::Active => true,
            PassStatus::CheckedOut => ! $pass->single_entry,
            default => false,
        };
    }

    /**
     * The first token claim that disagrees with the registry row, or null.
     *
     * @param  array<string, mixed>  $payload
     */
    private function claimMismatch(array $payload, GatePass $pass): ?string
    {
        $checks = [
            'profile' => [$payload['cat'] ?? null, $pass->category->value],
            'holder name' => [$payload['nam'] ?? null, $pass->holder_name],
            'holder account' => [(string) ($payload['uid'] ?? ''), (string) ($pass->user?->uid ?? '')],
            'property' => [$payload['prop'] ?? null, $pass->property ?? 'Unassigned'],
            'zone' => [$payload['zone'] ?? null, $this->policyResolver->zoneOf($pass)],
        ];

        foreach ($checks as $field => [$claimed, $registered]) {
            if ($claimed !== $registered) {
                return $field;
            }
        }

        // A token may narrow an any-gate pass to one gate, never the reverse.
        $registryGate = $pass->designated_gate ?? GateId::Any;
        if ($registryGate !== GateId::Any && ($payload['gate'] ?? null) !== $registryGate->value) {
            return 'gate';
        }

        return null;
    }

    /**
     * Gate and zone rules for entry, or null when they pass.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $policy
     * @return array{0: DenyReason, 1: string, 2: string}|null
     */
    private function gateRuleFailure(GatePass $pass, array $payload, array $policy, GateId $currentGate): ?array
    {
        $tokenGate = GateId::tryFrom((string) ($payload['gate'] ?? GateId::Any->value)) ?? GateId::Any;

        if ($tokenGate !== GateId::Any && $tokenGate !== $currentGate) {
            return [DenyReason::UnauthorizedGate,
                sprintf('Pass restricted strictly to %s. Attempted ingress at %s.', $tokenGate->value, $currentGate->value),
                sprintf('Physical boundary enforcement: designated gate is %s.', $tokenGate->value)];
        }

        if (! in_array($currentGate->value, $policy['allowed_gates'], true) && ! in_array(GateId::Any->value, $policy['allowed_gates'], true)) {
            return [DenyReason::UnauthorizedGate,
                sprintf('Category %s is not cleared for %s.', $pass->category->value, $currentGate->value),
                'Category gate clearance does not include this portal.'];
        }

        $zone = $this->policyResolver->zoneOf($pass);
        $served = config('gatepass.gate_zones.'.$currentGate->value);

        if (is_array($served) && ! in_array($zone, $served, true)) {
            return [DenyReason::UnauthorizedZone,
                sprintf('%s does not admit into %s.', config('gatepass.gates.'.$currentGate->value, $currentGate->value), $zone),
                'Zone not served by this gate.'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array{0: string, 1: string}|null
     */
    private function hoursFailure(array $policy, PassCategory $category, CarbonImmutable $now): ?array
    {
        $hours = $policy['operational_hours'];

        if ($hours['is_24_hours'] ?? false) {
            return null;
        }

        // Carbon dayOfWeek matches the JS getDay() convention: 0 = Sunday.
        if (isset($hours['days_of_week']) && ! in_array((int) $now->dayOfWeek, $hours['days_of_week'], true)) {
            return [sprintf('Ingress not permitted on %ss.', $now->format('l')),
                sprintf('Category %s schedule restricted to operating days.', $category->value)];
        }

        $hour = (int) $now->hour;
        if (isset($hours['start_hour'], $hours['end_hour']) && ($hour < $hours['start_hour'] || $hour >= $hours['end_hour'])) {
            return [sprintf('Operational shift bounds are %d:00 - %d:00. Current time: %s.', $hours['start_hour'], $hours['end_hour'], $now->toTimeString()),
                sprintf('Shift schedule violation: access prohibited outside %d:00 to %d:00.', $hours['start_hour'], $hours['end_hour'])];
        }

        return null;
    }

    /** @return array{passed: bool, stage: string, message: string, timestamp: string} */
    private function stage(bool $passed, string $stage, string $message, CarbonImmutable $now): array
    {
        return [
            'passed' => $passed,
            'stage' => $stage,
            'message' => $message,
            'timestamp' => $now->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function reject(array $report, DenyReason $reason, string $detail): array
    {
        $report['status'] = ValidationStatus::Deny->value;
        $report['decision'] = ScanDecision::Reject->value;
        $report['denyReason'] = $reason->value;
        $report['primaryReason'] = $reason->value.': '.$detail;

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function rejectAt(array $report, string $slot, DenyReason $reason, string $detail, string $stageMessage, CarbonImmutable $now): array
    {
        $name = $slot === 'accessPolicy' ? 'ACCESS_POLICY' : 'SERVER_CACHE';
        $report['stages'][$slot] = $this->stage(false, $name, $stageMessage, $now);

        return $this->reject($report, $reason, $detail);
    }

    /**
     * Report skeleton for a decoded, recognised payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private function baseReport(array $payload, PassCategory $category, array $policy, CarbonImmutable $now, GateId $gate): array
    {
        $issuedAt = CarbonImmutable::createFromTimestamp((int) ($payload['t'] ?? $now->getTimestamp()));
        $expiresAt = CarbonImmutable::createFromTimestamp((int) ($payload['vu'] ?? $now->getTimestamp()));

        return [
            'status' => ValidationStatus::Deny->value,
            'decision' => ScanDecision::Reject->value,
            'denyReason' => null,
            'primaryReason' => '',
            'category' => $category->value,
            'profile' => $category->label(),
            'shape' => $category->shape()->value,
            'communityId' => $payload['cid'] ?? config('gatepass.default_community_id'),
            'accessZone' => $payload['zone'] ?? 'ZONE-UNKNOWN',
            'passId' => $payload['pid'] ?? 'UNKNOWN',
            'passStatus' => null,
            'holderType' => null,
            'visitorId' => null,
            'property' => $payload['prop'] ?? 'UNKNOWN',
            'userName' => $payload['nam'] ?? 'Unknown Visitor',
            'person' => $payload['nam'] ?? 'Unknown Visitor',
            'host' => 'Resident Host',
            'passType' => 'Standard Access',
            'gate' => $gate->value,
            'gateChecked' => $gate->value,
            'validUntil' => $expiresAt->toIso8601String(),
            'photoUrl' => null,
            'stages' => [
                'structure' => $this->stage(false, 'STRUCTURE', 'Not evaluated.', $now),
                'cryptography' => $this->stage(false, 'SIGNATURE', 'Signature could not be evaluated.', $now),
                'serverCache' => $this->stage(false, 'SERVER_CACHE', 'Server lookup blocked.', $now),
                'accessPolicy' => $this->stage(false, 'ACCESS_POLICY', 'Policy evaluation blocked.', $now),
            ],
            'checks' => array_fill_keys([
                'payloadAuthenticity', 'communityMatch', 'cryptographicSignature', 'temporalTimeAuth',
                'passRegistered', 'profileMatch', 'personMatch', 'propertyMatch', 'notRevoked',
                'statusEligible', 'validityPeriod', 'personVerified', 'replayFree',
                'physicalGateAuth', 'zoneAuth', 'shapeCategoryMatch', 'colorClassMatch',
            ], false),
            'policy' => $policy,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
            'secondsRemaining' => max(0, $expiresAt->getTimestamp() - $now->getTimestamp()),
        ];
    }

    /**
     * Terminal REJECT report for a token that never got far enough to decode.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function denyReport(
        CarbonImmutable $now,
        GateId $gate,
        DenyReason $reason,
        string $detail,
        string $stage,
        string $stageMessage,
        array $payload = [],
    ): array {
        $category = PassCategory::tryFrom($payload['cat'] ?? '') ?? PassCategory::Homeowner;
        $report = $this->baseReport($payload, $category, $this->policyResolver->policyFor($category), $now, $gate);

        $slot = match ($stage) {
            'STRUCTURE' => 'structure',
            'SIGNATURE' => 'cryptography',
            'SERVER_CACHE' => 'serverCache',
            default => 'accessPolicy',
        };
        $report['stages'][$slot] = $this->stage(false, $stage, $stageMessage, $now);

        return $this->reject($report, $reason, $detail);
    }
}
