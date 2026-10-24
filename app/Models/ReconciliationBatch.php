<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationBatch extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_BALANCED = 'balanced';

    public const STATUS_DISCREPANCY = 'discrepancy';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'batch_number',
        'community_id',
        'period_start',
        'period_end',
        'statement_balance_minor',
        'ledger_balance_minor',
        'variance_minor',
        'matched_count',
        'unmatched_count',
        'discrepancy_count',
        'resolved_count',
        'status',
        'conducted_by',
        'verified_by',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'statement_balance_minor' => 'integer',
        'ledger_balance_minor' => 'integer',
        'variance_minor' => 'integer',
        'matched_count' => 'integer',
        'unmatched_count' => 'integer',
        'discrepancy_count' => 'integer',
        'resolved_count' => 'integer',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ReconciliationMatch::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(ReconciliationMatch::class)->where('status', '!=', ReconciliationMatch::STATUS_MATCHED);
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isBalanced(): bool
    {
        return $this->variance_minor === 0 && $this->discrepancy_count === $this->resolved_count;
    }
}
