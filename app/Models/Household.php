<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function primaryHomeowner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_homeowner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class)->orderBy('id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active');
    }
}
