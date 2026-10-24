<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

class EnterpriseLogActivityAction extends LogActivityAction
{
    /**
     * Intercept and enrich activity records with security and network metadata.
     */
    public function execute(Model $activity, string $description): Model
    {
        $properties = $activity->properties ? $activity->properties->toArray() : [];

        $request = request();

        $auditMetadata = [
            'ip' => $request ? ($request->ip() ?? '127.0.0.1') : '127.0.0.1',
            'url' => $request ? $request->fullUrl() : 'cli',
            'method' => $request ? $request->method() : (app()->runningInConsole() ? 'CLI' : 'UNKNOWN'),
            'user_agent' => $request ? ($request->userAgent() ?? (app()->runningInConsole() ? 'Console/Artisan' : 'Unknown Agent')) : 'System',
        ];

        // Merge properties without overriding existing explicit properties
        $activity->properties = collect(array_merge($auditMetadata, $properties));

        return parent::execute($activity, $description);
    }
}
