<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Re-enter your password before a sensitive action (the `password.confirm`
 * middleware). The dashboard's password dialog posts here as JSON; a page or
 * download that needed confirmation lands on show() and is sent back to it.
 */
class ConfirmPasswordController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Dashboard/ConfirmPassword');
    }

    public function store(Request $request): JsonResponse|RedirectResponse|SymfonyResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ], [
            'password.current_password' => 'That password is incorrect.',
        ]);

        $request->session()->passwordConfirmed();
        $request->user()->recordActivity('Confirmed their password for a sensitive action');

        if ($request->expectsJson()) {
            return response()->json(['confirmed' => true]);
        }

        $intended = $request->session()->pull('url.intended', route('dashboard.index'));

        // Usually an export: a file download cannot be an Inertia page, so
        // leave Inertia with a full navigation.
        return $request->header('X-Inertia') ? Inertia::location($intended) : redirect()->to($intended);
    }
}
