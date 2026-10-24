<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CommunityUpdate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Backs src/app/dashboard/updates/page.tsx and community-update-form.tsx. */
class UpdateController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard/Updates', [
            'updates' => CommunityUpdate::latest('date')
                ->paginate(20)
                ->through(fn (CommunityUpdate $u) => [
                    'id' => $u->id,
                    'title' => $u->title,
                    'date' => $u->date->toDateString(),
                    'summary' => $u->summary,
                ]),
            'canManage' => $request->user()->can('broadcastNotices'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('broadcastNotices');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'date' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:5000'],
        ]);

        CommunityUpdate::create([...$validated, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Update posted.');
    }
}
