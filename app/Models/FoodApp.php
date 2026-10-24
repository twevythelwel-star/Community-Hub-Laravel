<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FoodApp extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'logo_url', 'website_url', 'ai_hint', 'coupon_percentage'];

    protected function casts(): array
    {
        return ['coupon_percentage' => 'integer'];
    }
}
