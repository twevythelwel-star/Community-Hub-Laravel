<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Trait to automatically record comprehensive audit trails on model lifecycle events.
 * Captures who did it, what changed, when, which record, which IP, request URL, old values and new values.
 */
trait Auditable
{
    use LogsActivity;

    /**
     * Define default activity log options for the auditable model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        $logName = $this->getAuditLogName();
        $modelName = class_basename($this);

        return LogOptions::defaults()
            ->useLogName($logName)
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(function (string $eventName) use ($modelName) {
                return Str::headline($modelName)." {$eventName}";
            });
    }

    /**
     * Audit log channel/name for this model.
     */
    public function getAuditLogName(): string
    {
        return property_exists($this, 'auditLogName') ? $this->auditLogName : $this->getTable();
    }
}
