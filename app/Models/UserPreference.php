<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Server-side replacement for the theme and map toggles previously kept in localStorage. */
class UserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'theme', 'theme_preset', 'map_show_boundary', 'map_show_landmarks',
        'notify_email', 'notify_push', 'notify_sms', 'extra',
    ];

    protected function casts(): array
    {
        return [
            'map_show_boundary' => 'boolean',
            'map_show_landmarks' => 'boolean',
            'notify_email' => 'boolean',
            'notify_push' => 'boolean',
            'notify_sms' => 'boolean',
            'extra' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
