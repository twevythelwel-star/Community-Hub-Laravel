<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityUpdate extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'date', 'summary', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
