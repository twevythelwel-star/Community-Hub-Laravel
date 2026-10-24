<?php

namespace App\Enums;

/**
 * Where one payment attempt stands. Enforced by Payment::transitionTo().
 *
 * Every state is one a real event can put a payment in — a processor's
 * webhook, or an administrator's action — never one only the browser knows.
 *
 * Processor payments (card through Stripe Checkout, or a future local PSP):
 *
 *     Created ─┬─► RequiresAction ─┬─► Processing ─► Succeeded ─► Paid
 *              │                   │                    ▲
 *              └─► Failed ◄────────┘                    │
 *                    │   (a declined card may be retried on the same
 *                    └── checkout page, so Failed can still succeed)
 *              Created / RequiresAction / Failed ─► Expired   (terminal)
 *              Created / RequiresAction / Processing / Failed ─► Canceled
 *                  (withdrawn — by the payer, or cleared from a card reader
 *                  before a card is presented; the provider refuses once
 *                  money is being authorised; terminal)
 *
 * Office-confirmed payments (bank wire, cash, QR, NFC, digital wallets,
 * Zelle, Cash App — channels the app cannot see). Received is logged by one
 * administrator; Verified is set in a bank reconciliation by a different one:
 *
 *     Created ─► AwaitingTransfer ─► Received ─► Verified ─► Paid
 *            └───────────────┴─► Rejected   (terminal)
 *     AwaitingTransfer ─► Canceled   (payer withdrew before sending; terminal)
 *
 * After payment, for both:
 *
 *     Paid ─► PartiallyRefunded ─► Refunded
 *       └──► Disputed ─┬─► Paid          (dispute won)
 *                      └─► ChargedBack   (dispute lost, terminal)
 *
 * Succeeded and Paid differ on purpose: Succeeded is "the processor has the
 * money", Paid is "it was applied to what it was for". A payment for an
 * invoice already settled at the office stays Succeeded until refunded.
 */
enum PaymentState: string
{
    case Created = 'created';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Failed = 'failed';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case Succeeded = 'succeeded';

    case AwaitingTransfer = 'awaiting_transfer';
    case Received = 'received';
    case Verified = 'verified';
    case Rejected = 'rejected';

    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
    case ChargedBack = 'charged_back';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            // A payment is created before its channel is known (the slip shown
            // to the payer); an office channel then moves it to AwaitingTransfer.
            self::Created => [self::RequiresAction, self::Processing, self::Succeeded, self::Failed, self::Expired, self::AwaitingTransfer, self::Canceled],
            self::RequiresAction => [self::Processing, self::Succeeded, self::Failed, self::Expired, self::Canceled],
            // Canceled from Processing or Failed only after the provider has
            // withdrawn it (e.g. a reader cleared before a card is presented);
            // the provider refuses once money is being authorised.
            self::Processing => [self::Succeeded, self::Failed, self::Canceled],
            self::Failed => [self::RequiresAction, self::Processing, self::Succeeded, self::Expired, self::Canceled],
            self::Succeeded => [self::Paid, self::PartiallyRefunded, self::Refunded, self::Disputed],

            self::AwaitingTransfer => [self::Received, self::Rejected, self::Canceled],
            self::Received => [self::Verified, self::Rejected],
            self::Verified => [self::Paid],

            self::Paid => [self::PartiallyRefunded, self::Refunded, self::Disputed],
            self::PartiallyRefunded => [self::Refunded, self::Disputed],
            self::Disputed => [self::Paid, self::ChargedBack],

            self::Expired, self::Canceled, self::Rejected, self::Refunded, self::ChargedBack => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Still waiting on the payer, the processor or the office. */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::Created, self::RequiresAction, self::Processing, self::Failed,
            self::AwaitingTransfer, self::Received, self::Verified,
        ], true);
    }

    /** Waiting on the community office rather than a processor. */
    public function awaitsOffice(): bool
    {
        return in_array($this, [self::AwaitingTransfer, self::Received], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Started',
            self::RequiresAction => 'Needs authentication',
            self::Processing => 'Processing',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
            self::Canceled => 'Canceled',
            self::Succeeded => 'Received by processor',
            self::AwaitingTransfer => 'Awaiting transfer',
            self::Received => 'Received, awaiting verification',
            self::Verified => 'Verified',
            self::Rejected => 'Not received',
            self::Paid => 'Paid',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Disputed => 'Disputed',
            self::ChargedBack => 'Charged back',
        };
    }
}
