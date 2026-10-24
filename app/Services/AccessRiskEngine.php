<?php

namespace App\Services;

use App\Enums\DenyReason;
use App\Enums\GateId;
use App\Enums\PassStatus;
use App\Models\AccessAuditTimelineEvent;
use App\Models\AccessLogEntry;
use App\Models\AccessRiskIncident;
use App\Models\GatePass;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

class AccessRiskEngine
{
    /**
     * Analyze a gate scan attempt against the 8 threat detection pillars.
     * Generates real-time AccessRiskIncident alerts when suspicious behavior is detected.
     */
    public function analyzeScanAttempt(
        string $tokenOrPassId,
        GateId $gate,
        ?User $guard,
        array $scanResult
    ): ?AccessRiskIncident {
        $report = $scanResult['report'] ?? [];
        $decision = $scanResult['decision'] ?? 'REJECT';
        $denyReason = $report['denyReason'] ?? null;
        $passId = $report['passId'] ?? null;

        // If passId not directly in report, extract from candidate or token
        if (! $passId) {
            if (str_starts_with($tokenOrPassId, 'GP-')) {
                $passId = explode('|', $tokenOrPassId)[0];
            }
        }

        $pass = $passId ? GatePass::where('pass_id', $passId)->first() : null;
        $now = CarbonImmutable::now();

        // ── 1. Revoked QR / Leaked Screenshot Attempt (CRITICAL) ──
        if ($denyReason === DenyReason::PassRevoked->value || ($pass && $pass->isRevoked())) {
            return $this->createIncident([
                'pass_id' => $pass?->pass_id ?? $passId,
                'user_id' => $pass?->user_id,
                'gate' => $gate->value,
                'flag_type' => 'REVOKED_ATTEMPT',
                'risk_level' => 'CRITICAL',
                'title' => '⚠️ Suspicious Credential Activity: Revoked QR Presented',
                'description' => sprintf(
                    'Unauthorized entry attempt using revoked pass #%s at %s. Leaked screenshot or decommissioned card detected.',
                    $pass?->pass_id ?? $passId,
                    $gate->label()
                ),
                'evidence' => [
                    'pass_id' => $pass?->pass_id ?? $passId,
                    'holder_name' => $pass?->holder_name ?? 'Unknown',
                    'revoked_at' => $pass?->revoked_at?->toIso8601String(),
                    'revocation_reason' => $pass?->revocation_reason,
                    'gate' => $gate->value,
                    'scan_decision' => $decision,
                    'scanned_by' => $guard?->name,
                ],
                'occurred_at' => $now,
            ]);
        }

        // ── 2. Simultaneous Multi-Gate Presentation (Cloned Credential / Impossible Transit) (CRITICAL) ──
        if ($passId) {
            $recentScan = AccessLogEntry::where('pass_id', $passId)
                ->where('occurred_at', '>=', $now->subMinutes(3))
                ->latest('occurred_at')
                ->first();

            if ($recentScan && $recentScan->gate !== $gate->value) {
                $secondsBetween = $now->diffInSeconds($recentScan->occurred_at);
                if ($secondsBetween <= 90) {
                    return $this->createIncident([
                        'pass_id' => $passId,
                        'user_id' => $pass?->user_id,
                        'gate' => $gate->value,
                        'flag_type' => 'SIMULTANEOUS_MULTI_GATE',
                        'risk_level' => 'CRITICAL',
                        'title' => '🚨 Credential Cloning Detected: Simultaneous Multi-Gate Presentation',
                        'description' => sprintf(
                            'Pass #%s was presented at %s only %d seconds after presentation at %s (impossible transit). Active credential sharing or cloning alert.',
                            $passId,
                            $gate->label(),
                            $secondsBetween,
                            $recentScan->gate
                        ),
                        'evidence' => [
                            'primary_gate' => $recentScan->gate,
                            'secondary_gate' => $gate->value,
                            'seconds_delta' => $secondsBetween,
                            'first_scan_at' => $recentScan->occurred_at->toIso8601String(),
                            'second_scan_at' => $now->toIso8601String(),
                        ],
                        'occurred_at' => $now,
                    ]);
                }
            }
        }

        // ── 3. Excessive Failed Scans (HIGH RISK) ──
        if ($decision === 'REJECT') {
            $recentFailures = AccessLogEntry::where(function ($q) use ($passId, $tokenOrPassId) {
                if ($passId) {
                    $q->where('pass_id', $passId);
                }
            })
            ->where('result', 'DENY')
            ->where('occurred_at', '>=', $now->subMinutes(10))
            ->count();

            if ($recentFailures >= 3) {
                return $this->createIncident([
                    'pass_id' => $passId,
                    'user_id' => $pass?->user_id,
                    'gate' => $gate->value,
                    'flag_type' => 'EXCESSIVE_FAILURES',
                    'risk_level' => 'HIGH',
                    'title' => '⚠️ Suspicious Credential Activity: Excessive Failed Scans',
                    'description' => sprintf(
                        'Pass or holder experienced %d failed scan attempts within 10 minutes at %s.',
                        $recentFailures + 1,
                        $gate->label()
                    ),
                    'evidence' => [
                        'failure_count' => $recentFailures + 1,
                        'latest_deny_reason' => $denyReason,
                        'gate' => $gate->value,
                    ],
                    'occurred_at' => $now,
                ]);
            }
        }

        // ── 4. Expired QR / Stale Credential Attempt (MEDIUM RISK) ──
        if ($denyReason === DenyReason::OutsideValidity->value || ($pass && $pass->valid_until && $pass->valid_until->isPast())) {
            return $this->createIncident([
                'pass_id' => $pass?->pass_id ?? $passId,
                'user_id' => $pass?->user_id,
                'gate' => $gate->value,
                'flag_type' => 'EXPIRED_ATTEMPT',
                'risk_level' => 'MEDIUM',
                'title' => '⚠️ Expired Credential Presented at Gate',
                'description' => sprintf(
                    'Expired pass #%s was presented at %s. Pass expired on %s.',
                    $pass?->pass_id ?? $passId,
                    $gate->label(),
                    $pass?->valid_until?->toDayDateTimeString() ?? 'prior window'
                ),
                'evidence' => [
                    'valid_until' => $pass?->valid_until?->toIso8601String(),
                    'gate' => $gate->value,
                ],
                'occurred_at' => $now,
            ]);
        }

        // ── 5. Unusual Hours / Midnight Curfew Scan (MEDIUM RISK) ──
        $hour = $now->hour;
        if (($hour >= 1 && $hour <= 4) && $pass && ! in_array($pass->category->value, ['SECURITY', 'SYSADMIN'])) {
            $isCurfewRestricted = $pass->metadata['access_schedule']['curfew_enabled'] ?? false;
            if ($isCurfewRestricted || $decision === 'REJECT') {
                return $this->createIncident([
                    'pass_id' => $pass->pass_id,
                    'user_id' => $pass->user_id,
                    'gate' => $gate->value,
                    'flag_type' => 'UNUSUAL_HOURS',
                    'risk_level' => 'MEDIUM',
                    'title' => '⚠️ Suspicious Hours: Late Night Curfew Scan',
                    'description' => sprintf(
                        'Pass #%s presented during high-risk overnight window (%02d:%02d) at %s.',
                        $pass->pass_id,
                        $now->hour,
                        $now->minute,
                        $gate->label()
                    ),
                    'evidence' => [
                        'scan_time' => $now->toTimeString(),
                        'holder_name' => $pass->holder_name,
                        'gate' => $gate->value,
                    ],
                    'occurred_at' => $now,
                ]);
            }
        }

        // ── 6. Unusual Scan Frequency (Burst Attack / Key Fob Cycling) ──
        if ($passId) {
            $burstCount = AccessLogEntry::where('pass_id', $passId)
                ->where('occurred_at', '>=', $now->subMinutes(5))
                ->count();

            if ($burstCount >= 5) {
                return $this->createIncident([
                    'pass_id' => $passId,
                    'user_id' => $pass?->user_id,
                    'gate' => $gate->value,
                    'flag_type' => 'UNUSUAL_FREQUENCY',
                    'risk_level' => 'HIGH',
                    'title' => '⚠️ Abnormal Presentation Frequency Detected',
                    'description' => sprintf(
                        'Pass #%s scanned %d times in 5 minutes. Potential RFID replay test or credential cycling.',
                        $passId,
                        $burstCount + 1
                    ),
                    'evidence' => [
                        'burst_scans' => $burstCount + 1,
                        'gate' => $gate->value,
                    ],
                    'occurred_at' => $now,
                ]);
            }
        }

        return null;
    }

    /**
     * Create and record an AccessRiskIncident and corresponding audit event.
     */
    private function createIncident(array $attributes): AccessRiskIncident
    {
        $incident = AccessRiskIncident::create($attributes);

        // Record in forensic audit timeline
        AccessAuditTimelineEvent::create([
            'pass_id' => $attributes['pass_id'] ?? 'UNKNOWN',
            'user_id' => $attributes['user_id'] ?? null,
            'holder_name' => $attributes['evidence']['holder_name'] ?? 'Unidentified Person',
            'gate' => $attributes['gate'],
            'event_type' => 'CREDENTIAL_DENIED',
            'severity' => $attributes['risk_level'] === 'CRITICAL' ? 'CRITICAL' : 'WARNING',
            'headline' => $attributes['title'],
            'description' => $attributes['description'],
            'actor_type' => 'SYSTEM',
            'telemetry' => $attributes['evidence'],
            'occurred_at' => $attributes['occurred_at'],
        ]);

        return $incident;
    }

    /**
     * Lock down a compromised or suspicious credential.
     */
    public function lockdownCredential(string $passId, User $actor, string $reason): GatePass
    {
        $pass = GatePass::where('pass_id', $passId)->firstOrFail();

        $pass->update([
            'status' => PassStatus::Suspended,
            'status_changed_at' => now(),
            'metadata' => array_merge($pass->metadata ?? [], [
                'lockdown_by' => $actor->id,
                'lockdown_reason' => $reason,
                'locked_down_at' => now()->toIso8601String(),
            ]),
        ]);

        AccessRiskIncident::where('pass_id', $passId)
            ->where('status', 'NEW')
            ->update([
                'status' => 'LOCKED_DOWN',
                'resolved_by' => $actor->id,
                'resolution_notes' => 'Credential locked down and suspended: ' . $reason,
            ]);

        AccessAuditTimelineEvent::create([
            'pass_id' => $passId,
            'user_id' => $pass->user_id,
            'holder_name' => $pass->holder_name,
            'gate' => 'ALL_GATES',
            'event_type' => 'REVOCATION_ENFORCED',
            'severity' => 'CRITICAL',
            'headline' => '🚨 Security Lockdown Enforced',
            'description' => "Credential #{$passId} suspended by Officer {$actor->name}. Reason: {$reason}",
            'actor_type' => 'GUARD',
            'actor_id' => $actor->id,
            'occurred_at' => now(),
        ]);

        return $pass;
    }
}
