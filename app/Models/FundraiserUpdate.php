<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundraiserUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'fundraiser_id',
        'title',
        'content',
        'image_url',
    ];

    public function fundraiser(): BelongsTo
    {
        return $this->belongsTo(Fundraiser::class);
    }
}
