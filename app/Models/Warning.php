<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/** Community warnings. Vote tallies are derived from warning_responses rather than stored counters. */
class Warning extends Model implements HasMedia
{
    use Auditable, HasFactory, InteractsWithMedia, Searchable;

    protected $fillable = ['title', 'description', 'author_id', 'author_name', 'issued_at'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(WarningResponse::class);
    }

    public function confirmsCount(): int
    {
        return $this->responses()->where('response', 'confirmed')->count();
    }

    public function deniesCount(): int
    {
        return $this->responses()->where('response', 'denied')->count();
    }

    /** The current viewer vote: confirmed, denied, or null. */
    public function responseFor(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        return $this->responses->firstWhere('user_id', $user->id)?->response;
    }

    /**
     * Register media collections for incident evidence.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence');
    }

    /**
     * Register conversions for photo evidence thumbnails.
     */
    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->nonQueued();
    }

    /**
     * Get the indexable data array for Scout.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'description' => (string) $this->description,
            'author_name' => (string) $this->author_name,
        ];
    }
}
