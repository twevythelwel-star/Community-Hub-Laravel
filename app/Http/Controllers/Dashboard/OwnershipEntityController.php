<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ownership\AppointRepresentativeRequest;
use App\Http\Requests\Ownership\AssignPropertyRequest;
use App\Http\Requests\Ownership\RevokeRepresentativeRequest;
use App\Http\Requests\Ownership\StoreOwnershipEntityRequest;
use App\Http\Requests\Ownership\UpdateBeneficialOwnershipRequest;
use App\Http\Requests\Ownership\VerifyOwnershipEntityRequest;
use App\Models\AuthorizedPerson;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Services\Properties\OwnershipEntityService;
use App\Services\Properties\OwnershipPagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Who may do what is in OwnershipEntityPolicy and the requests. */
class OwnershipEntityController extends Controller
{
    public function __construct(
        protected OwnershipEntityService $entityService,
        protected OwnershipPagePresenter $presenter,
    ) {}

    /**
     * An entity, its properties, its representatives and its verification.
     */
    public function show(Request $request, OwnershipEntity $entity): JsonResponse
    {
        $this->authorize('view', $entity);

        return response()->json($this->presenter->entity($entity, $request->user()));
    }

    public function store(StoreOwnershipEntityRequest $request): JsonResponse|RedirectResponse
    {
        $entity = $this->entityService->createEntity($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Ownership entity registered successfully.',
                'entity' => $entity,
            ], 201);
        }

        return back()->with('success', "Ownership entity {$entity->legal_name} registered successfully.");
    }

    public function addRepresentative(AppointRepresentativeRequest $request, OwnershipEntity $entity): JsonResponse|RedirectResponse
    {
        $person = $this->entityService->appointRepresentative($entity, $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Representative {$person->full_name} appointed successfully.",
                'representative' => $person,
            ], 201);
        }

        return back()->with('success', "Representative {$person->full_name} appointed successfully.");
    }

    /**
     * Revoke a representative's authorization (e.g. employee departure).
     * Property ownership is not affected.
     */
    public function revokeRepresentative(RevokeRepresentativeRequest $request, OwnershipEntity $entity, AuthorizedPerson $person): JsonResponse|RedirectResponse
    {
        $this->entityService->revokeAuthorizedPerson($person, $request->validated('reason') ?: 'Authorization revoked by entity administration.');

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Authorization for {$person->full_name} has been revoked. Property ownership remains unchanged.",
                'person_status' => 'revoked',
                'entity_id' => $entity->id,
            ]);
        }

        return back()->with('success', "Authorization for {$person->full_name} has been revoked.");
    }

    /** Transfers a property's ownership: administrators only. */
    public function assignProperty(AssignPropertyRequest $request, OwnershipEntity $entity, Property $property): JsonResponse|RedirectResponse
    {
        $this->entityService->assignProperty($entity, $property);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Property {$property->label()} is now owned by {$entity->legal_name}.",
                'property' => $property->fresh(),
            ]);
        }

        return back()->with('success', "Property {$property->label()} assigned to {$entity->legal_name}.");
    }

    public function verify(VerifyOwnershipEntityRequest $request, OwnershipEntity $entity): JsonResponse|RedirectResponse
    {
        $updated = $this->entityService->verifyOwnershipEntity($entity, $request->validated(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Ownership entity {$updated->legal_name} verified successfully.",
                'verification' => $updated->getVerificationSummary(),
                'entity' => $updated,
            ]);
        }

        return back()->with('success', "Ownership entity {$updated->legal_name} verified successfully.");
    }

    /**
     * Confidential beneficial ownership: administrators and the entity's
     * directors or signatories only.
     */
    public function beneficialOwnership(Request $request, OwnershipEntity $entity): JsonResponse
    {
        $this->authorize('viewBeneficialOwnership', $entity);

        return response()->json([
            'beneficial_ownership' => $entity->getBeneficialOwnershipProfile($request->user()),
        ]);
    }

    public function updateBeneficialOwnership(UpdateBeneficialOwnershipRequest $request, OwnershipEntity $entity): JsonResponse|RedirectResponse
    {
        $updated = $this->entityService->updateBeneficialOwnership($entity, $request->validated(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Beneficial ownership records updated.',
                'beneficial_ownership' => $updated->getBeneficialOwnershipProfile($request->user()),
            ]);
        }

        return back()->with('success', 'Beneficial ownership records updated successfully.');
    }
}
