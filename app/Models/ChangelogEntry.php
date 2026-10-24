<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChangelogEntry extends Model
{
    use HasFactory;

    protected $fillable = ['version', 'released_on', 'title', 'body'];

    protected function casts(): array
    {
        return ['released_on' => 'date'];
    }

    public function scopeNewestFirst($query)
    {
        return $query->orderByDesc('released_on');
    }
}
