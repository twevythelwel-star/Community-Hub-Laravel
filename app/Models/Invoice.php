<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'property_id', 'reference', 'stripe_session_id', 'stripe_payment_intent',
        'amount_minor', 'currency', 'period_start', 'period_end', 'due_on', 'status', 'paid_at',
        'last_reminded_at', 'reminder_count',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_on' => 'date',
            'paid_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'reminder_count' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The property an HOA assessment is for; dues are charged per property. */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function paymentPlan(): HasOne
    {
        return $this->hasOne(PaymentPlan::class);
    }

    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class);
    }

    public function reminderDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function latestReminderDelivery(): HasOne
    {
        return $this->hasOne(NotificationDelivery::class)->latestOfMany();
    }


    /**
     * Generate a secure, opaque-token payment link for this invoice.
     * Contains NO sensitive balance, card, or PII information in the URL.
     */
    public function generatePaymentLink(?User $createdBy = null, ?\DateTimeInterface $expiresAt = null): PaymentLink
    {
        return PaymentLink::create([
            'token' => 'CH-'.strtoupper(bin2hex(random_bytes(10))),
            'title' => 'Invoice #'.$this->reference.' Payment',
            'description' => 'Payment for maintenance and community dues on '.$this->reference,
            'amount_minor' => $this->balanceRemainingMinor(),
            'currency' => $this->currency ?? 'JMD',
            'category' => 'hoa_dues',
            'user_id' => $this->user_id,
            'invoice_id' => $this->id,
            'expires_at' => $expiresAt ?? $this->due_on?->endOfDay() ?? now()->addDays(14),
            'max_uses' => 1,
            'active' => true,
            'created_by' => $createdBy?->id,
        ]);
    }

    public function amount(): float
    {
        return (float) ($this->amount_minor / 100);
    }

    /** Money received less money refunded, from the ledger. */
    public function amountPaidMinor(): int
    {
        $received = (int) $this->transactions()->where('status', 'completed')->sum('amount_minor');
        $refunded = (int) $this->transactions()->where('status', 'refunded')->sum('amount_minor');

        return max(0, $received - $refunded);
    }

    public function balanceRemainingMinor(): int
    {
        return max(0, $this->amount_minor - $this->amountPaidMinor());
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['Unpaid', 'Partially Paid']) && $this->due_on->isPast();
    }

    public function markPaid(): void
    {
        $this->update(['status' => 'Paid', 'paid_at' => now()]);
        $this->items()->update(['status' => 'Paid']);
    }

    /**
     * Paid when the ledger covers the invoice, Partially Paid when some of it
     * has arrived, left alone when nothing has.
     *
     * Decided from completed ledger rows, not from the payment just recorded,
     * so a statement paid in parts across channels reads correctly, and a
     * payment still awaiting confirmation changes nothing.
     */
    public function settleFromLedger(): void
    {
        if ($this->balanceRemainingMinor() <= 0) {
            $this->markPaid();
        } elseif ($this->amountPaidMinor() > 0) {
            $this->update(['status' => 'Partially Paid']);
        }
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', ['Unpaid', 'Overdue', 'Partially Paid']);
    }
}
