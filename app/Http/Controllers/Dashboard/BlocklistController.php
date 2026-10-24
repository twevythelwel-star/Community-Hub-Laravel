<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BlocklistEntry;
use App\Models\BlocklistRemovalRequest;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backs src/app/dashboard/block-list/page.tsx, blocklist-form.tsx and
 * request-removal-form.tsx.
 *
 * Access model, which the original could not settle:
 *
 *   The sidebar offered "Block List" to Homeowners and Temporary Homeowners, and
 *   the page carried a Homeowner-only "Request Removal" action — but the route
 *   required `manageBlocklist`, which residents do not hold. So the menu item led
 *   to a 403 and the resident feature was unreachable.
 *
 *   Resolved by splitting read from write: any signed-in resident may see that
 *   someone is blocked and ask for a review; only `manageBlocklist` may add,
 *   edit or remove entries.
 *
 * A blocklist is personal data and the stated reasons are unflattering, so a
 * resident's copy carries the name, status and date only. The reason, the photo
 * and who added the entry are withheld from anyone who cannot manage the list.
 */
class BlocklistController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->can('manageBlocklist');

        return Inertia::render('Dashboard/BlockList', [
            'entries' => BlocklistEntry::with('removalRequests')
                ->latest('date_added')
                ->paginate(25)
                ->through(fn (BlocklistEntry $e) => $this->entryPayload($e, $user, $canManage)),

            /*
             | Known visitors, so the add form can pre-fill a name and reuse the
             | ID photo already on file. Only sent to staff — it is a directory of
             | everyone's guests, which residents have no reason to receive.
             */
            'visitors' => $canManage
                ? Visitor::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'id_image_url'])
                    ->map(fn (Visitor $v) => [
                        'id' => $v->id,
                        'name' => $v->name,
                        'idImageUrl' => $v->id_image_url,
                    ])
                : [],

            // Pending resident requests, for the administrators who action them.
            'removalRequests' => $canManage
                ? BlocklistRemovalRequest::with(['entry:id,name', 'requester:id,display_name,lot'])
                    ->pending()
                    ->latest()
                    ->get()
                    ->map(fn (BlocklistRemovalRequest $r) => [
                        'id' => $r->id,
                        'entryId' => $r->blocklist_entry_id,
                        'entryName' => $r->entry?->name,
                        'requestedBy' => $r->requester?->display_name,
                        'lot' => $r->requester?->lot,
                        'reason' => $r->reason,
                        'requestedAt' => $r->created_at->toIso8601String(),
                    ])
                : [],

            'can' => [
                'manage' => $canManage,
                'requestRemoval' => $user->role->isResident(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageBlocklist');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:1000'],
            'photo_url' => ['nullable', 'url', 'max:2048'],
            'expiry_date' => ['nullable', 'date', 'after:today'],
        ]);

        $user = $request->user();

        $entry = BlocklistEntry::create([
            ...$validated,
            'date_added' => now(),
            'added_by_id' => $user->id,
            'added_by' => $user->display_name,
        ]);

        Log::channel('security')->notice('Blocklist entry added', [
            'name' => $entry->name,
            'added_by' => $user->uid,
            'expires' => $entry->expiry_date?->toDateString() ?? 'never',
        ]);

        return back()->with('success', "{$entry->name} added to the blocklist.");
    }

    public function update(Request $request, BlocklistEntry $blocklistEntry): RedirectResponse
    {
        $this->authorize('manageBlocklist');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'reason' => ['sometimes', 'string', 'max:1000'],
            'photo_url' => ['nullable', 'url', 'max:2048'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $blocklistEntry->update($validated);

        $request->user()->recordActivity("Updated blocklist entry {$blocklistEntry->name}");

        return back()->with('success', 'Blocklist entry updated.');
    }

    public function destroy(Request $request, BlocklistEntry $blocklistEntry): RedirectResponse
    {
        $this->authorize('manageBlocklist');

        Log::channel('security')->notice('Blocklist entry removed', [
            'name' => $blocklistEntry->name,
            'removed_by' => $request->user()->uid,
        ]);

        $blocklistEntry->delete();

        return back()->with('success', 'Blocklist entry removed.');
    }

    // ── Removal requests ─────────────────────────────────────────────

    /**
     * A resident asks for an entry to be reviewed.
     *
     * Replaces a dialog that called console.log and told the resident the request
     * had been sent to administrators.
     */
    public function requestRemoval(Request $request, BlocklistEntry $blocklistEntry): RedirectResponse
    {
        $user = $request->user();

        if (! $user->role->isResident()) {
            abort(403, 'Only residents can request a blocklist review.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        // One open request per resident per entry; asking again reopens the
        // existing row rather than stacking duplicates.
        BlocklistRemovalRequest::updateOrCreate(
            [
                'blocklist_entry_id' => $blocklistEntry->id,
                'requested_by' => $user->id,
            ],
            [
                'reason' => $validated['reason'],
                'status' => 'Pending',
                'reviewer_note' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ],
        );

        $user->recordActivity("Requested blocklist review for {$blocklistEntry->name}");

        return back()->with('success', 'Your request has been sent to community administration for review.');
    }

    /** An administrator approves or declines a request. */
    public function reviewRemoval(Request $request, BlocklistRemovalRequest $removalRequest): RedirectResponse
    {
        $this->authorize('manageBlocklist');

        $validated = $request->validate([
            'status' => ['required', 'in:Approved,Declined'],
            'reviewer_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        $removalRequest->update([
            ...$validated,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        // Approving the request is what actually lifts the block.
        if ($validated['status'] === 'Approved') {
            $entry = $removalRequest->entry;

            Log::channel('security')->notice('Blocklist entry removed after resident request', [
                'name' => $entry?->name,
                'removed_by' => $user->uid,
                'requested_by' => $removalRequest->requester?->uid,
            ]);

            $entry?->delete();

            return back()->with('success', 'Request approved and the entry removed from the blocklist.');
        }

        return back()->with('success', 'Request declined.');
    }

    /**
     * Builds one row, redacting the sensitive fields for residents.
     *
     * @return array<string, mixed>
     */
    private function entryPayload(BlocklistEntry $e, User $user, bool $canManage): array
    {
        $base = [
            'id' => $e->id,
            'name' => $e->name,
            'dateAdded' => $e->date_added->toIso8601String(),
            'expiryDate' => $e->expiry_date?->toIso8601String(),
            'isPermanent' => $e->isPermanent(),
            'inForce' => $e->isCurrentlyBlocking(),
            // Lets a resident see that they have already asked, so the action
            // can be disabled rather than silently doing nothing.
            'myRequestStatus' => $e->removalRequests
                ->firstWhere('requested_by', $user->id)?->status,
        ];

        if (! $canManage) {
            return $base;
        }

        return [
            ...$base,
            'reason' => $e->reason,
            'photoUrl' => $e->photo_url,
            'addedBy' => $e->added_by,
            'pendingRequests' => $e->removalRequests->where('status', 'Pending')->count(),
        ];
    }
}
