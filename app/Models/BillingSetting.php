<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Single-row settings table replacing the BillingProvider monthlyFee React state. */
class BillingSetting extends Model
{
    use HasFactory;

    protected $table = 'billing_settings';

    protected $fillable = ['monthly_fee_minor', 'currency', 'due_day_of_month'];

    protected function casts(): array
    {
        return ['monthly_fee_minor' => 'integer', 'due_day_of_month' => 'integer'];
    }

    /** Monthly fee as a major-unit float, for display. */
    public function monthlyFee(): float
    {
        return (float) ($this->monthly_fee_minor / 100);
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], []);
    }
}
