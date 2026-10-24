<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationMatch extends Model
{
    use HasFactory;

    public const STATUS_MATCHED = 'MATCHED';

    public const STATUS_MISSING_PROVIDER = 'MISSING_PROVIDER';

    public const STATUS_MISSING_COMMUNITYHUB = 'MISSING_COMMUNITYHUB';

    public const STATUS_AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';

    public const STATUS_CURRENCY_MISMATCH = 'CURRENCY_MISMATCH';

    public const STATUS_DUPLICATE = 'DUPLICATE';

    public const STATUS_REFUND_MISMATCH = 'REFUND_MISMATCH';

    public const STATUS_UNRESOLVED = 'UNRESOLVED';

    protected $fillable = [
        'reconciliation_batch_id',
        'bank_transaction_id',
        'transaction_id',
        'match_type',
        'status',
        'provider',
        'provider_reference',
        'confidence_score',
        'discrepancy_details',
        'resolution_action',
        'resolution_notes',
        'matched_by',
        'matched_at',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'confidence_score' => 'integer',
        'discrepancy_details' => 'array',
        'matched_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReconciliationBatch::class, 'reconciliation_batch_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED;
    }

    public function isDiscrepancy(): bool
    {
        return $this->status !== self::STATUS_MATCHED;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
