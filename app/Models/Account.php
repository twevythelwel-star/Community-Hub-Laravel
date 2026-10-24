<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'currency',
        'user_id',
        'fundraiser_id',
        'balance_minor',
        'description',
        'is_active',
    ];

    protected $casts = [
        'balance_minor' => 'integer',
        'is_active' => 'boolean',
    ];

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    public const CODE_PAYMENT_CLEARING = '1010';

    public const CODE_STRIPE_CLEARING = '1020';

    public const CODE_BANK_OPERATING = '1030';

    public const CODE_RESIDENT_RECEIVABLES = '1200';

    public const CODE_PREPAID_LIABILITIES = '2010';

    public const CODE_HOA_REVENUE = '4010';

    public const CODE_MAINTENANCE_REVENUE = '4020';

    public const CODE_LATE_FEE_REVENUE = '4030';

    public const CODE_AMENITY_REVENUE = '4040';

    public const CODE_GATE_FEE_REVENUE = '4050';

    public const CODE_EVENT_TICKET_REVENUE = '4060';

    public const CODE_FUNDRAISING_CONTRIBUTIONS = '4070';

    public const CODE_FUNDRAISING_SPONSORSHIPS = '4080';

    public const CODE_COMMUNITY_PROJECT = '4090';

    public const CODE_EMERGENCY_RESERVE = '4100';

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class)->latest();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fundraiser(): BelongsTo
    {
        return $this->belongsTo(Fundraiser::class);
    }

    public function balance(): float
    {
        return (float) ($this->balance_minor / 100);
    }

    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    public static function getOrCreateResidentAccount(User $user): self
    {
        $userCode = Transaction::formatUserCode($user->id);
        $code = "1200-{$user->id}";

        return static::firstOrCreate(
            ['code' => $code],
            [
                'name' => "Resident Account — {$user->display_name} ({$userCode})",
                'type' => self::TYPE_ASSET,
                'currency' => 'USD',
                'user_id' => $user->id,
                'description' => "Sub-ledger for resident {$user->display_name} on lot ".($user->lot ?? 'N/A'),
            ]
        );
    }

    public static function getOrCreateFundraiserAccount(Fundraiser $fundraiser): self
    {
        $code = "4070-{$fundraiser->id}";

        return static::firstOrCreate(
            ['code' => $code],
            [
                'name' => "Campaign Fund — {$fundraiser->title}",
                'type' => self::TYPE_REVENUE,
                'currency' => strtoupper($fundraiser->goal_currency ?: 'USD'),
                'fundraiser_id' => $fundraiser->id,
                'description' => "Campaign ledger for {$fundraiser->title}",
            ]
        );
    }
}
