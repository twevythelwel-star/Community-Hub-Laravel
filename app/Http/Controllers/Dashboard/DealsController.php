<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FoodApp;
use App\Models\Voucher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Backs src/app/dashboard/deals/page.tsx, voucher-form.tsx, and food-app-form.tsx. */
class DealsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard/Deals', [
            'businesses' => Business::with('vouchers')
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Business $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'logoUrl' => $b->logo_url,
                    'aiHint' => $b->ai_hint,
                    'vouchers' => $b->vouchers
                        ->reject(fn ($v) => $v->isExpired())
                        ->map(fn ($v) => [
                            'id' => $v->id,
                            'title' => $v->title,
                            'description' => $v->description,
                            'expiresAt' => $v->expires_at?->toIso8601String(),
                        ])->values(),
                ]),
            'foodApps' => FoodApp::orderBy('name')
                ->get()
                ->map(fn (FoodApp $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'logoUrl' => $f->logo_url,
                    'websiteUrl' => $f->website_url,
                    'aiHint' => $f->ai_hint,
                    'couponPercentage' => $f->coupon_percentage,
                ]),
            'canManage' => $request->user()->can('manageUsers'),
        ]);
    }

    public function storeBusiness(Request $request): RedirectResponse
    {
        $this->authorize('manageUsers');

        // Normalize camelCase from front-end
        $data = [
            'name' => $request->input('name'),
            'logo_url' => $request->input('logo_url', $request->input('logoUrl')),
            'ai_hint' => $request->input('ai_hint', $request->input('aiHint')),
        ];

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:120'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'ai_hint' => ['nullable', 'string', 'max:160'],
        ])->validate();

        Business::create([...$validated, 'active' => true]);

        return back()->with('success', 'Business added.');
    }

    public function destroyBusiness(Request $request, Business $business): RedirectResponse
    {
        $this->authorize('manageUsers');

        $business->update(['active' => false]);

        return back()->with('success', 'Business deactivated.');
    }

    public function storeFoodApp(Request $request): RedirectResponse
    {
        $this->authorize('manageUsers');

        // Normalize camelCase from front-end
        $data = [
            'name' => $request->input('name'),
            'website_url' => $request->input('website_url', $request->input('websiteUrl')),
            'logo_url' => $request->input('logo_url', $request->input('logoUrl')),
            'ai_hint' => $request->input('ai_hint', $request->input('aiHint')),
            'coupon_percentage' => $request->input('coupon_percentage', $request->input('couponPercentage')),
        ];

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:120'],
            'website_url' => ['required', 'url', 'max:2048'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'ai_hint' => ['nullable', 'string', 'max:160'],
            'coupon_percentage' => ['nullable', 'integer', 'between:0,100'],
        ])->validate();

        FoodApp::create($validated);

        return back()->with('success', 'Food delivery option added.');
    }

    public function destroyFoodApp(Request $request, FoodApp $foodApp): RedirectResponse
    {
        $this->authorize('manageUsers');

        $foodApp->delete();

        return back()->with('success', 'Food delivery option removed.');
    }

    public function storeVoucher(Request $request): RedirectResponse
    {
        $this->authorize('manageUsers');

        $data = [
            'business_id' => $request->input('business_id', $request->input('businessId')),
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'expires_at' => $request->input('expires_at', $request->input('expiresAt')),
        ];

        $validated = validator($data, [
            'business_id' => ['required', 'exists:businesses,id'],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            'expires_at' => ['nullable', 'date'],
        ])->validate();

        Voucher::create($validated);

        return back()->with('success', 'Voucher published.');
    }

    public function destroyVoucher(Request $request, Voucher $voucher): RedirectResponse
    {
        $this->authorize('manageUsers');

        $voucher->delete();

        return back()->with('success', 'Voucher deleted.');
    }
}
