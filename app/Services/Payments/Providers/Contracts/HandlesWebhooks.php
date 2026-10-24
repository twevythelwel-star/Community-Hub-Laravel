<?php

namespace App\Services\Payments\Providers\Contracts;

use Illuminate\Http\Request;

/** A provider that reports outcomes server-to-server, signed. */
interface HandlesWebhooks
{
    /** Whether the request genuinely comes from the provider. */
    public function verifyWebhook(Request $request): bool;

    /**
     * Apply one verified delivery. Returns a short outcome for the response
     * and logs. Must be idempotent: providers retry and repeat deliveries.
     */
    public function handleWebhook(Request $request): string;
}
