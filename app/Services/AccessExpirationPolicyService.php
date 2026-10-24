<?php

namespace App\Services;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Models\AccessAuditTimelineEvent;
use App\Models\GatePass;
use Carbon\Carbon;

class AccessExpirationPolicyService
{
    /**
     * Compute mandatory expiration timestamp based on category policy.
     * Prevents forgotten authorizations from remaining active indefinitely.
     */
    public function computeMandatoryExpiration(PassCategory|string $category, ?array $context = null): Carbon
    {
        $cat = is_string($category) ? (PassCategory::tryFrom($category) ?? PassCategory::Visitor) : $category;
        $now = Carbon::now();

        return match ($cat) {
            // Visitor → expires tonight (23:59:59)
            PassCategory::Visitor => $now->copy()->endOfDay(),

            // Contractor → expires this Friday at 18:00 (or upcoming Friday if today is weekend)
            PassCategory::Contractor => $now->isFriday() && $now->hour < 18
                ? $now->copy()->setTime(18, 0, 0)
                : $now->copy()->next(Carbon::FRIDAY)->setTime(18, 0, 0),

            // Long-Term Occupant → expires according to verified lease date (fallback: 1 year from now)
            PassCategory::LongTermOccupant, PassCategory::Renter => isset($context['lease_expires_at'])
                ? Carbon::parse($context['lease_expires_at'])->endOfDay()
                : $now->copy()->addYear()->endOfDay(),

            // Caregiver / Domestic Support → expires December 31 of current calendar year
            PassCategory::HomeownerStaff => $now->copy()->endOfYear(),

            // Legacy Contact / Delegate → expires per authorization window (max 90 days)
            PassCategory::Delegate => isset($context['valid_until'])
                ? min(Carbon::parse($context['valid_until']), $now->copy()->addDays(90)->endOfDay())
                : $now->copy()->addDays(90)->endOfDay(),

            // Permanent Homeowners and Admins
            default => $now->copy()->addYears(5),
        };
    }

    /**
     * Enforce mandatory expiration policy by sweeping all live passes.
     * Automatically transitions passes whose validity window has closed to EXPIRED.
     */
    public function sweepExpiredPasses(): int
    {
        $now = now();
        $expiredPasses = GatePass::query()
            ->whereIn('status', [PassStatus::Issued, PassStatus::Active, PassStatus::CheckedOut])
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', $now)
            ->get();

        $count = 0;
        foreach ($expiredPasses as $pass) {
            if ($pass->canTransitionTo(PassStatus::Expired)) {
                $pass->transitionTo(PassStatus::Expired, null, 'Mandatory policy expiration window elapsed');
            } else {
                $pass->update([
                    'status' => PassStatus::Expired,
                    'status_changed_at' => $now,
                ]);
            }

            AccessAuditTimelineEvent::create([
                'pass_id' => $pass->pass_id,
                'user_id' => $pass->user_id,
                'holder_name' => $pass->holder_name,
                'gate' => 'SYSTEM_POLICY',
                'event_type' => 'CREDENTIAL_DENIED',
                'severity' => 'NOTICE',
                'headline' => 'Policy Window Expired',
                'description' => "Pass #{$pass->pass_id} ({$pass->category->label()}) automatically expired. Window closed at {$pass->valid_until->toDayDateTimeString()}.",
                'actor_type' => 'SYSTEM',
                'occurred_at' => $now,
            ]);

            $count++;
        }

        return $count;
    }
}
