<?php

namespace App\Services;

use App\Models\AccessAuditTimelineEvent;
use App\Models\GatePass;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AccessAuditTimelineService
{
    /**
     * Record a discrete forensic event in the access audit timeline.
     */
    public function recordEvent(
        string $passId,
        string $eventType,
        string $headline,
        ?string $description = null,
        string $gate = 'GATE-01',
        string $severity = 'INFO',
        string $actorType = 'SYSTEM',
        ?User $actor = null,
        ?array $telemetry = null,
        ?CarbonImmutable $occurredAt = null
    ): AccessAuditTimelineEvent {
        $pass = GatePass::where('pass_id', $passId)->first();

        return AccessAuditTimelineEvent::create([
            'pass_id' => $passId,
            'user_id' => $pass?->user_id ?? $actor?->id,
            'holder_name' => $pass?->holder_name ?? 'Unidentified Person',
            'gate' => $gate,
            'event_type' => $eventType,
            'severity' => $severity,
            'headline' => $headline,
            'description' => $description,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
            'telemetry' => $telemetry,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Retrieve complete forensic chronological timeline for a pass.
     */
    /**
     * Retrieve complete forensic chronological timeline for a pass.
     */
    public function getTimelineForPass(string $passId): Collection
    {
        return AccessAuditTimelineEvent::where('pass_id', $passId)
            ->with(['user', 'actor'])
            ->orderBy('occurred_at', 'asc')
            ->get()
            ->map(function ($event) {
                return [
                    'id' => $event->id,
                    'pass_id' => $event->pass_id,
                    'holder_name' => $event->holder_name,
                    'gate' => $event->gate,
                    'event_type' => $event->event_type,
                    'severity' => $event->severity,
                    'headline' => $event->headline,
                    'description' => $event->description,
                    'actor_type' => $event->actor_type,
                    'actor_name' => $event->actor?->name ?? $event->actor_type,
                    'telemetry' => $event->telemetry,
                    'occurred_at' => $event->occurred_at,
                    'date_formatted' => $event->occurred_at->format('M j, Y'),
                    'time_formatted' => $event->occurred_at->format('g:i A'),
                ];
            });
    }

    public function getPassTimeline(string $passId): Collection
    {
        return $this->getTimelineForPass($passId);
    }

    /**
     * Seed example realistic forensic audit timeline for demonstration & investigation disputes.
     * Mary Smith's Legacy QR visit: Scanned → Identity Verified → Security Approved → Gate Opened → Checked Out.
     */
    public function seedExampleMarySmithTimeline(?GatePass $pass = null): Collection
    {
        $passId = $pass?->pass_id ?? 'GP-MARY-1004';
        $userId = $pass?->user_id ?? null;
        $holderName = $pass?->holder_name ?? 'Mary Smith';
        $base = CarbonImmutable::parse('2026-10-04 20:43:00');

        $events = [
            [
                'pass_id' => $passId,
                'user_id' => $userId,
                'holder_name' => $holderName,
                'gate' => 'Main Gate (Gate 01)',
                'event_type' => 'CREDENTIAL_SCANNED',
                'severity' => 'INFO',
                'headline' => "Mary Smith's Legacy QR scanned at Main Gate",
                'description' => "Optical high-speed CMOS scanner captured 18004 dynamic QR payload. Envelope GPE1 authenticated.",
                'actor_type' => 'SENSOR',
                'occurred_at' => $base,
            ],
            [
                'pass_id' => $passId,
                'user_id' => $userId,
                'holder_name' => $holderName,
                'gate' => 'Main Gate (Gate 01)',
                'event_type' => 'IDENTITY_VERIFIED',
                'severity' => 'INFO',
                'headline' => 'Identity verified',
                'description' => 'Cryptographic HMAC signature confirmed. Holder verified against Unit 14 directory and facial match.',
                'actor_type' => 'SYSTEM',
                'occurred_at' => $base,
            ],
            [
                'pass_id' => $passId,
                'user_id' => $userId,
                'holder_name' => $holderName,
                'gate' => 'Main Gate (Gate 01)',
                'event_type' => 'SECURITY_APPROVED',
                'severity' => 'INFO',
                'headline' => 'Security approved entry',
                'description' => 'Officer Davis confirmed visual clearance and approved vehicular entry for Toyota Land Cruiser.',
                'actor_type' => 'GUARD',
                'occurred_at' => $base->addMinute(),
            ],
            [
                'pass_id' => $passId,
                'user_id' => $userId,
                'holder_name' => $holderName,
                'gate' => 'Main Gate (Gate 01)',
                'event_type' => 'GATE_OPENED',
                'severity' => 'NOTICE',
                'headline' => 'Gate opened',
                'description' => 'Pneumatic barrier arm raised. Inductive vehicle loop #1 triggered.',
                'actor_type' => 'BARRIER_HARDWARE',
                'occurred_at' => $base->addMinute(),
            ],
            [
                'pass_id' => $passId,
                'user_id' => $userId,
                'holder_name' => $holderName,
                'gate' => 'West Exit (Gate 02)',
                'event_type' => 'CHECKED_OUT',
                'severity' => 'INFO',
                'headline' => 'Mary checked out',
                'description' => 'Optical exit scanner recorded departure. Total stay duration: 2 hours 34 minutes.',
                'actor_type' => 'SENSOR',
                'occurred_at' => CarbonImmutable::parse('2026-10-04 23:17:00'),
            ],
        ];

        $models = collect();
        foreach ($events as $e) {
            $models->push(AccessAuditTimelineEvent::create($e));
        }

        return $models;
    }
}
