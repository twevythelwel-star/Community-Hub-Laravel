<?php

namespace App\Http\Controllers\Dashboard;

use App\Events\SecurityAlertBroadcastEvent;
use App\Http\Controllers\Controller;
use App\Models\Warning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backs src/app/dashboard/warnings/page.tsx — "Community Safety Alerts".
 *
 * What the original did not do:
 *
 *   1. "Send Alert" called console.log and toasted "Alert Sent".
 *   2. Confirm/deny were client-side counters. A vote never left the browser,
 *      reloading the page restored the mock tallies, and nothing stopped one
 *      person voting repeatedly. They are now one row per user per warning,
 *      enforced by a unique index on warning_responses.
 *
 * On who may raise an alert: an earlier revision of this controller gated
 * `store` on `manageSecurity`, which was wrong. The page is designed around
 * resident reports — the seeded authors are residents, the copy reads "Send and
 * validate urgent alerts within the community", and the dialog says the alert
 * goes "to all residents and the admin", so the sender is not the admin. The
 * confirm/deny mechanic only makes sense as community corroboration of an
 * unverified report; residents do not need to vote on an official security
 * bulletin. That gate was mine, not the original's, and it made the Send Alert
 * button 403 for exactly the people the feature was built for.
 *
 * Raising an alert notifies everyone, so it is rate limited on the route
 * (`throttle:3,60`) and `manageSecurity` can delete a false alarm.
 */
class WarningController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Warnings', [
            'warnings' => Warning::with('responses')
                ->latest('issued_at')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Warning $w) => [
                    'id' => $w->id,
                    'title' => $w->title,
                    'description' => $w->description,
                    'author' => $w->author_name,
                    'timestamp' => $w->issued_at->toIso8601String(),
                    'confirms' => $w->responses->where('response', 'confirmed')->count(),
                    'denies' => $w->responses->where('response', 'denied')->count(),
                    'userStatus' => $w->responseFor($user),
                    // Corroborating your own report is not corroboration.
                    'isMine' => $w->author_id !== null && $w->author_id === $user->id,
                ]),

            'can' => [
                'remove' => $user->can('manageSecurity'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $user = $request->user();

        $warning = Warning::create([
            ...$validated,
            'author_id' => $user->id,
            'author_name' => $user->display_name,
            'issued_at' => now(),
        ]);

        event(new SecurityAlertBroadcastEvent($warning));

        // A community-wide alert is worth an audit trail of who raised it.
        Log::channel('security')->notice('Safety alert raised', [
            'warning_id' => $warning->id,
            'title' => $warning->title,
            'raised_by' => $user->uid,
            'role' => $user->role->value,
        ]);

        $user->recordActivity("Raised safety alert: {$warning->title}");

        return back()->with('success', 'Your alert has been sent to the community.');
    }

    public function respond(Request $request, Warning $warning): RedirectResponse
    {
        $validated = $request->validate([
            'response' => ['required', 'in:confirmed,denied'],
        ]);

        $user = $request->user();

        if ($warning->author_id !== null && $warning->author_id === $user->id) {
            return back()->with('error', 'You cannot confirm or deny your own alert.');
        }

        /*
         | updateOrCreate keeps one vote per user and lets that vote change —
         | the unique index on (warning_id, user_id) is what makes stacking
         | impossible even if two requests race.
         */
        $warning->responses()->updateOrCreate(
            ['user_id' => $user->id],
            ['response' => $validated['response']],
        );

        return back();
    }

    /** Removes a false alarm. Alerts reach the whole community, so someone must be able to. */
    public function destroy(Request $request, Warning $warning): RedirectResponse
    {
        $this->authorize('manageSecurity');

        Log::channel('security')->notice('Safety alert removed', [
            'warning_id' => $warning->id,
            'title' => $warning->title,
            'raised_by' => $warning->author_name,
            'removed_by' => $request->user()->uid,
        ]);

        $warning->delete();

        return back()->with('success', 'Alert removed.');
    }
}
