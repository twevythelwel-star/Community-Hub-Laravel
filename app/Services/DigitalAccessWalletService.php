<?php

namespace App\Services;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Exceptions\WalletPassUnavailable;
use App\Models\GatePass;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\Credentials\AppleWalletPass;
use App\Services\Credentials\GoogleWalletPass;
use App\Services\Credentials\WalletCredentialCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DigitalAccessWalletService
{
    public function __construct(
        private readonly GatePassEngine $engine,
        private readonly GateScanner $scanner,
        private readonly HouseholdManagementService $householdService,
        private readonly WalletCredentialCode $walletCodes,
        private readonly AppleWalletPass $appleWallet,
        private readonly GoogleWalletPass $googleWallet,
    ) {}

    /**
     * Retrieve all wallet credentials accessible to the given user.
     * Includes personal pass, all household members' passes, and delegated passes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getWalletCredentials(User $user): Collection
    {
        $passes = collect();

        // 1. User's personal account pass
        $personalPass = $this->engine->issuePassFor($user);
        if ($personalPass) {
            $passes->push($this->formatWalletPass($personalPass, 'Primary Account Holder', $user));
        }

        // 2. Household members' passes (if user is homeowner or in a household)
        $household = Household::where('primary_homeowner_id', $user->id)
            ->with(['members.gatePass'])
            ->first();

        if ($household) {
            foreach ($household->members as $member) {
                if ($member->gatePass && $member->gatePass->id !== $personalPass?->id) {
                    $passes->push($this->formatWalletPass(
                        $member->gatePass,
                        $member->relationship_label ?: ucfirst(str_replace('_', ' ', $member->role_in_household)),
                        null,
                        $member
                    ));
                }
            }
        }

        return $passes->unique('pass_id')->values();
    }

    /**
     * Format a GatePass into a rich multi-modal wallet credential.
     *
     * @return array<string, mixed>
     */
    public function formatWalletPass(
        GatePass $pass,
        string $relationshipLabel,
        ?User $user = null,
        ?HouseholdMember $member = null
    ): array {
        $category = $pass->category;
        $variant = $this->engine->variantFor($pass);
        $shape = $category->shape()->value;

        // Dynamic QR Token
        $qrToken = null;
        $validUntil = null;
        $secondsRemaining = 30;

        if ($pass->isActive()) {
            try {
                $issued = $this->engine->issueToken($pass);
                $qrToken = $issued['token'];
                $validUntil = $issued['valid_until']->toIso8601String();
                $secondsRemaining = max(0, $issued['valid_until']->getTimestamp() - now()->getTimestamp());
            } catch (\Throwable $e) {
                // No code rather than the bare pass ID, which the gate refuses.
                $qrToken = null;
            }
        }

        // Contactless NFC: the card UID, and the signed wallet code as the message.
        $nfcUid = $this->generateNfcUid($pass);
        $nfcPayload = $this->walletCodes->nfcMessageFor($pass);

        $schedule = $member?->access_schedule ?? $pass->metadata['access_schedule'] ?? null;
        $permissions = $member?->permissions ?? $pass->metadata['permissions'] ?? [];

        return [
            'id' => $pass->id,
            'pass_id' => $pass->pass_id,
            'holder_name' => $pass->holder_name,
            'relationship_label' => $relationshipLabel,
            'role_in_household' => $member?->role_in_household ?? ($user?->role->value ?? 'Resident'),
            'category' => $category->value,
            'category_label' => $category->label(),
            'shape' => $shape,
            'variant' => $variant,
            'property' => $pass->property ?: 'Unassigned',
            'status' => $pass->status->value,
            'status_label' => $pass->status->label(),
            'is_active' => $pass->isActive(),
            // Derived from the pass (GatePass::getOfflinePinAttribute), so never null.
            'offline_pin' => $pass->offline_pin,

            // Multi-Modal 1: QR Credential
            'qr' => [
                'token' => $qrToken,
                'valid_until' => $validUntil,
                'seconds_remaining' => $secondsRemaining,
                'envelope_prefix' => config('gatepass.protocol.envelope_prefix', 'GPE1.'),
                'standard' => 'ISO/IEC 18004 Tamper-Evident Anti-Replay QR',
            ],

            // Multi-Modal 2: Contactless NFC Tap Credential
            'nfc' => [
                'card_uid' => $nfcUid,
                'payload' => $nfcPayload,
                'protocol' => 'ISO/IEC 14443 Type A (NFC-A) / MIFARE DESFire EV3 / Apple VAS / Google Smart Tap',
                'frequency' => '13.56 MHz High Frequency',
                'status' => 'Antenna Field Ready — Tap to Authenticate',
                'ndef_mime' => 'application/vnd.communityhub.gatepass',
            ],

            // Multi-Modal 3: Mobile Wallet Integrations. Offered only once the
            // estate's Apple / Google credentials are configured (config/wallet.php).
            'wallet_integrations' => [
                'apple_wallet' => [
                    'available' => $this->appleWallet->isConfigured(),
                    'file_name' => "{$pass->pass_id}.pkpass",
                    'download_url' => url("/dashboard/wallet/apple-pass/{$pass->pass_id}"),
                    'badge_label' => 'Add to Apple Wallet',
                ],
                'google_wallet' => [
                    'available' => $this->googleWallet->isConfigured(),
                    'save_url' => url("/dashboard/wallet/google-pass/{$pass->pass_id}"),
                    'badge_label' => 'Save to Google Wallet',
                ],
            ],

            // Governance & Access Windows
            'schedule' => $schedule,
            'permissions' => $permissions,
            'designated_gate' => $pass->designated_gate?->label() ?? 'Any Gate',
            'expiry_date' => $member?->valid_until?->toFormattedDateString() ?? 'Perpetual Residence',
        ];
    }

    /**
     * Deterministic ISO 14443 Type A 7-byte UID for virtual NFC card presentation.
     */
    public function generateNfcUid(GatePass $pass): string
    {
        $hash = md5($pass->pass_id.':'.config('gatepass.secret', 'community_hub'));

        return sprintf(
            '04:%s:%s:%s:%s:%s:%s',
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            substr($hash, 4, 2),
            substr($hash, 6, 2),
            substr($hash, 8, 2),
            substr($hash, 10, 2)
        );
    }

    /**
     * A signed Apple Wallet pass (.pkpass).
     *
     * @throws WalletPassUnavailable while Apple Wallet is not configured for the estate.
     */
    public function generateApplePkpass(GatePass $pass): string
    {
        if (! $this->appleWallet->isConfigured()) {
            throw new WalletPassUnavailable('Apple Wallet passes are not set up for this estate yet.');
        }

        return $this->appleWallet->build($pass);
    }

    /**
     * A "Save to Google Wallet" link.
     *
     * @throws WalletPassUnavailable while Google Wallet is not configured for the estate.
     */
    public function googleWalletSaveUrl(GatePass $pass): string
    {
        if (! $this->googleWallet->isConfigured()) {
            throw new WalletPassUnavailable('Google Wallet passes are not set up for this estate yet.');
        }

        return $this->googleWallet->saveUrl($pass);
    }

    /**
     * One-button Lost/Stolen Credential Protocol:
     * 1. Immediately transitions the existing pass to REVOKED, recording timestamp and reason.
     *    Connected scanners reject old credentials; queued offline scans are revalidated on sync.
     * 2. Immediately generates a fresh replacement credential with a new pass ID, incremented rotation
     *    sequence, new cryptographic keys, and updated offline keypad PIN.
     *
     * @return array{
     *     success: bool,
     *     revoked_pass: array<string, mixed>,
     *     replacement_pass: array<string, mixed>,
     *     message: string
     * }
     */
    public function reportLostAndReplace(GatePass|string $passOrId, User $actor, ?string $reason = null): array
    {
        $oldPass = is_string($passOrId)
            ? GatePass::where('pass_id', $passOrId)->firstOrFail()
            : $passOrId;

        $revocationReason = $reason ?: 'Reported Lost/Stolen by Cardholder. Immediate revocation issued.';
        $newPass = null;
        $replacementFormatted = null;

        DB::transaction(function () use (&$oldPass, &$newPass, &$replacementFormatted, $actor, $revocationReason) {
            // 1. Immediately Revoke Old Pass
            if ($oldPass->canTransitionTo(PassStatus::Revoked)) {
                $oldPass->transitionTo(PassStatus::Revoked, $actor, $revocationReason);
            } else {
                $oldPass->update([
                    'status' => PassStatus::Revoked,
                    'status_changed_at' => now(),
                    'revoked_at' => now(),
                    'revoked_by' => $actor->id,
                    'revocation_reason' => $revocationReason,
                ]);
                $oldPass->transitions()->create([
                    'from_status' => $oldPass->status,
                    'to_status' => PassStatus::Revoked,
                    'actor_id' => $actor->id,
                    'reason' => $revocationReason,
                    'occurred_at' => now(),
                ]);
            }

            // 2. Generate Replacement Credential
            $member = HouseholdMember::where('gate_pass_id', $oldPass->id)->first();
            if ($member) {
                $household = $member->household;
                $seq = ($oldPass->rotation_seq ?? 1) + 1;
                $newPassId = sprintf('GP-HOM-%s-%04d-R%d',
                    strtoupper(substr(md5($household->id.$member->name), 0, 4)),
                    rand(1000, 9999),
                    $seq
                );
                while (GatePass::where('pass_id', $newPassId)->exists()) {
                    $newPassId = sprintf('GP-HOM-%s-%04d-R%d',
                        strtoupper(substr(md5($household->id.$member->name), 0, 4)),
                        rand(1000, 9999),
                        $seq++
                    );
                }

                $category = PassCategory::tryFrom($member->pass_category) ?? $oldPass->category;

                $newPass = GatePass::create([
                    'pass_id' => $newPassId,
                    'user_id' => $member->user_id ?? $household->primary_homeowner_id,
                    'category' => $category,
                    'holder_name' => $member->name,
                    'property' => $household->property_number,
                    'access_zone' => $oldPass->access_zone ?: $category->defaultZone(),
                    'designated_gate' => $oldPass->designated_gate ?? GateId::Any,
                    'status' => PassStatus::Active,
                    'rotation_seq' => $seq,
                    'status_changed_at' => now(),
                    'color_variant' => $this->engine->randomApprovedColor($category)['id'] ?? null,
                    'metadata' => array_merge($oldPass->metadata ?? [], [
                        'replaces_pass_id' => $oldPass->pass_id,
                        'replacement_reason' => $revocationReason,
                        'household_id' => $household->id,
                        'role_in_household' => $member->role_in_household,
                        'relationship' => $member->relationship_label,
                        'permissions' => $member->permissions,
                        'access_schedule' => $member->access_schedule,
                    ]),
                ]);

                $newPass->recordCreation($actor, "Replacement credential for lost pass {$oldPass->pass_id}");
                $member->update(['gate_pass_id' => $newPass->id]);

                $replacementFormatted = $this->formatWalletPass(
                    $newPass,
                    $member->relationship_label ?: ucfirst(str_replace('_', ' ', $member->role_in_household)),
                    null,
                    $member
                );
            } else {
                // User personal account pass or general pass
                $user = $oldPass->user ?? $actor;
                $seq = ($oldPass->rotation_seq ?? 1) + 1;
                $newPass = $this->engine->reissuePassFor($user, $actor);
                $newPass->update([
                    'rotation_seq' => $seq,
                    'metadata' => array_merge($oldPass->metadata ?? [], [
                        'replaces_pass_id' => $oldPass->pass_id,
                        'replacement_reason' => $revocationReason,
                    ]),
                ]);

                $replacementFormatted = $this->formatWalletPass(
                    $newPass,
                    'Primary Account Holder',
                    $user
                );
            }
        });

        return [
            'success' => true,
            'revoked_pass' => [
                'pass_id' => $oldPass->pass_id,
                'holder_name' => $oldPass->holder_name,
                'status' => 'REVOKED',
                'revoked_at' => $oldPass->revoked_at?->toIso8601String() ?? now()->toIso8601String(),
                'revocation_reason' => $oldPass->revocation_reason,
            ],
            'replacement_pass' => $replacementFormatted,
            'message' => "Credential {$oldPass->pass_id} has been permanently REVOKED and replacement credential {$newPass->pass_id} is active. Connected gate scanners reject the old credential immediately; scans queued during a network outage are revalidated when synced.",
        ];
    }

    /**
     * Retrieve all revoked credentials for this user or their household.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getRevokedCredentials(User $user): Collection
    {
        $household = Household::where('primary_homeowner_id', $user->id)->first();
        $memberPassIds = $household
            ? HouseholdMember::where('household_id', $household->id)->pluck('gate_pass_id')->filter()->all()
            : [];

        $revoked = GatePass::query()
            ->where(function ($q) use ($user, $memberPassIds) {
                $q->where('user_id', $user->id)
                    ->orWhereIn('id', $memberPassIds);
            })
            ->where(function ($q) {
                $q->where('status', PassStatus::Revoked)
                    ->orWhereNotNull('revoked_at');
            })
            ->latest('revoked_at')
            ->get();

        return $revoked->map(function (GatePass $pass) {
            return [
                'id' => $pass->id,
                'pass_id' => $pass->pass_id,
                'holder_name' => $pass->holder_name,
                'category' => $pass->category->value,
                'category_label' => $pass->category->label(),
                'property' => $pass->property,
                'status' => 'REVOKED',
                'revoked_at' => $pass->revoked_at?->toIso8601String(),
                'revoked_at_formatted' => $pass->revoked_at?->toDayDateTimeString(),
                'revocation_reason' => $pass->revocation_reason ?: 'Reported Lost/Stolen',
                'offline_pin' => $pass->offline_pin,
            ];
        });
    }
}
