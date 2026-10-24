<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    public function __construct(
        protected VehicleManagementService $vehicleService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $myVehicles = $this->vehicleService->getVehiclesForUser($user);
        $isSecurityOrAdmin = $user->role->isAdministrative() || $user->role->isOperational();
        $allEstateVehicles = $isSecurityOrAdmin ? $this->vehicleService->getAllVehicles() : [];

        $household = Household::where('primary_homeowner_id', $user->id)->first();
        $householdMembers = $household
            ? HouseholdMember::where('household_id', $household->id)->get(['id', 'name', 'role_in_household'])
            : [];

        $stats = [
            'total' => $myVehicles->count(),
            'anprEnabled' => $myVehicles->where('anpr_enabled', true)->count(),
            'electricVehicles' => $myVehicles->where('is_ev', true)->count(),
            'temporary' => $myVehicles->where('is_temporary', true)->count(),
            'estateTotal' => is_countable($allEstateVehicles) ? count($allEstateVehicles) : 0,
        ];

        return Inertia::render('Dashboard/Vehicles', [
            'vehicles' => $myVehicles,
            'estateVehicles' => $allEstateVehicles,
            'householdMembers' => $householdMembers,
            'stats' => $stats,
            'isSecurityOrAdmin' => $isSecurityOrAdmin,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // A registered plate opens the gate by ANPR: residents and administrators register them.
        abort_unless($request->user()->role->isResident() || $request->user()->role->isAdministrative(), 403, 'Only residents and administrators can register vehicles.');

        $validated = $request->validate([
            'license_plate' => ['required', 'string', 'max:32'],
            'jurisdiction' => ['nullable', 'string', 'max:64'],
            'make' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:64'],
            'color' => ['required', 'string', 'max:48'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2030'],
            'parking_location' => ['nullable', 'string', 'max:128'],
            'household_member_id' => ['nullable', Rule::exists('occupants', 'id')->where('type', HouseholdMember::TYPE)],
            'is_ev' => ['nullable', 'boolean'],
            'is_temporary' => ['nullable', 'boolean'],
            'valid_until' => ['nullable', 'date'],
            'anpr_enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->ensureOwnHouseholdMember($request->user(), $validated['household_member_id'] ?? null);

        $this->vehicleService->registerVehicle($request->user(), $validated);

        return back()->with('success', 'Vehicle registered successfully. ANPR plate authorization active.');
    }

    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $validated = $request->validate([
            'license_plate' => ['sometimes', 'string', 'max:32'],
            'jurisdiction' => ['nullable', 'string', 'max:64'],
            'make' => ['sometimes', 'string', 'max:64'],
            'model' => ['sometimes', 'string', 'max:64'],
            'color' => ['sometimes', 'string', 'max:48'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2030'],
            'parking_location' => ['nullable', 'string', 'max:128'],
            'household_member_id' => ['nullable', Rule::exists('occupants', 'id')->where('type', HouseholdMember::TYPE)],
            'is_ev' => ['nullable', 'boolean'],
            'is_temporary' => ['nullable', 'boolean'],
            'valid_until' => ['nullable', 'date'],
            'anpr_enabled' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:active,inactive,suspended,revoked'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $this->ensureCanManage($user, $vehicle);
        $this->ensureOwnHouseholdMember($user, $validated['household_member_id'] ?? null);

        // Suspension and revocation are security's call: an owner could set a
        // suspended plate back to active, or switch its ANPR back on.
        if (! $user->can('manageSecurity')) {
            unset($validated['status']);
            if (in_array($vehicle->status, ['suspended', 'revoked'], true)) {
                unset($validated['anpr_enabled']);
            }
        }

        $vehicle->update($validated);

        return back()->with('success', 'Vehicle details updated successfully.');
    }

    public function destroy(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->ensureCanManage($request->user(), $vehicle);

        $vehicle->delete();

        return back()->with('success', 'Vehicle removed from community registry.');
    }

    public function anprLookup(Request $request): JsonResponse
    {
        $plate = $request->query('plate', '');
        $result = $this->vehicleService->lookupPlate($plate);

        // The result names the owner, their property and where the car parks.
        // Gate staff look up any plate; anyone else only their own household's,
        // and someone else's is answered exactly like an unregistered one.
        $user = $request->user();
        if (($result['found'] ?? false) && ! $user->can('scanPasses')
            && ! Vehicle::whereKey($result['id'])->where('user_id', $user->id)->exists()) {
            $result = null;
        }

        if (! $result || ! ($result['found'] ?? false)) {
            return response()->json([
                'found' => false,
                'authorized' => false,
                'message' => "No registered vehicle matching plate '{$plate}'.",
                'vehicle' => null,
            ], 404);
        }

        return response()->json([
            'found' => true,
            'authorized' => $result['authorized'] ?? true,
            'owner' => $result['owner'] ?? ['name' => $result['ownerName'] ?? 'Resident'],
            'message' => 'Vehicle recognized by ANPR registry.',
            'vehicle' => $result,
        ]);
    }

    /**
     * Its owner, or security and administration. Any account could edit or
     * delete any vehicle by id, its ANPR flag and status included.
     */
    private function ensureCanManage(User $user, Vehicle $vehicle): void
    {
        abort_unless((int) $vehicle->user_id === $user->id || $user->can('manageSecurity'), 403, 'You can only change your own vehicles.');
    }

    /** A vehicle can only be tied to a member of the user's own household. */
    private function ensureOwnHouseholdMember(User $user, mixed $memberId): void
    {
        if ($memberId === null || $user->role->isAdministrative()) {
            return;
        }

        abort_unless(
            HouseholdMember::whereKey($memberId)->whereHas('household', fn ($q) => $q->where('primary_homeowner_id', $user->id))->exists(),
            403,
            'That person is not in your household.',
        );
    }
}
