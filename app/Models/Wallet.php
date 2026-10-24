<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'available_balance_minor',
        'pending_balance_minor',
        'rewards_balance_minor',
        'currency',
        'auto_reload_enabled',
        'auto_reload_threshold_minor',
        'auto_reload_amount_minor',
    ];

    protected $casts = [
        'available_balance_minor' => 'integer',
        'pending_balance_minor' => 'integer',
        'rewards_balance_minor' => 'integer',
        'auto_reload_enabled' => 'boolean',
        'auto_reload_threshold_minor' => 'integer',
        'auto_reload_amount_minor' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function totalUsableMinor(): int
    {
        return $this->available_balance_minor + $this->rewards_balance_minor;
    }

    public function credit(int $amountMinor, string $description, string $balanceType = 'available'): WalletTransaction
    {
        $field = match ($balanceType) {
            'pending' => 'pending_balance_minor',
            'rewards' => 'rewards_balance_minor',
            default => 'available_balance_minor',
        };

        $this->increment($field, $amountMinor);

        return $this->transactions()->create([
            'amount_minor' => $amountMinor,
            'currency' => $this->currency,
            'balance_type' => $balanceType,
            'type' => 'credit',
            'reference' => 'WAL-CR-'.strtoupper(Str::random(10)),
            'description' => $description,
        ]);
    }

    public function debit(int $amountMinor, string $description): ?WalletTransaction
    {
        if ($this->totalUsableMinor() < $amountMinor) {
            return null; // insufficient funds
        }

        // Deduct rewards first, then available balance
        $remaining = $amountMinor;
        if ($this->rewards_balance_minor > 0) {
            $deductRewards = min($this->rewards_balance_minor, $remaining);
            $this->decrement('rewards_balance_minor', $deductRewards);
            $remaining -= $deductRewards;
        }

        if ($remaining > 0) {
            $this->decrement('available_balance_minor', $remaining);
        }

        return $this->transactions()->create([
            'amount_minor' => $amountMinor,
            'currency' => $this->currency,
            'balance_type' => 'available',
            'type' => 'debit',
            'reference' => 'WAL-DB-'.strtoupper(Str::random(10)),
            'description' => $description,
        ]);
    }
}
