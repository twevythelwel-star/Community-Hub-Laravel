<?php

namespace App\Services;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Models\GatePass;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Credentials\GatePassPolicyResolver;
use App\Services\Credentials\GatePassTokenSigner;
use App\Services\Credentials\GatePassTokenValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Digital Gate Pass Engine coordinator.
 *
 *   [Scan] -> [1 STRUCTURE] -> [2 SIGNATURE] -> [3 REGISTRY] -> [4 POLICY] -> CHECK_IN | CHECK_OUT | REJECT
 *
 * Coordinates pass lifecycle, cryptographic signing, policy resolution, and validation.
 */
class GatePassEngine
{
    private readonly GatePassTokenSigner $signer;

    private readonly GatePassPolicyResolver $policyResolver;

    private readonly GatePassTokenValidator $validator;

    public function __construct(
        private readonly ?string $secret = null,
        ?GatePassTokenSigner $signer = null,
        ?GatePassPolicyResolver $policyResolver = null,
        ?GatePassTokenValidator $validator = null,
    ) {
        if (blank($this->secret ?? config('gatepass.secret'))) {
            throw new RuntimeException(
                'GATE_ENGINE_SECRET is not set. Generate one with: php artisan gatepass:secret'
            );
        }

        $this->signer = $signer ?? new GatePassTokenSigner($this->secret);
        $this->policyResolver = $policyResolver ?? new GatePassPolicyResolver;
        $this->validator = $validator ?? new GatePassTokenValidator($this->signer, $this->policyResolver);
    }

    /**
     * Mints a dynamic, signed token for a pass.
     *
     * The validity window is aligned to a rolling slot (default 30s) so the same
     * pass yields the same token within a slot and a screenshot dies with it.
     * `$gate` narrows an any-gate pass to one gate; it cannot widen a pass.
     *
     * @return array{token: string, payload: array<string, mixed>, valid_from: CarbonImmutable, valid_until: CarbonImmutable}
     */
    public function issueToken(GatePass $pass, ?GateId $gate = null, ?int $windowSeconds = null): array
    {
        $window = $windowSeconds ?? (int) config('gatepass.window_seconds', 30);
        $nowSec = CarbonImmutable::now()->getTimestamp();

        $windowIndex = intdiv($nowSec, $window);
        $validFrom = $windowIndex * $window;
        $validUntil = $validFrom + $window;

        $uidTail = substr((string) ($pass->user?->uid ?? $pass->pass_id), -4);
        $nonce = sprintf(
            'NONCE-%s-%s%s',
            $uidTail,
            strtoupper(dechex($windowIndex)),
            $pass->rotation_seq > 1 ? '-S'.$pass->rotation_seq : ''
        );

        $payload = [
            'gpe' => config('gatepass.protocol.engine_id', 'GPE'),
            'cid' => config('gatepass.default_community_id'),
            'v' => (int) config('gatepass.protocol.version', 1),
            'pid' => $pass->pass_id,
            'cat' => $pass->category->value,
            'zone' => $this->policyResolver->zoneOf($pass),
            'uid' => (string) ($pass->user?->uid ?? ''),
            'nam' => $pass->holder_name,
            'prop' => $pass->property ?? 'Unassigned',
            'gate' => ($gate ?? $pass->designated_gate ?? GateId::Any)->value,
            'vf' => $validFrom,
            'vu' => $validUntil,
            'nonce' => $nonce,
            't' => $nowSec,
            'cvar' => $this->policyResolver->variantFor($pass)['id'],
            'seq' => $pass->rotation_seq,
        ];

        $signature = $this->signer->sign($payload);
        $payload['sig'] = $signature;
        $encodedPayload = $this->signer->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'token' => config('gatepass.protocol.envelope_prefix').$encodedPayload.'.'.$signature,
            'payload' => $payload,
            'valid_from' => CarbonImmutable::createFromTimestamp($validFrom),
            'valid_until' => CarbonImmutable::createFromTimestamp($validUntil),
        ];
    }

    /**
     * The account's pass, issued on first use.
     *
     * Account passes are auto-approved: the account itself is the approval, so
     * the pass is created ACTIVE, multi-entry, with no end date, and a random
     * approved colour.
     */
    public function issuePassFor(User $user): GatePass
    {
        return $user->gatePasses()->whereNull('visitor_id')->whereNull('delegated_access_id')->latest('id')->first()
            ?? $this->createAccountPass($user);
    }

    /**
     * A new pass for an account whose last one was revoked or expired. The old
     * pass keeps its ID and history; the new one gets the next ID.
     */
    public function reissuePassFor(User $user, User $by): GatePass
    {
        $current = $user->gatePasses()->whereNull('visitor_id')->whereNull('delegated_access_id')->latest('id')->first();

        if ($current && ! $current->status->isTerminal()) {
            throw new RuntimeException("Pass {$current->pass_id} is still {$current->status->label()}; revoke it before reissuing.");
        }

        return $this->createAccountPass($user, $by, 'Reissued by '.$by->display_name);
    }

    private function createAccountPass(User $user, ?User $by = null, string $reason = 'Issued with the account'): GatePass
    {
        $category = $user->passCategory();

        $pass = GatePass::create([
            'pass_id' => $this->policyResolver->uniquePassId($category, (string) $user->id),
            'user_id' => $user->id,
            'category' => $category,
            'holder_name' => $user->display_name,
            'property' => $user->propertyLabel(),
            'access_zone' => $category->defaultZone(),
            'designated_gate' => GateId::Any,
            'single_entry' => false,
            'color_variant' => $this->policyResolver->randomApprovedColor($category)['id'],
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
            'status_changed_at' => now(),
        ]);

        $pass->recordCreation($by, $reason);

        return $pass;
    }

    /**
     * Requests a pass for a visitor or contractor registered by a resident.
     */
    public function issueGuestPass(Visitor $visitor, User $host, PassCategory $category = PassCategory::Visitor): GatePass
    {
        if (! $category->isGuest()) {
            throw new RuntimeException("{$category->value} is not a guest pass category.");
        }

        [$validFrom, $validUntil] = $this->guestWindow($visitor);

        return DB::transaction(function () use ($visitor, $host, $category, $validFrom, $validUntil) {
            $pass = GatePass::create([
                'pass_id' => $this->policyResolver->uniquePassId($category, (string) $visitor->id),
                'visitor_id' => $visitor->id,
                'category' => $category,
                'holder_name' => $visitor->name,
                'property' => $visitor->property?->label() ?? $host->propertyLabel(),
                'access_zone' => $category->defaultZone(),
                'designated_gate' => GateId::Any,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'single_entry' => $visitor->type !== 'Recurring',
                'color_variant' => $this->policyResolver->randomApprovedColor($category)['id'],
                'rotation_seq' => 1,
                'status' => PassStatus::Requested,
                'status_changed_at' => now(),
                'metadata' => [
                    'visitor_id' => $visitor->id,
                    'host_name' => $host->display_name,
                    'property' => $visitor->property?->label() ?? $host->propertyLabel(),
                    'parking_instructions' => $visitor->parkingInstructions(),
                    'arrival_window' => $visitor->arrivalWindowLabel(),
                    'arrival_window_start' => $visitor->arrival_window_start,
                    'arrival_window_end' => $visitor->arrival_window_end,
                    'community_rules' => $visitor->communityRules(),
                    'emergency_info' => $visitor->emergencyInfo(),
                    'notes' => $visitor->notes,
                ],
            ]);

            $pass->recordCreation($host, "Requested by {$host->display_name}");

            if (! $category->requiresApproval()) {
                $this->approve($pass, $host, 'Registered by the host resident');
            }

            return $pass;
        });
    }

    /** Approves a requested pass and issues it in one step. */
    public function approve(GatePass $pass, ?User $by = null, ?string $reason = null): GatePass
    {
        $pass->transitionTo(PassStatus::Approved, $by, $reason);

        return $pass->transitionTo(PassStatus::Issued, $by, 'Issued on approval');
    }

    /**
     * Brings a guest pass's window in line with its visitor record after the
     * host edits the arrival time or visit type.
     */
    public function syncGuestPass(GatePass $pass, Visitor $visitor): void
    {
        if (! in_array($pass->status, [PassStatus::Requested, PassStatus::Approved, PassStatus::Issued, PassStatus::Active], true)) {
            return;
        }

        [$validFrom, $validUntil] = $this->guestWindow($visitor);

        $pass->update([
            'holder_name' => $visitor->name,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'single_entry' => $visitor->type !== 'Recurring',
            'metadata' => array_merge($pass->metadata ?? [], [
                'parking_instructions' => $visitor->parkingInstructions(),
                'arrival_window' => $visitor->arrivalWindowLabel(),
                'community_rules' => $visitor->communityRules(),
                'emergency_info' => $visitor->emergencyInfo(),
            ]),
        ]);
    }

    /**
     * The window a guest pass is valid for, from the visitor's expected
     * arrival and visit type.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function guestWindow(Visitor $visitor): array
    {
        $arrival = CarbonImmutable::parse($visitor->expected_at);
        $windows = config('gatepass.guest_windows');

        if (! empty($visitor->arrival_window_start) && ! empty($visitor->arrival_window_end)) {
            try {
                [$startH, $startM] = explode(':', $visitor->arrival_window_start);
                [$endH, $endM] = explode(':', $visitor->arrival_window_end);
                $from = $arrival->setTime((int) $startH, (int) $startM)->subMinutes((int) ($windows['opens_minutes_before'] ?? 15));
                $until = $arrival->setTime((int) $endH, (int) $endM)->addHours(2);

                if ($until->lessThanOrEqualTo($from)) {
                    $until = $until->addDay();
                }

                return [$from, $until];
            } catch (\Throwable) {
                // fallback below
            }
        }

        $from = $arrival->subMinutes((int) $windows['opens_minutes_before']);
        $until = $visitor->type === 'Recurring'
            ? $arrival->addDays((int) $windows['recurring_days'])
            : $arrival->addHours((int) $windows['one_time_hours']);

        return [$from, $until];
    }

    /**
     * Expires every pass whose window has closed. Called by the scheduler.
     */
    public function expireLapsedPasses(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $expired = 0;

        GatePass::query()
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', $now)
            ->whereIn('status', array_map(fn (PassStatus $s) => $s->value, [
                PassStatus::Requested, PassStatus::Approved, PassStatus::Issued,
                PassStatus::Active, PassStatus::CheckedOut, PassStatus::Suspended,
            ]))
            ->each(function (GatePass $pass) use (&$expired) {
                $pass->transitionTo(PassStatus::Expired, reason: 'Validity window closed');
                $expired++;
            });

        return $expired;
    }

    /**
     * Runs the full pipeline and returns a report.
     *
     * @return array<string, mixed>
     */
    public function validate(
        string $rawQr,
        ?GateId $currentGate = null,
        ?CarbonImmutable $now = null,
        bool $bypassReplayCheck = false,
    ): array {
        return $this->validator->validate($rawQr, $currentGate, $now, $bypassReplayCheck);
    }

    /** Drops nonce rows whose window has closed. Called by the scheduler. */
    public function pruneNonces(): int
    {
        return $this->signer->pruneNonces();
    }

    /** @return array{id: string, name: string, hex: string, accent_hex: string, contrast_ratio: float, wcag_pass: bool} */
    public function variantFor(GatePass $pass): array
    {
        return $this->policyResolver->variantFor($pass);
    }

    /** @return array{id: string, name: string, hex: string, accent_hex: string, contrast_ratio: float, wcag_pass: bool} */
    public function assignedColorVariant(PassCategory $category, ?string $seed = null, int $rotationSeq = 0): array
    {
        return $this->policyResolver->assignedColorVariant($category, $seed, $rotationSeq);
    }

    /**
     * @param  list<string>  $excludeColorIds
     * @return array{id: string, name: string, hex: string, accent_hex: string, contrast_ratio: float, wcag_pass: bool}
     */
    public function randomApprovedColor(PassCategory $category, array $excludeColorIds = []): array
    {
        return $this->policyResolver->randomApprovedColor($category, $excludeColorIds);
    }

    /** @return array{next_seq: int, variant: array<string, mixed>, rotated_at: mixed} */
    public function rotateVisualIdentity(GatePass $pass): array
    {
        return $this->policyResolver->rotateVisualIdentity($pass);
    }

    public function formatPassId(PassCategory $category, string $rawId): string
    {
        return $this->policyResolver->formatPassId($category, $rawId);
    }

    /** @return array<string, mixed> */
    public function policyFor(PassCategory $category): array
    {
        return $this->policyResolver->policyFor($category);
    }

    /** @return array<string, mixed> */
    public function categoryConfig(PassCategory $category): array
    {
        return $this->policyResolver->categoryConfig($category);
    }
}
