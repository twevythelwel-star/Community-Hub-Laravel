<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Guideline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backs src/app/dashboard/guidelines/page.tsx.
 *
 * The page always offered administrators Add, Edit and Delete, but this
 * controller only had `index`, so every change lived in React state and was
 * gone on reload. The write endpoints use `broadcastNotices`, the gate that
 * already governs the other estate-wide publications (notices, updates,
 * calendar events).
 */
class GuidelinesController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard/Guidelines', [
            // Grouped by category so the accordion renders without client-side grouping.
            'guidelines' => Guideline::ordered()
                ->get()
                ->groupBy('category')
                ->map(fn ($group) => $group->map(fn (Guideline $g) => [
                    'id' => $g->id,
                    'title' => $g->title,
                    'description' => $g->description,
                ])->values()),
            'canManage' => $request->user()->can('broadcastNotices'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        // Appended to the end of its category, which is where the page shows it.
        $validated['sort_order'] = (int) Guideline::where('category', $validated['category'])->max('sort_order') + 1;

        Guideline::create($validated);

        return back()->with('success', 'Guideline added.');
    }

    public function update(Request $request, Guideline $guideline): RedirectResponse
    {
        $validated = $this->validated($request);

        if ($validated['category'] !== $guideline->category) {
            $validated['sort_order'] = (int) Guideline::where('category', $validated['category'])->max('sort_order') + 1;
        }

        $guideline->update($validated);

        return back()->with('success', 'Guideline updated.');
    }

    public function destroy(Guideline $guideline): RedirectResponse
    {
        $guideline->delete();

        return back()->with('success', 'Guideline deleted.');
    }

    /**
     * Mirrors the minimums in guideline-form.tsx so the server refuses what the
     * form refuses.
     *
     * @return array{category: string, title: string, description: string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'category' => ['required', 'string', 'min:3', 'max:80'],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
    }
}
