<?php

namespace App\Actions\Visitors;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\BlocklistEntry;
use App\Models\GatePass;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class RegisterVisitorAction
{
    public function __construct(
        protected GatePassEngine $engine
    ) {}

    /**
     * Register a new visitor clearance, enforce stay and blocklist rules,
     * issue gate pass, and dispatch notifications.
     *
     * @param  array<string, mixed>  $validated
     * @return array{visitor: Visitor, pass: GatePass, message: string}
     *
     * @throws ValidationException
     */
    public function execute(array $validated, User $user): array
    {
        $passCategory = PassCategory::from($validated['pass_category'] ?? PassCategory::Visitor->value);
        unset($validated['pass_category']);

        // Server-side stay timeframe enforcement for Temporary Homeowners
        if ($user->role === UserRole::TemporaryHomeowner) {
            $stay = $user->activeStay() ?? Renter::where('user_id', $user->id)->first();
            if (! $stay || $stay->leaseHasExpired()) {
                throw ValidationException::withMessages([
                    'expected_at' => 'Your temporary stay has expired or is inactive. You cannot register visitors.',
                ]);
            }

            $expectedDate = Carbon::parse($validated['expected_at'])->startOfDay();
            $leaseStart = $stay->lease_start->startOfDay();
            $leaseEnd = $stay->lease_end->endOfDay();

            if ($expectedDate->lt($leaseStart) || $expectedDate->gt($leaseEnd)) {
                throw ValidationException::withMessages([
                    'expected_at' => "Visitors can only be registered within your approved stay timeframe ({$stay->lease_start->format('M d, Y')} to {$stay->lease_end->format('M d, Y')}).",
                ]);
            }
        }

        // Server-side blocklist enforcement
        if (BlocklistEntry::blocks($validated['name'])) {
            throw ValidationException::withMessages([
                'name' => 'This person is on the community blocklist and cannot be registered. Contact security.',
            ]);
        }

        // With only one property to choose from, the visit is to that one.
        $validated['property_id'] ??= ($own = $user->propertiesForVisitorManagement())->count() === 1 ? $own->first()->id : null;

        $visitor = Visitor::create([
            ...$validated,
            'status' => 'Expected',
            'homeowner_id' => $user->id,
            'homeowner_name' => $user->display_name,
            'is_blocked' => false,
            'notify_email' => $validated['notify_email'] ?? true,
            'notify_sms' => $validated['notify_sms'] ?? false,
            'notify_whatsapp' => $validated['notify_whatsapp'] ?? false,
        ]);

        $user->recordActivity("Registered visitor {$validated['name']}");

        $pass = $this->engine->issueGuestPass($visitor, $user, $passCategory);

        $channels = $visitor->passNotificationChannels();
        if ($channels !== [] && $pass->status !== PassStatus::Requested) {
            $visitor->sendPassNotification($channels);
        }

        $message = $pass->status === PassStatus::Requested
            ? 'Contractor registered. Their pass is waiting for security approval.'
            : 'Guest pre-registered successfully. Digital Guest Pass has been generated.';

        return [
            'visitor' => $visitor,
            'pass' => $pass,
            'message' => $message,
        ];
    }
}
