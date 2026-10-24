<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reconciliation_batch_id',
        'bank_name',
        'bank_reference',
        'transaction_date',
        'value_date',
        'description',
        'payer_reference',
        'amount_minor',
        'currency',
        'entry_type',
        'matched_transaction_id',
        'status',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'value_date' => 'date',
        'amount_minor' => 'integer',
    ];

    public function reconciliationBatch(): BelongsTo
    {
        return $this->belongsTo(ReconciliationBatch::class);
    }

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'matched_transaction_id');
    }
}
