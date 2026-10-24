<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\OwnershipEntity;
use App\Services\Properties\CompanyDashboardPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A company's properties, people and activity, scoped to the viewer's appointment. */
class CompanyDashboardController extends Controller
{
    public function __construct(private readonly CompanyDashboardPresenter $presenter) {}

    /** The viewer's company (or the one asked for, if they may see it). */
    public function index(Request $request): Response
    {
        $company = $this->presenter->resolveCompanyForUser($request->user(), $request->input('company_id'));

        return Inertia::render('Dashboard/Company', $this->presenter->props($request, $company));
    }

    public function show(Request $request, OwnershipEntity $entity): Response
    {
        $this->authorize('view', $entity);

        return Inertia::render('Dashboard/Company', $this->presenter->props($request, $entity));
    }
}
