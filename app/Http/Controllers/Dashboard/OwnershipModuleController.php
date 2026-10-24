<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ownership\AppointRepresentativeRequest;
use App\Http\Requests\Ownership\RevokeRepresentativeRequest;
use App\Models\AuthorizedPerson;
use App\Services\Properties\OwnershipEntityService;
use App\Services\Properties\OwnershipPagePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The Ownership page: "Ownership & Household" for an individual owner,
 * "Ownership & Representatives" for a company, trustees for a trust,
 * executors for an estate.
 */
class OwnershipModuleController extends Controller
{
    public function __construct(
        protected OwnershipEntityService $entityService,
        protected OwnershipPagePresenter $presenter,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Dashboard/Ownership', $this->presenter->page(
            $request->user(),
            $request->string('type')->toString() ?: null,
            $request->integer('entity_id') ?: null,
        ));
    }

    public function storeRepresentative(AppointRepresentativeRequest $request): RedirectResponse
    {
        $person = $this->entityService->appointRepresentative($request->entity(), $request->validated());

        return back()->with('success', "Representative '{$person->full_name}' appointed successfully.");
    }

    public function revokeRepresentative(RevokeRepresentativeRequest $request, AuthorizedPerson $person): RedirectResponse
    {
        $this->entityService->revokeAuthorizedPerson($person, $request->validated('reason') ?: 'Administrative revocation');

        return back()->with('success', "Authorization for '{$person->full_name}' has been revoked.");
    }
}
