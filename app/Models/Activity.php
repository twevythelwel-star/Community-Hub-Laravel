<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity as BaseActivity;

class Activity extends BaseActivity
{
    /**
     * IP address associated with the activity.
     */
    public function getIpAttribute(): ?string
    {
        return $this->getProperty('ip');
    }

    /**
     * Request URL associated with the activity.
     */
    public function getUrlAttribute(): ?string
    {
        return $this->getProperty('url');
    }

    /**
     * Request HTTP method (GET, POST, PUT, DELETE, CLI).
     */
    public function getMethodAttribute(): ?string
    {
        return $this->getProperty('method');
    }

    /**
     * User Agent / Client associated with the activity.
     */
    public function getUserAgentAttribute(): ?string
    {
        return $this->getProperty('user_agent');
    }

    /**
     * Old attribute values prior to change.
     *
     * @return array<string, mixed>
     */
    public function getOldValuesAttribute(): array
    {
        $old = data_get($this->attribute_changes, 'old')
            ?? $this->getProperty('old', []);

        if ($old instanceof Collection) {
            return $old->all();
        }

        return is_array($old) ? $old : (is_object($old) ? (array) $old : []);
    }

    /**
     * New attribute values after change.
     *
     * @return array<string, mixed>
     */
    public function getNewValuesAttribute(): array
    {
        $attributes = data_get($this->attribute_changes, 'attributes')
            ?? $this->getProperty('attributes', []);

        if ($attributes instanceof Collection) {
            return $attributes->all();
        }

        return is_array($attributes) ? $attributes : (is_object($attributes) ? (array) $attributes : []);
    }

    /**
     * Detailed diff breakdown of changes: attribute => ['old' => ..., 'new' => ...].
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function getChangesSummary(): array
    {
        $old = $this->old_values;
        $new = $this->new_values;
        $summary = [];

        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allKeys as $key) {
            $oldVal = $old[$key] ?? null;
            $newVal = $new[$key] ?? null;

            if ($oldVal !== $newVal) {
                $summary[$key] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        return $summary;
    }

    /**
     * Scope query to a specific IP address.
     */
    public function scopeForIp(Builder $query, string $ip): Builder
    {
        return $query->where('properties->ip', $ip);
    }

    /**
     * Scope query to recent records within N days.
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
