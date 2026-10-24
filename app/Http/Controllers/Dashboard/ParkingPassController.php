<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ParkingPass;
use App\Models\Vehicle;
use App\Services\ParkingPassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ParkingPassController extends Controller
{
    public function __construct(
        protected ParkingPassService $parkingService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $passes = $this->parkingService->getPassesForUser($user);
        $vehicles = Vehicle::where('user_id', $user->id)->get(['id', 'make', 'model', 'color', 'license_plate', 'parking_location']);

        $categoryStats = [];
        foreach (ParkingPass::categories() as $catKey => $catData) {
            $categoryStats[$catKey] = [
                'meta' => $catData,
                'count' => $passes->where('category', $catKey)->count(),
            ];
        }

        return Inertia::render('Dashboard/Parking', [
            'passes' => $passes,
            'vehicles' => $vehicles,
            'categories' => ParkingPass::categories(),
            'categoryStats' => $categoryStats,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $isStaff = $user->can('manageSecurity');
        abort_unless($user->role->isResident() || $isStaff, 403, 'Only residents, security and administration can issue parking passes.');

        $validated = $request->validate([
            'category' => ['required', 'string', 'in:resident,visitor,contractor,temporary,accessible,loading_zone'],
            'license_plate' => ['required', 'string', 'max:32'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'property' => ['nullable', 'string', 'max:64'],
            'assigned_bay' => ['nullable', 'string', 'max:64'],
            'holder_name' => ['required', 'string', 'max:128'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'max_duration_minutes' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        // A resident issues passes for their own property and vehicles; bays and
        // the accessible and loading-zone categories are assigned by staff.
        if (! $isStaff) {
            abort_if(in_array($validated['category'], ['accessible', 'loading_zone'], true), 403, 'Accessible and loading-zone passes are issued by the estate office.');
            if (! empty($validated['vehicle_id'])) {
                abort_unless(Vehicle::whereKey($validated['vehicle_id'])->where('user_id', $user->id)->exists(), 403, 'You can only use your own vehicle.');
            }
            $validated['property'] = $user->propertyLabel();
            $validated['assigned_bay'] = null;
        }

        $this->parkingService->issueParkingPass($user, $validated);

        return back()->with('success', 'Parking QR credential issued successfully.');
    }

    public function verify(Request $request): JsonResponse
    {
        $token = $request->input('token') ?? $request->query('token', '');
        $result = $this->parkingService->verifyParkingPass((string) $token);

        return response()->json($result);
    }
}
