<?php

namespace App\Models;

use App\Enums\PaymentState;
use App\Exceptions\IllegalPaymentTransition;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One payment attempt, and the state it is in.
 *
 * The state only moves through transitionTo(), which refuses anything
 * PaymentState does not allow and records every step in
 * payment_state_transitions. Money that actually moved is in the ledger
 * (`transactions`), whose rows point back here.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'idempotency_key',
        'applies_to',
        'purpose',
        'channel',
        'provider',
        'state',
        'user_id',
        'invoice_id',
        'fundraiser_id',
        'donation_id',
        'payment_link_id',
        'payment_method_id',
        'amount_minor',
        'currency',
        'provider_session_id',
        'provider_payment_id',
        'payer_reference',
        'device_identifier',
        'payment_terminal_id',
        'terminal_id',
        'location_id',
        'invoice_item_ids',
        'metadata',
        'failure_reason',
        'retry_of_payment_id',
        'received_by',
        'received_at',
        'bank_reference',
        'verified_by',
        'verified_at',
        'bank_reconciliation_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => PaymentState::class,
            'amount_minor' => 'integer',
            'invoice_item_ids' => 'array',
            'metadata' => 'array',
            'received_at' => 'datetime',
            'verified_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The payer's number from the moment the payment exists; the ledger
        // row written when it is paid carries the same number.
        static::creating(function (self $payment) {
            $payment->transaction_id ??= Transaction::generateTransactionId();
        });
    }

    /**
     * Begin a payment in its first state, with that step on record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function start(PaymentState $state, array $attributes, ?User $actor = null, string $source = 'system'): self
    {
        return DB::transaction(function () use ($state, $attributes, $actor, $source): self {
            $payment = self::create([...$attributes, 'state' => $state]);

            $payment->transitions()->create([
                'from_state' => null,
                'to_state' => $state,
                'actor_id' => $actor?->id,
                'source' => $source,
            ]);

            return $payment;
        });
    }

    /**
     * Move to `$to`, or refuse.
     *
     * Moving to the state the payment is already in is a no-op, so a webhook
     * delivered twice, or a second partial refund, needs no special case.
     *
     * @param  array<string, mixed>  $attributes  written in the same update
     *
     * @throws IllegalPaymentTransition
     */
    public function transitionTo(
        PaymentState $to,
        ?User $actor = null,
        string $source = 'system',
        ?string $note = null,
        array $attributes = [],
    ): self {
        if ($this->state === $to) {
            if ($attributes !== []) {
                $this->update($attributes);
            }

            return $this;
        }

        if (! $this->state->canTransitionTo($to)) {
            throw new IllegalPaymentTransition($this, $to);
        }

        return DB::transaction(function () use ($to, $actor, $source, $note, $attributes): self {
            $from = $this->state;

            $this->update([...$attributes, 'state' => $to]);

            $this->transitions()->create([
                'from_state' => $from,
                'to_state' => $to,
                'actor_id' => $actor?->id,
                'source' => $source,
                'note' => $note,
            ]);

            return $this;
        });
    }

    /**
     * transitionTo() for events that may arrive late or out of order, such as
     * processor webhooks: a transition the machine does not allow from here
     * is skipped rather than thrown, so a stale event is acknowledged instead
     * of being retried for days. Returns whether the payment moved.
     */
    public function advanceTo(PaymentState $to, string $source, ?string $note = null, array $attributes = []): bool
    {
        if ($this->state !== $to && ! $this->state->canTransitionTo($to)) {
            return false;
        }

        $this->transitionTo($to, null, $source, $note, $attributes);

        return true;
    }

    /** The ledger row recording this payment's money, once it is Paid. */
    public function ledgerPayment(): ?Transaction
    {
        return $this->ledgerEntries()->where('status', Transaction::STATUS_COMPLETED)->oldest('id')->first();
    }

    /**
     * The transaction slip shown to the payer, from what is on record.
     *
     * @return array<string, mixed>
     */
    public function toSlip(): array
    {
        $user = $this->user;

        return [
            'transaction_id' => $this->transaction_id,
            'public_transaction_id' => $this->transaction_id,
            'user' => Transaction::formatUserCode($user),
            'property' => Transaction::formatPropertyCode($user, $this->invoice),
            'community' => Transaction::defaultCommunityCode(),
            'purpose' => $this->purpose,
            'invoice' => $this->invoice?->reference,
            'amount' => number_format($this->amount_minor / 100, 2, '.', ''),
            'currency' => $this->currency,
            'status' => strtoupper($this->state->value),
            'state_label' => $this->state->label(),
            'payment_method' => Transaction::formatPaymentMethod($this->channel),
            'method' => $this->channel,
            'provider' => $this->provider,
            'provider_transaction_id' => $this->provider_payment_id,
            'device' => $this->device_identifier,
            'terminal' => $this->terminal_id,
            'location' => $this->location_id,
            'created' => $this->created_at?->format('Y-m-d'),
        ];
    }

    public function scopeInState(Builder $query, PaymentState ...$states): Builder
    {
        return $query->whereIn('state', array_map(fn (PaymentState $s) => $s->value, $states));
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(PaymentStateTransition::class)->orderBy('id');
    }

    /** Money that moved for this payment: the payment itself, refunds, dispute markers. */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function fundraiser(): BelongsTo
    {
        return $this->belongsTo(Fundraiser::class);
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function paymentLink(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** The reader an in-person payment was taken on. */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PaymentTerminal::class, 'payment_terminal_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_payment_id');
    }

    /**
     * Build standard processor metadata array from this Payment model.
     *
     * @return array<string, string>
     */
    public function toProcessorMetadata(array $extra = []): array
    {
        return Transaction::buildProcessorMetadata(
            payment: $this,
            user: $this->user,
            invoice: $this->invoice,
            paymentType: $this->invoice_id ? 'HOA_ASSESSMENT' : ($this->donation_id ? 'DONATION' : 'PAYMENT'),
            extra: $extra
        );
    }
}
