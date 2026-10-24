<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One ID per request, for following it through the logs.
 *
 * A caller's X-Correlation-ID (or X-Request-ID, as nginx and most load
 * balancers send) is kept when it looks like an ID; anything else — too
 * long, or carrying characters an ID never needs — is replaced with a fresh
 * UUID rather than written into every log line on a stranger's say-so.
 *
 * The ID goes into Laravel's Context, which adds it to every log entry and
 * queued job, and which Octane resets between requests. Log::withContext()
 * is not used as well: it duplicated the key, and lives on the logger, which
 * an Octane worker keeps across requests.
 *
 * It is also set as the request's X-Request-ID, so ApiLoggingMiddleware and
 * ApiResponse report the same ID instead of minting their own.
 */
class AssignCorrelationId
{
    public const HEADER_NAME = 'X-Correlation-ID';

    public const REQUEST_ID_HEADER = 'X-Request-ID';

    /** Letters, digits and . _ : - ; 8 to 128 characters, starting alphanumeric. */
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->acceptable($request->header(self::HEADER_NAME))
            ?? $this->acceptable($request->header(self::REQUEST_ID_HEADER))
            ?? (string) Str::uuid();

        $request->headers->set(self::HEADER_NAME, $correlationId);
        $request->headers->set(self::REQUEST_ID_HEADER, $correlationId);

        Context::add('correlation_id', $correlationId);

        $response = $next($request);

        $response->headers->set(self::HEADER_NAME, $correlationId);

        return $response;
    }

    private function acceptable(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }
}
