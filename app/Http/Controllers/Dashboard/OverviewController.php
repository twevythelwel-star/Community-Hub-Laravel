<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Http\Controllers\Controller;
use App\Models\AccessLogEntry;
use App\Models\Business;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\Landmark;
use App\Models\Notification;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GeofenceService;
use App\Services\Occupancy\OccupancyMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backs src/app/dashboard/page.tsx — the map, announcements, visitor passes,
 * stat tiles, fundraisers, recent visitors, recent payments and perks.
 *
 * The original page read from module-level mock arrays and, in several places,
 * from JSX literals — the announcements, the visitor passes and the "452 active
 * residents" tile were hardcoded markup, identical for every user on every day.
 * Everything here is a real aggregate scoped to the viewer's role.
 */
class OverviewController extends Controller
{
    public function __construct(private readonly GeofenceService $geofence) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $isStaff = $user->can('manageSecurity');
        $community = Community::query()
            ->where('code', config('gatepass.default_community_id'))
            ->first();

        // Residents see only their own visitors; security and admins see the estate.
        $visitorScope = fn () => Visitor::query()
            ->when(! $isStaff, fn ($q) => $q->where('homeowner_id', $user->id));

        return Inertia::render('Dashboard/Overview', [

            'stats' => $this->stats($user, $isStaff, $visitorScope),

            'permissions' => [
                // Replaces five inline `['System Admin', 'Admin'].includes(role)`
                // checks in the JSX, which only hid tiles rather than the data.
                'viewActiveResidents' => $isStaff,
                'viewUpcomingVisitors' => $user->role->isResident()
                    || $user->role === UserRole::Security
                    || $user->role->isAdministrative(),
                'viewRecentVisitors' => $isStaff || $user->role->isResident(),
                'viewBilling' => $user->can('manageBilling'),
                'viewFundraisers' => $user->role->isResident() || $user->role->isAdministrative(),
                'manageBoundary' => $user->can('manageBoundary'),
            ],

            'announcements' => Notification::query()
                ->forRole($user->role->value)
                ->latest('published_at')
                ->limit(5)
                ->get()
                ->map(fn (Notification $n) => [
                    'id' => $n->id,
                    'title' => $n->title,
                    'content' => $n->content,
                    'author' => $n->author_name,
                    'timestamp' => $n->published_at->toIso8601String(),
                ]),

            'upcomingEvents' => CommunityEvent::upcoming()
                ->limit(4)
                ->get()
                ->map(fn (CommunityEvent $e) => [
                    'id' => $e->id,
                    'title' => $e->title,
                    'summary' => str($e->description)->limit(90)->toString(),
                    'startDate' => $e->start_date->toIso8601String(),
                    'imageUrl' => $e->image_url,
                ]),

            // Today's clearances, for the "Visitor Gate Passes" strip that was
            // previously three hardcoded cards.
            'visitorPasses' => $visitorScope()
                ->whereDate('expected_at', today())
                ->whereNull('expired_at')
                ->orderBy('expected_at')
                ->limit(6)
                ->get()
                ->map(fn (Visitor $v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'status' => $v->status->value,
                    'expectedAt' => $v->expected_at->toIso8601String(),
                    'host' => $v->homeowner_name,
                    'isBlocked' => $v->is_blocked,
                ]),

            'recentVisitors' => $visitorScope()
                ->latest('expected_at')
                ->limit(8)
                ->get()
                ->map(fn (Visitor $v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'type' => $v->type,
                    'status' => $v->status->value,
                    'expectedAt' => $v->expected_at->toIso8601String(),
                    'homeowner' => $v->homeowner_name,
                    'isBlocked' => $v->is_blocked,
                ]),

            'recentPayments' => $this->recentPayments($user, $isStaff),

            /*
             | Resident spotlight. Previously four hardcoded cards (Marcus Vance,
             | Olivia Davis…) with phone and message buttons that did nothing.
             |
             | The phone number is only included for viewers who may manage users
             | — the same restriction the directory now applies. Without it the
             | tile would publish every resident's number to every other resident.
             */
            'residents' => User::active()
                ->whereIn('role', [
                    UserRole::Homeowner->value,
                    UserRole::TemporaryHomeowner->value,
                ])
                ->orderBy('display_name')
                ->limit(6)
                ->get()
                ->map(fn (User $r) => [
                    'id' => $r->id,
                    'name' => $r->display_name,
                    'initials' => $this->initials($r->display_name),
                    'lot' => $r->lot,
                    'role' => $r->role === UserRole::Homeowner ? 'Res' : 'Tenant',
                    'avatarUrl' => $r->avatar_url,
                    'phone' => $user->can('manageUsers') ? $r->phone : null,
                ]),

            'fundraisers' => Fundraiser::with('donations')
                ->active()
                ->get()
                ->map(fn (Fundraiser $f) => [
                    'id' => $f->id,
                    'title' => $f->title,
                    'description' => $f->description,
                    'goal' => $f->goal(),
                    'raised' => $f->raised(),
                    'progress' => $f->progressPercent(),
                    'donorCount' => $f->donorCount(),
                    'currency' => $f->goal_currency,
                    'startDate' => $f->start_date->toDateString(),
                    'endDate' => $f->end_date->toDateString(),
                    'status' => $f->status,
                ]),

            'perks' => Business::with('vouchers')
                ->where('active', true)
                ->limit(6)
                ->get()
                ->map(fn (Business $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'logoUrl' => $b->logo_url,
                    'aiHint' => $b->ai_hint,
                    'vouchers' => $b->vouchers->map(fn ($v) => [
                        'id' => $v->id,
                        'title' => $v->title,
                        'description' => $v->description,
                    ])->values(),
                ]),

            /*
             | Landmarks come from the database rather than the COMMUNITY_LANDMARKS
             | constant the page used to import, so an administrator adding one is
             | visible to everybody. The boundary itself is shared globally by
             | HandleInertiaRequests and read through useMap().
             */
            'landmarks' => $community
                ? $community->landmarks->map(fn (Landmark $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'category' => $l->category,
                    'description' => $l->description,
                    'coordinates' => [$l->lat, $l->lng],
                    'elevation' => $this->geofence->estimateElevation($l->lat, $l->lng),
                    'color' => $this->landmarkColor($l->category),
                ])
                : [],
        ]);
    }

    private function stats(User $user, bool $isStaff, callable $visitorScope): array
    {
        $collectedThisMonth = Invoice::query()
            ->where('status', 'Paid')
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year);

        $outstanding = Invoice::outstanding();

        $occupancyData = $isStaff ? $this->occupancyStats() : null;

        return [
            'month' => now()->format('F'),

            'activeResidents' => $isStaff ? User::active()->count() : null,
            // The tile showed "+12 from last month" as a literal.
            'residentsJoinedThisMonth' => $isStaff
                ? User::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count()
                : null,

            'totalCollected' => (float) ((int) $collectedThisMonth->sum('amount_minor') / 100),
            'collectedHouseholds' => (clone $collectedThisMonth)->distinct('user_id')->count('user_id'),

            'outstandingDues' => (float) ((int) $outstanding->sum('amount_minor') / 100),
            'outstandingHouseholds' => (clone $outstanding)->distinct('user_id')->count('user_id'),

            'upcomingVisitors' => $visitorScope()
                ->where('status', VisitorStatus::Expected->value)
                ->whereNull('expired_at')
                ->where('expected_at', '>=', now())
                ->count(),
            'visitorsToday' => $visitorScope()
                ->whereDate('expected_at', today())
                ->whereNull('expired_at')
                ->count(),

            'visitorsOnSite' => $visitorScope()->onSite()->count(),

            'entriesToday' => $isStaff
                ? AccessLogEntry::whereDate('occurred_at', today())->count()
                : null,
            'deniedToday' => $isStaff
                ? AccessLogEntry::denied()->whereDate('occurred_at', today())->count()
                : null,

            'myOutstandingBalance' => (float) ((int) $user->invoices()->outstanding()->sum('amount_minor') / 100),
            'myRentersCount' => $user->role === UserRole::Homeowner
                ? $user->renters()->where('status', 'Active')->count()
                : null,

            'occupancy' => $occupancyData,
        ];
    }

    /**
     * "Currently inside" and "expected today", from the same occupancy
     * service as the Who's On Property page, so the two never disagree.
     *
     * @return array{currentlyInside: array<string, int>, expectedToday: array<string, int>}
     */
    private function occupancyStats(): array
    {
        $summary = app(OccupancyMetricsService::class)->getDualSummary();

        $card = fn (array $counts): array => [
            'total' => $counts['total'],
            'residents' => $counts['residents'],
            'renters' => $counts['long_term_guests'] + $counts['short_term_guests'],
            'visitors' => $counts['visitors'],
            'staff' => $counts['staff'],
            'contractors' => $counts['contractors'],
            'legacyContacts' => $counts['legacy_contacts'],
        ];

        return [
            'currentlyInside' => $card($summary['inside']),
            'expectedToday' => $card($summary['expected']),
        ];
    }

    /** Estate-wide payments for billing admins, otherwise the viewer's own. */
    private function recentPayments(User $user, bool $isStaff): Collection
    {
        $query = $user->can('manageBilling')
            ? Invoice::with('user:id,display_name,lot')
            : $user->invoices()->with('user:id,display_name,lot');

        return $query
            ->latest('period_start')
            ->limit(5)
            ->get()
            ->map(fn (Invoice $i) => [
                'id' => $i->id,
                'reference' => $i->reference,
                'homeowner' => trim(($i->user?->display_name ?? 'Resident').($i->user?->lot ? " ({$i->user->lot})" : '')),
                'amount' => $i->amount(),
                'currency' => $i->currency,
                'status' => $i->isOverdue() ? 'Overdue' : $i->status,
                'date' => $i->due_on->toDateString(),
                'paidAt' => $i->paid_at?->toIso8601String(),
            ]);
    }

    /** Up to two initials from a display name, for the avatar fallback. */
    private function initials(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /** Pin colour per landmark category, matching LANDMARK_PIN_CONFIGS. */
    private function landmarkColor(?string $category): string
    {
        return match ($category) {
            'Security Gate' => '#10B981',
            'Community Center' => '#EF4444',
            'Park' => '#3B82F6',
            default => '#64748B',
        };
    }
}
