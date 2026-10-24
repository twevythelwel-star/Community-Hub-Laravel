<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CommunityEvent;
use App\Models\Renter;
use App\Models\Visitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Backs src/app/dashboard/calendar/page.tsx and event-form.tsx. */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $userStay = null;
        if ($user->role === UserRole::TemporaryHomeowner) {
            $stay = $user->activeStay() ?? Renter::where('user_id', $user->id)->first();
            if ($stay) {
                $userStay = [
                    'stayType' => $stay->stay_type ?? 'Long-term (Renter)',
                    'leaseStart' => $stay->lease_start->toDateString(),
                    'leaseEnd' => $stay->lease_end->toDateString(),
                    'expired' => $stay->leaseHasExpired(),
                ];
            }
        }

        $myVisitors = Visitor::where('homeowner_id', $user->id)
            ->whereDate('expected_at', '>=', now()->subDays(7))
            ->orderBy('expected_at')
            ->get()
            ->map(fn (Visitor $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'type' => $v->type,
                'status' => $v->status->value,
                'expectedAt' => $v->expected_at->toIso8601String(),
                'dateRange' => $v->date_range,
            ]);

        $entrySlots = [
            [
                'id' => 'slot-morning',
                'name' => 'Morning Clearance',
                'timeRange' => '06:00 AM – 12:00 PM',
                'status' => 'Available',
                'description' => 'Optimal for standard deliveries, service contractors, and day visitors.',
            ],
            [
                'id' => 'slot-afternoon',
                'name' => 'Afternoon Clearance',
                'timeRange' => '12:00 PM – 06:00 PM',
                'status' => 'Available',
                'description' => 'Peak visitor hours, guest arrivals, and package drop-offs.',
            ],
            [
                'id' => 'slot-evening',
                'name' => 'Evening Clearance',
                'timeRange' => '06:00 PM – 11:00 PM',
                'status' => 'Available',
                'description' => 'Dinner guests, social visits, and evening service arrivals.',
            ],
            [
                'id' => 'slot-overnight',
                'name' => 'Overnight Pass',
                'timeRange' => '11:00 PM – 06:00 AM',
                'status' => 'Available on Request',
                'description' => 'Special overnight guest passes with automatic security check.',
            ],
        ];

        return Inertia::render('Dashboard/Calendar', [
            'events' => CommunityEvent::orderBy('start_date')
                ->get()
                ->map(fn (CommunityEvent $e) => [
                    'id' => $e->id,
                    'title' => $e->title,
                    'description' => $e->description,
                    'startDate' => $e->start_date->toIso8601String(),
                    'endDate' => $e->end_date?->toIso8601String(),
                    'imageUrl' => $e->image_url,
                ]),
            'entrySlots' => $entrySlots,
            'myVisitors' => $myVisitors,
            'userStay' => $userStay,
            'canManage' => $user->can('broadcastNotices'),
            'canRegisterVisitors' => $user->can('registerVisitors'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('broadcastNotices');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ]);

        CommunityEvent::create([...$validated, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Event created.');
    }

    public function update(Request $request, CommunityEvent $communityEvent): RedirectResponse
    {
        $this->authorize('broadcastNotices');

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $communityEvent->update($validated);

        return back()->with('success', 'Event updated.');
    }

    public function destroy(CommunityEvent $communityEvent): RedirectResponse
    {
        $this->authorize('broadcastNotices');

        $communityEvent->delete();

        return back()->with('success', 'Event deleted.');
    }
}
