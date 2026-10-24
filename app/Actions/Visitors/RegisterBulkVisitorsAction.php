<?php

namespace App\Actions\Visitors;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\BlocklistEntry;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterBulkVisitorsAction
{
    public function __construct(
        private readonly GatePassEngine $engine,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{createdCount: int, blockedNames: array<string>, message: string}
     */
    public function execute(array $validated, User $user): array
    {
        $passCategory = PassCategory::from($validated['pass_category'] ?? PassCategory::Visitor->value);

        // 1. Resolve intended timezone: request > property > user > app default
        $property = $user->property ?? $user->properties()->first();
        $targetTimezone = $validated['timezone'] ?? $property?->timezone ?? $user->timezone ?? config('app.timezone', 'America/Jamaica');

        // 2. Parse intended local date & time in that specific timezone
        if (! empty($validated['expected_date']) && ! empty($validated['expected_time'])) {
            $timePart = strlen($validated['expected_time']) === 5 ? $validated['expected_time'].':00' : $validated['expected_time'];
            $localCarbon = Carbon::createFromFormat('Y-m-d H:i:s', "{$validated['expected_date']} {$timePart}", $targetTimezone);
        } else {
            $raw = (string) $validated['expected_at'];
            if (! preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $raw)) {
                $clean = str_replace('T', ' ', substr($raw, 0, 19));
                $localCarbon = Carbon::parse($clean, $targetTimezone);
            } else {
                $localCarbon = Carbon::parse($raw)->setTimezone($targetTimezone);
            }
        }

        // 3. Convert to UTC exactly once at the persistence boundary
        $expectedAtUtc = $localCarbon->copy()->setTimezone('UTC');

        // Check stay timeframe for temporary residents
        if ($user->role === UserRole::TemporaryHomeowner) {
            $stay = $user->activeStay() ?? Renter::where('user_id', $user->id)->first();
            if (! $stay || $stay->leaseHasExpired()) {
                throw ValidationException::withMessages([
                    'expected_at' => 'Your temporary stay has expired or is inactive. You cannot register visitors.',
                ]);
            }

            $stayStart = $stay->lease_start->startOfDay()->setTimezone('UTC');
            $stayEnd = $stay->lease_end->endOfDay()->setTimezone('UTC');
            if ($expectedAtUtc->lt($stayStart) || $expectedAtUtc->gt($stayEnd)) {
                throw ValidationException::withMessages([
                    'expected_at' => "Visitors can only be scheduled within your approved stay timeframe ({$stay->lease_start->format('M d, Y')} to {$stay->lease_end->format('M d, Y')}).",
                ]);
            }
        }

        $createdCount = 0;
        $blockedNames = [];

        DB::transaction(function () use ($validated, $user, $expectedAtUtc, $passCategory, &$createdCount, &$blockedNames) {
            foreach ($validated['visitors'] as $item) {
                $name = trim($item['name']);
                if (BlocklistEntry::blocks($name)) {
                    $blockedNames[] = $name;

                    continue;
                }

                $visitor = Visitor::create([
                    'homeowner_id' => $user->id,
                    'homeowner_name' => $user->display_name,
                    'name' => $name,
                    'contact' => $item['contact'] ?? null,
                    'vehicle' => $item['vehicle'] ?? null,
                    'type' => 'One-time',
                    'expected_at' => $expectedAtUtc,
                    'status' => 'Expected',
                    'is_blocked' => false,
                    'notify_email' => $validated['notify_email'] ?? false,
                    'notify_sms' => $validated['notify_sms'] ?? false,
                    'notify_whatsapp' => $validated['notify_whatsapp'] ?? false,
                ]);

                $pass = $this->engine->issueGuestPass($visitor, $user, $passCategory);
                $createdCount++;

                $channels = $visitor->passNotificationChannels();
                if ($channels !== [] && $pass->status !== PassStatus::Requested) {
                    $visitor->sendPassNotification($channels);
                }
            }
        });

        $msg = "Registered {$createdCount} event attendee(s) with pre-cleared guest passes.";
        if (! empty($blockedNames)) {
            $msg .= ' Note: '.count($blockedNames).' attendee(s) could not be registered due to active community blocklist restrictions: '.implode(', ', $blockedNames);
        }

        return [
            'createdCount' => $createdCount,
            'blockedNames' => $blockedNames,
            'message' => $msg,
        ];
    }
}
