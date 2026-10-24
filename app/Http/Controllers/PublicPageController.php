<?php

namespace App\Http\Controllers;

use App\Models\BrandingSetting;
use App\Models\Community;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Public entry and policy pages, rendered with Blade.
 *
 * Replaces src/app/page.tsx and src/app/privacy/page.tsx. In the original app
 * "/" was itself the login screen, so that behaviour is kept: a signed-out
 * visitor lands on the sign-in form, a signed-in one goes straight through.
 *
 * Neither page carries application state, so serving them as HTML avoids
 * shipping the React bundle to visitors who have not signed in.
 */
class PublicPageController extends Controller
{
    public function landing(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard.index');
        }

        return view('blade.landing', [
            'branding' => BrandingSetting::current(),
            'community' => Community::default(),
        ]);
    }

    public function privacy(): View
    {
        return $this->policy('privacy');
    }

    public function terms(): View
    {
        return $this->policy('terms');
    }

    public function refunds(): View
    {
        return $this->policy('refunds');
    }

    public function cookies(): View
    {
        return $this->policy('cookies');
    }

    /**
     * Policy pages share a shape: branded layout, no application state.
     *
     * Each renders a prominent notice while config('legal.complete') is false,
     * so an unfinished template cannot be mistaken for a published policy.
     */
    protected function policy(string $name): View
    {
        return view("blade.{$name}", [
            'branding' => BrandingSetting::current(),
        ]);
    }
}
