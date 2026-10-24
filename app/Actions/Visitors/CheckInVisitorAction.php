<?php

namespace App\Actions\Visitors;

use App\Enums\GateId;
use App\Enums\VisitorStatus;
use App\Events\VisitorCheckedInEvent;
use App\Exceptions\ScanNotConfirmable;
use App\Models\AccessLogEntry;
use App\Models\BlocklistEntry;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GateScanner;
use Illuminate\Validation\ValidationException;

class CheckInVisitorAction
{
    public function __construct(
        protected GateScanner $scanner
    ) {}

    /**
     * Check in a visitor at the gate.
     *
     * @throws ValidationException|ScanNotConfirmable
     */
    public function execute(Visitor $visitor, User $user, GateId $gateId, string $gateName): void
    {
        if ($visitor->expired_at !== null) {
            throw ValidationException::withMessages([
                'visitor' => 'This clearance expired after the no-show grace period. Ask the resident to re-register the visitor.',
            ]);
        }

        if ($visitor->status === VisitorStatus::CheckedIn) {
            throw ValidationException::withMessages([
                'visitor' => 'This visitor is already checked in.',
            ]);
        }

        if (BlocklistEntry::blocks($visitor->name)) {
            $visitor->update(['is_blocked' => true]);

            AccessLogEntry::create([
                'user_id' => $visitor->homeowner_id,
                'user_name' => $visitor->name,
                'user_role' => 'Visitor',
                'method' => 'Visitor Pass',
                'gate' => $gateName,
                'result' => 'DENY',
                'deny_reason' => 'BLOCKLIST: Visitor is on the community blocklist.',
                'scanned_by' => $user->id,
                'occurred_at' => now(),
            ]);

            throw ValidationException::withMessages([
                'visitor' => 'Denied — this visitor is on the blocklist.',
            ]);
        }

        if ($visitor->gatePass) {
            $this->scanner->manualCheckIn($visitor->gatePass, $user, $gateId);

            return;
        }

        $visitor->checkIn();

        event(new VisitorCheckedInEvent($visitor, $gateName));

        AccessLogEntry::create([
            'user_id' => $visitor->homeowner_id,
            'user_name' => $visitor->name,
            'user_role' => 'Visitor',
            'method' => 'Visitor Pass',
            'gate' => $gateName,
            'result' => 'ALLOW',
            'scanned_by' => $user->id,
            'occurred_at' => now(),
        ]);
    }
}
