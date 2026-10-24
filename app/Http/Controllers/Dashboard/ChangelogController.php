<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ChangelogEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Backs src/app/dashboard/changelog/page.tsx. */
class ChangelogController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard/Changelog', [
            'entries' => ChangelogEntry::newestFirst()
                ->get()
                ->map(fn (ChangelogEntry $e) => [
                    'id' => $e->id,
                    'version' => $e->version,
                    'releasedOn' => $e->released_on->toDateString(),
                    'title' => $e->title,
                    'body' => $e->body,
                ]),
            'canManage' => $request->user()->can('viewAppChangelog'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('viewAppChangelog');

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:20'],
            'released_on' => ['required', 'date'],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        ChangelogEntry::create($validated);

        return back()->with('success', 'Release notes published.');
    }
}
