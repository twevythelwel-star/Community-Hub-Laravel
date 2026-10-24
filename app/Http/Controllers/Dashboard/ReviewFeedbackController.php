<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Backs src/app/dashboard/review-feedback/page.tsx (admin triage). */
class ReviewFeedbackController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard/ReviewFeedback', [
            'submissions' => Feedback::query()
                ->when($request->string('status')->isNotEmpty(),
                    fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->string('type')->isNotEmpty(),
                    fn ($q) => $q->where('type', $request->string('type')))
                ->latest('submitted_at')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Feedback $f) => [
                    'id' => $f->id,
                    'submittedBy' => $f->submitted_by,
                    'userRole' => $f->user_role,
                    'type' => $f->type,
                    'subject' => $f->subject,
                    'body' => $f->body,
                    'status' => $f->status,
                    'adminResponse' => $f->admin_response,
                    'timestamp' => $f->submitted_at->toIso8601String(),
                ]),
            'filters' => $request->only('status', 'type'),
            'counts' => [
                'new' => Feedback::where('status', 'New')->count(),
                'inProgress' => Feedback::where('status', 'In Progress')->count(),
                'resolved' => Feedback::where('status', 'Resolved')->count(),
            ],
        ]);
    }

    public function update(Request $request, Feedback $feedback): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:New,In Progress,Resolved'],
            'admin_response' => ['nullable', 'string', 'max:5000'],
        ]);

        $feedback->update($validated);

        $request->user()->recordActivity("Reviewed feedback #{$feedback->id}");

        return back()->with('success', 'Feedback updated.');
    }
}
