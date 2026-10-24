<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\ValidationStatus;
use App\Http\Controllers\Controller;
use App\Models\AccessLogEntry;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backs src/app/dashboard/access-log/page.tsx.
 *
 * The page listed five hardcoded entries with columns for user, role, method,
 * gate and timestamp — and no column for the outcome. Every mock row was a
 * successful entry, so a log whose purpose is security review showed only the
 * people who got in and never the ones who were refused. `result` and
 * `deny_reason` have always been recorded; nothing displayed them.
 *
 * The filters and the CSV export were written here and never reachable: the
 * page had no controls for either.
 */
class AccessLogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $this->filtered($request);

        return Inertia::render('Dashboard/AccessLog', [
            'entries' => $query->clone()
                ->latest('occurred_at')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (AccessLogEntry $e) => [
                    'id' => $e->id,
                    'userName' => $e->user_name,
                    'userRole' => $e->user_role,
                    'method' => $e->method,
                    'gate' => $e->gate,
                    'passId' => $e->pass_id,
                    'result' => $e->result,
                    'denyReason' => $e->deny_reason,
                    'timestamp' => $e->occurred_at->toIso8601String(),
                ]),

            /*
             | The headline of an access log is how many attempts were refused,
             | which is the one number the original page could not show.
             | Counted over the active filter, not the whole table.
             */
            'stats' => [
                'total' => $query->clone()->count(),
                'denied' => $query->clone()->where('result', ValidationStatus::Deny->value)->count(),
            ],

            'filters' => $request->only('gate', 'result', 'from', 'to'),

            /*
             | Gate names are free text on this table — scans store the label
             | from config('gatepass.gates'), the visitor API accepts whatever it
             | is given, and the seeder writes a "Pedestrian Gate" that is in
             | neither. Reading the distinct values back is the only option that
             | matches what is actually in the log.
             */
            'gates' => AccessLogEntry::query()
                ->distinct()
                ->orderBy('gate')
                ->pluck('gate')
                ->all(),

            // ALLOW/DENY are scan outcomes; CHECK_IN/CHECK_OUT are confirmed movements.
            'results' => [...array_column(ValidationStatus::cases(), 'value'), 'CHECK_IN', 'CHECK_OUT'],

            'canExport' => $request->user()->can('manageSecurity'),
        ]);
    }

    /** CSV export, streamed so a long log does not exhaust memory. */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('manageSecurity');

        // Same filters as the page. Exporting the whole table while the user is
        // looking at a filtered view hands them a file that is not what they saw.
        $query = $this->filtered($request);

        $filename = 'access-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['Timestamp', 'Name', 'Role', 'Method', 'Gate', 'Pass ID', 'Result', 'Deny Reason']);

            /*
             | chunkById, not chunk. chunk() pages by offset, and this table is
             | append-heavy: rows arriving mid-export shift every later offset,
             | which silently duplicates and skips records. Keying on the primary
             | key is stable under concurrent writes. The cost is that the file
             | comes out oldest-first rather than newest-first.
             */
            $query->chunkById(500, function ($entries) use ($handle) {
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        $entry->occurred_at->toDateTimeString(),
                        $entry->user_name,
                        $entry->user_role,
                        $entry->method,
                        $entry->gate,
                        $entry->pass_id,
                        $entry->result,
                        $entry->deny_reason,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The one place the filters are defined, so the table and the export cannot
     * drift apart.
     */
    private function filtered(Request $request): Builder
    {
        $user = $request->user();

        return AccessLogEntry::query()
            /*
             | Defence in depth. The route carries `can:manageSecurity`, so this
             | branch is unreachable today; it means that relaxing the gate shows
             | a resident their own gate activity rather than the whole estate's.
             */
            ->when(! $user->can('manageSecurity'), fn ($q) => $q->where('user_id', $user->id))
            ->when($request->string('gate')->isNotEmpty(), fn ($q) => $q->where('gate', $request->string('gate')))
            ->when($request->string('result')->isNotEmpty(), fn ($q) => $q->where('result', $request->string('result')))
            ->when($request->date('from'), fn ($q, $from) => $q->where('occurred_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('occurred_at', '<=', $to->endOfDay()));
    }
}
