<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivated accounts are logged out on their next request rather than being
 * allowed to keep an existing session alive. The original app only hid the UI.
 *
 * API tokens get the same treatment: the token is deleted and the request
 * refused. Tokens never expire, and an account stops being active without
 * anyone touching it when a temporary stay ends, so this check on every
 * request is what keeps a former resident's or guard's device out.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            $expiredMessage = $user->temporaryStayExpirationMessage();

            if (! $request->hasSession()) {
                $token = $user->currentAccessToken();
                if ($token instanceof PersonalAccessToken) {
                    $token->delete();
                }

                return response()->json([
                    'message' => $expiredMessage ?: 'This account has been deactivated. Contact community administration.',
                ], 401);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => $expiredMessage ?: 'This account has been deactivated. Contact community administration.']);
        }

        return $next($request);
    }
}
