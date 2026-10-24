<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'entry_id',
        'transaction_id',
        'account_id',
        'entry_type',
        'amount_minor',
        'currency',
        'description',
        'balance_after_minor',
        'reconciled_at',
        'bank_reconciliation_id',
        'metadata',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'balance_after_minor' => 'integer',
        'reconciled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const TYPE_DEBIT = 'debit';

    public const TYPE_CREDIT = 'credit';

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $entry->entry_id ??= static::generateEntryId();
        });
    }

    /** LED-YYYY-NNNNNNNNNN from a locked per-year counter; see IdSequence. */
    public static function generateEntryId(?int $year = null, ?int $seq = null): string
    {
        $year ??= (int) date('Y');
        $seq ??= IdSequence::next('ledger_entry', $year);

        return sprintf('LED-%04d-%010d', $year, $seq);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    public function amount(): float
    {
        return (float) ($this->amount_minor / 100);
    }

    public function isReconciled(): bool
    {
        return $this->reconciled_at !== null;
    }
}
