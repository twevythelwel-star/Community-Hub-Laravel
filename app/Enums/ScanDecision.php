<?php

namespace App\Enums;

/**
 * What a scan tells the guard to do. The server decides it from the pass's
 * current state; the scanner only displays it and asks the guard to confirm.
 */
enum ScanDecision: string
{
    case CheckIn = 'CHECK_IN';
    case CheckOut = 'CHECK_OUT';
    case Reject = 'REJECT';

    /** The state a confirmed decision moves the pass to. */
    public function targetStatus(): ?PassStatus
    {
        return match ($this) {
            self::CheckIn => PassStatus::CheckedIn,
            self::CheckOut => PassStatus::CheckedOut,
            self::Reject => null,
        };
    }
}
