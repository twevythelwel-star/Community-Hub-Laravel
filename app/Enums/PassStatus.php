<?php

namespace App\Enums;

/**
 * Where a gate pass is in its life.
 *
 *   REQUESTED → APPROVED → ISSUED → ACTIVE → CHECKED_IN → CHECKED_OUT
 *
 * with REJECTED, CANCELLED, REVOKED, EXPIRED and SUSPENDED as the exits. The
 * transition table is the whole rule: GatePass::transitionTo() refuses any
 * move not listed here, so a scan, an admin action or a scheduled job cannot
 * put a pass into a state it could not legitimately reach.
 */
enum PassStatus: string
{
    case Requested = 'REQUESTED';
    case Approved = 'APPROVED';
    case Issued = 'ISSUED';
    case Active = 'ACTIVE';
    case CheckedIn = 'CHECKED_IN';
    case CheckedOut = 'CHECKED_OUT';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case Revoked = 'REVOKED';
    case Expired = 'EXPIRED';
    case Suspended = 'SUSPENDED';

    /**
     * The states each state may move to.
     *
     * CHECKED_OUT → CHECKED_IN is allowed only for a multi-entry pass; that
     * rule depends on the pass, so GatePass::canTransitionTo() applies it.
     * CHECKED_IN deliberately cannot expire: someone still inside must be
     * checked out, however late, so the log shows when they actually left.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled, self::Expired],
            self::Approved => [self::Issued, self::Cancelled, self::Revoked, self::Expired],
            self::Issued => [self::Active, self::Cancelled, self::Revoked, self::Expired, self::Suspended],
            self::Active => [self::CheckedIn, self::Suspended, self::Revoked, self::Expired, self::Cancelled],
            self::CheckedIn => [self::CheckedOut, self::Revoked, self::Suspended],
            self::CheckedOut => [self::CheckedIn, self::Revoked, self::Expired, self::Suspended],
            self::Suspended => [self::Active, self::Revoked, self::Expired],
            self::Rejected, self::Cancelled, self::Revoked, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** No way back from these. */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** The pass is (or may become) usable at the gate. */
    public function isLive(): bool
    {
        return in_array($this, [self::Issued, self::Active, self::CheckedIn, self::CheckedOut], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Issued => 'Issued',
            self::Active => 'Active',
            self::CheckedIn => 'Checked In',
            self::CheckedOut => 'Checked Out',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Revoked => 'Revoked',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
        };
    }
}
