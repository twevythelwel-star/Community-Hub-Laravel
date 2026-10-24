<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Renters\StoreRenterRequest;
use App\Http\Requests\Renters\UpdateRenterRequest;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Backs src/app/dashboard/renters/page.tsx and register-renter-form.tsx. */
class RenterController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Renter::class);

        $user = $request->user();
        $isHomeowner = $user->role === UserRole::Homeowner;

        $query = Renter::with(['user', 'homeowner'])
            ->when($isHomeowner, fn ($q) => $q->where('homeowner_id', $user->id))
            ->orderBy('name');

        return Inertia::render('Dashboard/Renters', [
            'renters' => $query
                ->paginate(25)
                ->through(fn (Renter $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'stayType' => $r->stay_type ?? 'Long-term (Renter)',
                    'contact' => $r->contact,
                    'notes' => $r->notes,
                    'status' => $r->leaseHasExpired() ? 'Expired' : $r->status,
                    'leaseStart' => $r->lease_start->toDateString(),
                    'leaseEnd' => $r->lease_end->toDateString(),
                    'lot' => $r->lot,
                    'street' => $r->street,
                    'expired' => $r->leaseHasExpired(),
                    'homeownerId' => $r->homeowner_id,
                    'homeownerName' => $r->homeowner?->display_name ?? $r->homeowner?->name,
                ]),
            'isHomeowner' => $isHomeowner,
            'canRegister' => $user->can('manageUsers'),
            // Whose property a new stay is for; only the estate office registers one.
            'homeowners' => $user->can('manageUsers')
                ? User::where('role', UserRole::Homeowner)
                    ->orderBy('name')
                    ->get(['id', 'name', 'display_name', 'lot', 'street'])
                    ->map(fn (User $h) => ['id' => $h->id, 'name' => $h->display_name ?: $h->name, 'property' => $h->propertyLabel()])
                    ->values()
                : [],
        ]);
    }

    /**
     * Registering a resident is the estate office's: a temporary homeowner's
     * stay is what lets their account sign in and register visitors. The
     * administrator says whose property it is; the address is that homeowner's.
     */
    public function store(StoreRenterRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $homeowner = User::findOrFail($validated['homeowner_id']);
        $lot = $validated['lot'] ?? $homeowner->lot;

        Renter::create([
            ...$validated,
            'property_id' => $this->rentedProperty($homeowner, $validated['lot'] ?? null)?->id,
            'lot' => $lot,
            'street' => $validated['street'] ?? $homeowner->street,
            'status' => 'Active',
        ]);

        $request->user()->recordActivity("Registered temporary homeowner {$validated['name']} ({$validated['stay_type']}) for {$homeowner->propertyLabel()}");

        return back()->with('success', 'Temporary homeowner registered.');
    }

    /**
     * Which of the homeowner's properties the stay is for: the one at the
     * lot given, or their only one. An owner of several with no lot given
     * gets none recorded rather than a guess.
     */
    private function rentedProperty(User $homeowner, ?string $lot): ?Property
    {
        $owned = $homeowner->properties()->orderBy('id')->get();
        $bare = fn (?string $value) => strtolower(preg_replace('/^(unit|lot|#)\s*/i', '', trim((string) $value)));

        if (filled($lot)) {
            return $owned->first(fn (Property $p) => $bare($p->lot_number) === $bare($lot));
        }

        return $owned->count() === 1 ? $owned->first() : null;
    }

    public function update(UpdateRenterRequest $request, Renter $renter): RedirectResponse
    {
        $validated = $request->validated();
        $renter->update($validated);

        $request->user()->recordActivity("Updated temporary homeowner {$renter->name}");

        return back()->with('success', 'Temporary homeowner updated.');
    }

    public function destroy(Request $request, Renter $renter): RedirectResponse
    {
        $this->authorize('delete', $renter);

        $renterName = $renter->name;
        $renter->delete();

        $request->user()->recordActivity("Removed temporary homeowner {$renterName}");

        return back()->with('success', 'Temporary homeowner removed.');
    }
}
