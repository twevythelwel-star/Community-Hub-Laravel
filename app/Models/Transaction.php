<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Transaction extends Model
{
    use Auditable, HasFactory, Searchable;

    protected $fillable = [
        'payment_id',
        'transaction_id',
        'user_code',
        'property_code',
        'community_code',
        'purpose',
        'payment_method',
        'provider',
        'provider_reference',
        'provider_event_id',
        'idempotency_key',
        'provider_status',
        'device_identifier',
        'terminal_id',
        'location_id',
        'user_id',
        'payment_method_id',
        'invoice_id',
        'invoice_item_id',
        'payment_link_id',
        'fundraiser_id',
        'donation_id',
        'invoice_item_ids',
        'amount_minor',
        'fee_minor',
        'net_amount_minor',
        'currency',
        'payment_channel',
        'reference',
        'status',
        'reviewed_by',
        'reviewed_at',
        'settled_at',
        'authorized_at',
        'captured_at',
        'failed_at',
        'refunded_at',
        'payout_reference',
        'dispute_reason',
        'receipt_number',
        'proof_url',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'fee_minor' => 'integer',
        'net_amount_minor' => 'integer',
        'settled_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'authorized_at' => 'datetime',
        'captured_at' => 'datetime',
        'failed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'invoice_item_ids' => 'array',
        'metadata' => 'array',
    ];

    /*
     | Ledger statuses. A row in this table records money that moved:
     | `completed` (received), `refunded`, and the dispute markers `disputed`
     | and `reinstated`. Where a payment stands before and after that — started,
     | awaiting transfer, received by the office, verified, failed — is the
     | payment's state (App\Enums\PaymentState on App\Models\Payment), not a
     | status here. `failed` rows are Stripe failure notes kept for the audit
     | trail; `pending` and the other placeholder statuses exist only on rows
     | written before payments had their own table.
     */
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    /** Placeholder statuses from before payments had their own table; never money. */
    private const PLACEHOLDER_STATUSES = [
        'pending', 'payment_started', 'requires_action', 'awaiting_bank_transfer', 'received', 'verified', 'rejected',
    ];

    /** Canonical Revenue & Fee Purposes */
    public const PURPOSE_HOA_ASSESSMENT = 'HOA Assessment';

    public const PURPOSE_MAINTENANCE_FEE = 'Maintenance Fee';

    public const PURPOSE_LATE_FEE = 'Late Fee';

    public const PURPOSE_AMENITY_BOOKING = 'Amenity Booking';

    public const PURPOSE_GATE_ACCESS_FEE = 'Gate Access Fee';

    public const PURPOSE_EVENT_TICKET = 'Event Ticket';

    public const PURPOSE_FUNDRAISING_DONATION = 'Fundraising Donation';

    public const PURPOSE_FUNDRAISING_SPONSORSHIP = 'Fundraising Sponsorship';

    public const PURPOSE_COMMUNITY_PROJECT = 'Community Project';

    public const PURPOSE_EMERGENCY_FUND = 'Emergency Fund';

    protected static function booted(): void
    {
        static::creating(function (self $tx) {
            $tx->transaction_id ??= static::generateTransactionId();
            $tx->reference ??= $tx->transaction_id;
            $tx->receipt_number ??= 'REC-'.date('Ymd').'-'.strtoupper(Str::random(6));

            if ($tx->user_id && ! $tx->user_code) {
                $tx->user_code = static::formatUserCode($tx->user_id);
            }
            if (! $tx->property_code) {
                $user = $tx->user ?: ($tx->user_id ? User::find($tx->user_id) : null);
                $tx->property_code = static::formatPropertyCode($user);
            }
            if (! $tx->community_code) {
                $tx->community_code = static::formatCommunityCode();
            }
            if (! $tx->purpose) {
                $tx->purpose = $tx->fundraiser_id
                    ? static::PURPOSE_FUNDRAISING_DONATION
                    : static::PURPOSE_HOA_ASSESSMENT;
            }
            if (! $tx->payment_method && $tx->payment_channel) {
                $tx->payment_method = static::formatPaymentMethod($tx->payment_channel);
            }
            if (! $tx->provider && $tx->payment_channel) {
                $tx->provider = static::resolveDefaultProvider($tx->payment_channel);
            }
        });

        static::created(function (self $tx) {
            TransactionEvent::log(
                $tx,
                'created',
                $tx->status,
                $tx->provider_reference,
                'Created CommunityHub Transaction '.$tx->transaction_id
            );
        });
    }

    /**
     * The next CH-YYYY-NNNNNNNNNN number, from a locked per-year counter
     * (see IdSequence). Payments take theirs when they are created; the
     * ledger row that records a paid payment reuses the payment's number.
     */
    public static function generateTransactionId(?int $year = null, ?int $seq = null): string
    {
        $year ??= (int) date('Y');
        $seq ??= IdSequence::next('transaction', $year);

        return sprintf('CH-%04d-%010d', $year, $seq);
    }

    public static function formatUserCode(int|User|null $user): ?string
    {
        $id = $user instanceof User ? $user->id : $user;

        return $id ? sprintf('USR-%06d', $id) : null;
    }

    /**
     * PROP-NNNNN from the household's lot number, or an already formatted
     * code passed through. Null when neither is known: this used to fall
     * back to PROP-00481, a lot that belongs to someone else, or to digits
     * pulled out of an invoice reference.
     */
    public static function formatPropertyCode(?User $user = null, ?Invoice $invoice = null, ?string $code = null): ?string
    {
        if ($code && preg_match('/^PROP-\d{5}$/', $code)) {
            return $code;
        }

        $user ??= $invoice?->user;

        if ($user && $user->lot && preg_match('/\d+/', $user->lot, $m)) {
            return sprintf('PROP-%05d', (int) $m[0]);
        }

        return null;
    }

    public static function formatCommunityCode(?string $code = null): string
    {
        if ($code && preg_match('/COMM-\d+/', $code)) {
            return $code;
        }

        return static::defaultCommunityCode();
    }

    public static function defaultCommunityCode(): string
    {
        return 'COMM-001';
    }

    /**
     * Build standard, non-custodial metadata payload for payment processors (e.g. Stripe).
     *
     * Provides bidirectional linkage between processor events and CommunityHub entities
     * without transmitting or storing sensitive cardholder or bank credentials:
     *   Stripe transaction ↔ CommunityHub transaction ↔ homeowner ↔ property ↔ invoice
     *
     * @return array<string, string>
     */
    public static function buildProcessorMetadata(
        string|Payment $payment,
        ?User $user = null,
        ?Invoice $invoice = null,
        ?string $paymentType = null,
        array $extra = []
    ): array {
        $paymentRecord = $payment instanceof Payment ? $payment : null;
        $transactionId = $paymentRecord ? $paymentRecord->transaction_id : (string) $payment;
        $user ??= $paymentRecord?->user ?? $invoice?->user;
        $invoice ??= $paymentRecord?->invoice;

        $paymentType ??= $invoice ? 'HOA_ASSESSMENT' : ($paymentRecord?->donation_id ? 'DONATION' : 'PAYMENT');

        $metadata = [
            'communityhub_transaction_id' => $transactionId,
            'user_id' => static::formatUserCode($user) ?? 'USR-000000',
            'property_id' => static::formatPropertyCode($user, $invoice) ?? 'PROP-00000',
            'community_id' => static::defaultCommunityCode(),
            'invoice_id' => $invoice?->reference ?? ($paymentRecord?->invoice_id ? (string) $paymentRecord->invoice_id : 'N/A'),
            'payment_type' => $paymentType,
        ];

        if ($paymentRecord) {
            $metadata['payment_id'] = (string) $paymentRecord->id;
        }

        if ($invoice) {
            $metadata['internal_invoice_id'] = (string) $invoice->id;
        }

        return array_merge($metadata, $extra);
    }

    public static function formatPaymentMethod(string $channel): string
    {
        $map = [
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'samsung_wallet' => 'Samsung Wallet',
            'card' => 'Credit / Debit Card',
            'stripe_card' => 'Credit / Debit Card',
            'bank_wire' => 'Bank Transfer',
            'zelle' => 'Zelle',
            'cash_app' => 'Cash App',
            'qr_code' => 'QR Code',
            'nfc_pos' => 'Tap to Pay / Contactless',
            'cash_office' => 'Cash at Office',
            'wallet' => 'Community Wallet',
        ];

        return $map[$channel] ?? ucwords(str_replace('_', ' ', $channel));
    }

    /**
     * The default provider label for a ledger row that does not name one.
     *
     * Payments set their provider explicitly (ProviderRegistry): card and
     * device wallets name the estate's card processor. This default covers
     * rows written without one — older rows, where Apple/Google/Samsung Pay
     * were still office-confirmed — so it does not claim Stripe for money
     * Stripe never handled.
     */
    public static function resolveDefaultProvider(string $channel): string
    {
        return match ($channel) {
            'card', 'stripe_card' => 'stripe',
            'wallet' => 'internal',
            default => 'office',
        };
    }

    /** The payment attempt this movement of money belongs to. */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function paymentLink(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class);
    }

    public function fundraiser(): BelongsTo
    {
        return $this->belongsTo(Fundraiser::class);
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class);
    }

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(PaymentDispute::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** A placeholder row from before payments had their own table: not money, no receipt. */
    public function isPending(): bool
    {
        return in_array(strtolower((string) $this->status), self::PLACEHOLDER_STATUSES, true);
    }

    /** Money received. Every total in the app sums these rows. */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function formattedAmount(): string
    {
        return '$'.number_format($this->amount_minor / 100, 2).' '.$this->currency;
    }

    public function decimalAmount(): float
    {
        return (float) ($this->amount_minor / 100);
    }

    /**
     * The transaction slip for this ledger row.
     *
     * Fields the row does not have are null. This used to fill them in: an
     * unsaved new CH- number, invoice "INV-YYYY-00481", provider "stripe",
     * currency "USD", so the slip showed numbers that exist nowhere.
     */
    public function toTransactionCardPayload(): array
    {
        return [
            'transaction_id' => $this->transaction_id,
            'public_transaction_id' => $this->transaction_id,
            'user' => $this->user_code,
            'property' => $this->property_code,
            'community' => $this->community_code,
            'purpose' => $this->purpose,
            'invoice' => $this->invoice?->reference,
            'amount' => number_format($this->amount_minor / 100, 2, '.', ''),
            'currency' => strtoupper((string) $this->currency),
            'status' => strtoupper((string) $this->status),
            'payment_method' => $this->payment_method ?? ($this->payment_channel ? static::formatPaymentMethod($this->payment_channel) : null),
            'method' => $this->payment_channel,
            'provider' => $this->provider,
            'provider_transaction_id' => $this->provider_reference,
            'device' => $this->device_identifier,
            'terminal' => $this->terminal_id,
            'location' => $this->location_id,
            'created' => $this->created_at?->format('Y-m-d'),
        ];
    }

    /**
     * Get the indexable data array for Scout.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'transaction_id' => (string) $this->transaction_id,
            'reference' => (string) $this->reference,
            'purpose' => (string) $this->purpose,
            'status' => (string) $this->status,
            'user_code' => (string) $this->user_code,
            'property_code' => (string) $this->property_code,
            'receipt_number' => (string) $this->receipt_number,
        ];
    }
}
