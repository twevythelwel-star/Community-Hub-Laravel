<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Backs src/app/dashboard/deactivation/page.tsx, which previously wrote a flag
 * to localStorage — clearable by the user and invisible to administrators.
 * Deactivation now revokes the gate pass, ends the session, and is logged.
 */
class DeactivationController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Dashboard/Deactivation', [
            'user' => [
                'displayName' => $request->user()->display_name,
                'email' => $request->user()->email,
            ],
        ]);
    }

    public function store(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'current_password'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'confirm' => ['accepted'],
        ]);

        $user = $request->user();

        $user->update([
            'status' => 'Inactive',
            'deactivated_at' => now(),
        ]);

        /*
         | A deactivated account must not keep a working pass.
         |
         | `->get()` matters: the higher-order `->each->` proxy exists on
         | Collection, not on Builder, so calling it on the query threw
         | "Undefined property: HasMany::$each" and deactivation 500'd.
         */
        $user->gatePasses()->active()->get()->each->revoke($user, 'Account deactivated by holder');
        $user->tokens()->delete();

        $user->recordActivity('Deactivated own account');

        Log::channel('security')->notice('Account deactivated', [
            'uid' => $user->uid,
            'reason' => $validated['reason'] ?? null,
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('success', 'Your account has been deactivated.');

        /*
         | The landing page is Blade, not Inertia. A plain redirect answering an
         | Inertia request would be rendered inside the SPA's error modal, so
         | ask the client for a full page visit instead — the same thing
         | AuthenticatedSessionController::destroy() does on sign-out.
         */
        return Inertia::location(route('landing'));
    }
}
