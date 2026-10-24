<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_date',
        'statement_balance_minor',
        'ledger_balance_minor',
        'difference_minor',
        'reconciled_by',
        'status',
        'notes',
    ];

    protected $casts = [
        'bank_statement_date' => 'date',
        'statement_balance_minor' => 'integer',
        'ledger_balance_minor' => 'integer',
        'difference_minor' => 'integer',
    ];

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
