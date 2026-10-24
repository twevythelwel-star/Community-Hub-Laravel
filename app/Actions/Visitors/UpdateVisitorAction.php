<?php

namespace App\Actions\Visitors;

use App\Enums\UserRole;
use App\Models\BlocklistEntry;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class UpdateVisitorAction
{
    public function __construct(
        protected GatePassEngine $engine
    ) {}

    /**
     * Update visitor details, verifying blocklist and stay bounds, and syncing pass.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function execute(Visitor $visitor, array $validated, User $user): void
    {
        // Server-side blocklist check if name was changed
        if (isset($validated['name']) && $validated['name'] !== $visitor->name) {
            if (BlocklistEntry::blocks($validated['name'])) {
                throw ValidationException::withMessages([
                    'name' => 'This person is on the community blocklist and cannot be registered. Contact security.',
                ]);
            }
        }

        // Temporary Homeowners can only edit visitors within their approved timeframe
        if ($user->role === UserRole::TemporaryHomeowner) {
            $stay = $user->activeStay() ?? Renter::where('user_id', $user->id)->first();
            if (! $stay || $stay->leaseHasExpired()) {
                throw ValidationException::withMessages([
                    'expected_at' => 'Your temporary stay has expired or is inactive. You cannot modify visitors.',
                ]);
            }

            if (isset($validated['expected_at'])) {
                $expectedDate = Carbon::parse($validated['expected_at'])->startOfDay();
                $leaseStart = $stay->lease_start->startOfDay();
                $leaseEnd = $stay->lease_end->endOfDay();

                if ($expectedDate->lt($leaseStart) || $expectedDate->gt($leaseEnd)) {
                    throw ValidationException::withMessages([
                        'expected_at' => "Visitors can only be scheduled within your approved stay timeframe ({$stay->lease_start->format('M d, Y')} to {$stay->lease_end->format('M d, Y')}).",
                    ]);
                }
            }
        }

        $visitor->update($validated);

        if ($visitor->gatePass) {
            $this->engine->syncGuestPass($visitor->gatePass, $visitor->fresh());
        }
    }
}
