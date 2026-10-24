<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'method_type',
        'provider_customer_id',
        'provider_payment_method_id',
        'display_name',
        'last_four',
        'brand',
        'wallet_type',
        'bank_name',
        'status',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Resolves or registers a non-custodial payment method token.
     */
    public static function resolveForUser(
        User $user,
        string $methodType,
        string $provider = 'stripe',
        array $attributes = []
    ): self {
        return static::firstOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
                'method_type' => $methodType,
                'wallet_type' => $attributes['wallet_type'] ?? null,
            ],
            array_merge([
                'display_name' => $attributes['display_name'] ?? ucwords(str_replace('_', ' ', $methodType)),
                'provider_customer_id' => $attributes['provider_customer_id'] ?? null,
                'provider_payment_method_id' => $attributes['provider_payment_method_id'] ?? null,
                'last_four' => $attributes['last_four'] ?? null,
                'brand' => $attributes['brand'] ?? null,
                'bank_name' => $attributes['bank_name'] ?? null,
                'status' => 'active',
                'is_default' => true,
            ], $attributes)
        );
    }
}
