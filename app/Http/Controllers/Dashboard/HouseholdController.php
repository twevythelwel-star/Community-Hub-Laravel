<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Household\StoreHouseholdMemberRequest;
use App\Http\Requests\Household\UpdateHouseholdMemberRequest;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Services\GatePassEngine;
use App\Services\HouseholdManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class HouseholdController extends Controller
{
    public function __construct(
        private readonly HouseholdManagementService $householdService,
        private readonly GatePassEngine $engine,
    ) {}

    /**
     * Display the Family & Household Management dashboard.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('create', HouseholdMember::class);

        $user = $request->user();
        $household = $this->householdService->getOrCreateHousehold($user);

        return Inertia::render('Dashboard/Household', [
            'household' => [
                'id' => $household->id,
                'name' => $household->name,
                'property_number' => $household->property_number,
                'address' => $household->address,
                'notes' => $household->notes,
                'members' => $household->members->map(function (HouseholdMember $m) {
                    $token = null;
                    if ($m->gatePass && $m->gatePass->isActive()) {
                        try {
                            $token = $this->engine->issueToken($m->gatePass)['token'];
                        } catch (\Throwable $e) {
                            $token = $m->gatePass->pass_id;
                        }
                    }

                    return [
                        'id' => $m->id,
                        'name' => $m->name,
                        'email' => $m->email,
                        'phone' => $m->phone,
                        'role_in_household' => $m->role_in_household,
                        'relationship_label' => $m->relationship_label,
                        'pass_category' => $m->pass_category,
                        'credential_label' => $m->credentialTypeLabel(),
                        'role_badge_class' => $m->roleBadgeColor(),
                        'permissions' => $m->permissions ?? [],
                        'access_schedule' => $m->access_schedule,
                        'status' => $m->status,
                        'valid_until' => $m->valid_until?->toFormattedDateString(),
                        'gate_pass' => $m->gatePass ? [
                            'id' => $m->gatePass->id,
                            'pass_id' => $m->gatePass->pass_id,
                            'category' => $m->gatePass->category->value,
                            'status' => $m->gatePass->status->value,
                            'holder_name' => $m->gatePass->holder_name,
                            'token' => $token,
                        ] : null,
                    ];
                }),
            ],
            'availablePermissions' => HouseholdManagementService::AVAILABLE_PERMISSIONS,
            'isOwner' => true,
        ]);
    }

    /**
     * Add a new member to the household.
     */
    public function store(StoreHouseholdMemberRequest $request): RedirectResponse|JsonResponse
    {
        $household = $this->householdService->getOrCreateHousehold($request->user());
        $member = $this->householdService->addMember($household, $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Household member {$member->name} added successfully.",
                'member' => $member,
            ], 201);
        }

        return back()->with('success', "Household member {$member->name} added with digital gate credential.");
    }

    /**
     * Update permissions or details for a household member.
     */
    public function update(UpdateHouseholdMemberRequest $request, HouseholdMember $member): RedirectResponse|JsonResponse
    {
        $updated = $this->householdService->updateMember($member, $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Household member updated successfully.',
                'member' => $updated,
            ]);
        }

        return back()->with('success', "Updated permissions for {$updated->name}.");
    }

    /**
     * Remove a household member and revoke their gate pass.
     */
    public function destroy(Request $request, HouseholdMember $member): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $member);

        $name = $member->name;
        $this->householdService->removeMember($member);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Member {$name} removed and digital credential revoked.",
            ]);
        }

        return back()->with('success', "Member {$name} removed and digital credential revoked.");
    }
}
