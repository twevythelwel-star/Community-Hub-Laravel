<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Amounts are held in minor units (cents) so totals never drift through float
 * arithmetic — the original front end summed plain JS numbers.
 */
class Fundraiser extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'beneficiary', 'cover_image_url', 'images', 'goal_minor', 'goal_currency',
        'start_date', 'end_date', 'status', 'created_by', 'allow_anonymous', 'allow_recurring',
        'suggested_amounts', 'matching_sponsor', 'matching_multiplier', 'max_matching_minor',
        'fund_allocation', 'show_leaderboard',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'goal_minor' => 'integer',
            'allow_anonymous' => 'boolean',
            'allow_recurring' => 'boolean',
            'suggested_amounts' => 'array',
            'matching_multiplier' => 'integer',
            'max_matching_minor' => 'integer',
            'fund_allocation' => 'array',
            'show_leaderboard' => 'boolean',
            'images' => 'array',
        ];
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(FundraiserUpdate::class)->latest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function goal(): float
    {
        return (float) ($this->goal_minor / 100);
    }

    /** Total raised (net of refunds), in minor units, matching the fundraiser's goal currency. */
    public function raisedMinor(): int
    {
        return (int) $this->donations()
            ->where('currency', $this->goal_currency)
            ->counted()
            ->sum('amount_minor');
    }

    public function raised(): float
    {
        return (float) ($this->raisedMinor() / 100);
    }

    public function refundedMinor(): int
    {
        return (int) $this->donations()
            ->where('currency', $this->goal_currency)
            ->where('status', 'refunded')
            ->sum('amount_minor');
    }

    public function refunded(): float
    {
        return (float) ($this->refundedMinor() / 100);
    }

    public function progressPercent(): float
    {
        if ($this->goal_minor <= 0) {
            return 0.0;
        }

        return round(min(100, ($this->raisedMinor() / $this->goal_minor) * 100), 1);
    }

    public function donorCount(): int
    {
        return $this->donations()
            ->counted()
            ->count();
    }

    public function isOpen(): bool
    {
        return $this->status === 'Active' && $this->end_date->isFuture();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'Active');
    }
}
