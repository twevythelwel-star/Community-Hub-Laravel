<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Backs src/app/dashboard/feedback/page.tsx. */
class FeedbackController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Feedback', [
            // A resident sees only their own submissions and any reply.
            'submissions' => $user->feedback()
                ->latest('submitted_at')
                ->get()
                ->map(fn (Feedback $f) => [
                    'id' => $f->id,
                    'type' => $f->type,
                    'subject' => $f->subject,
                    'body' => $f->body,
                    'status' => $f->status,
                    'adminResponse' => $f->admin_response,
                    'timestamp' => $f->submitted_at->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:Issue,Suggestion'],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $user = $request->user();

        Feedback::create([
            ...$validated,
            'user_id' => $user->id,
            'submitted_by' => $user->display_name,
            'user_role' => $user->role->value,
            'status' => 'New',
            'submitted_at' => now(),
        ]);

        return back()->with('success', 'Thank you — your feedback has been submitted.');
    }
}
