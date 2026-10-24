<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `password.confirm`: re-enter your password before account changes, refunds,
 * billing settings and bulk exports, once per config('auth.password_timeout').
 *
 * Laravel's RequirePassword redirects to the confirmation page and back with
 * redirect()->intended(), which suits a page or a download (a GET). An Inertia
 * form submission cannot come back that way — the intended URL would be
 * revisited as a GET — and a plain redirect would read as success to the
 * caller's onSuccess. So it is refused as a validation error instead: the
 * dialog that sent it stays open, and the dashboard's password dialog opens on
 * `errors.password_confirmation`. JSON callers keep Laravel's 423.
 */
class RequirePasswordConfirmation extends RequirePassword
{
    public const MESSAGE = 'Confirm your password to continue.';

    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null): Response
    {
        // The parent's own fallback is a hard-coded three hours, not the config.
        $passwordTimeoutSeconds ??= (int) config('auth.password_timeout', 10800);

        if ($this->isInertiaSubmission($request) && $this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            return back()->withErrors(['password_confirmation' => self::MESSAGE]);
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }

    private function isInertiaSubmission(Request $request): bool
    {
        return $request->header('X-Inertia') && ! $request->isMethod('GET');
    }
}
