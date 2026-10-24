<?php

namespace App\Http\Controllers;

use App\Enums\PassCategory;
use App\Enums\VisitorStatus;
use App\Models\BlocklistEntry;
use App\Models\Community;
use App\Models\EventInvite;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\Messaging\PhoneNumber;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EventRsvpController extends Controller
{
    /**
     * Resident creates a shareable RSVP link for their event.
     */
    public function createInvite(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'expected_at' => ['required', 'date'],
            'max_guests' => ['nullable', 'integer', 'min:1', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $token = 'inv_'.Str::lower(Str::random(12));

        $invite = EventInvite::create([
            'host_id' => $user->id,
            'title' => $validated['title'],
            'token' => $token,
            'expected_at' => Carbon::parse($validated['expected_at']),
            'max_guests' => $validated['max_guests'] ?? 50,
            'notes' => $validated['notes'] ?? null,
            'is_active' => true,
        ]);

        return back()->with([
            'success' => "Event RSVP link generated: {$invite->title}",
            'rsvp_url' => $invite->rsvpUrl(),
        ]);
    }

    /**
     * Display public RSVP form for attendees.
     */
    public function show(string $token): View
    {
        $invite = EventInvite::with('host')->where('token', $token)->firstOrFail();
        $community = Community::first();

        $isClosed = ! $invite->is_active || $invite->expected_at->isPast();

        return view('blade.event-rsvp', [
            'invite' => $invite,
            'community' => $community,
            'isClosed' => $isClosed,
        ]);
    }

    /**
     * Process attendee registration.
     */
    public function submit(Request $request, string $token, GatePassEngine $engine, SmsService $sms): View|RedirectResponse
    {
        $invite = EventInvite::with('host')->where('token', $token)->firstOrFail();

        if (! $invite->is_active || $invite->expected_at->isPast()) {
            return back()->withErrors(['general' => 'This RSVP invitation is closed or has expired.']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:50'],
            'vehicle' => ['nullable', 'string', 'max:20'],
        ]);

        $name = trim($validated['name']);

        // Check blocklist
        if (BlocklistEntry::blocks($name)) {
            return back()->withInput()->withErrors([
                'name' => 'Registration cannot be processed at this time. Please contact your host directly.',
            ]);
        }

        $visitor = Visitor::create([
            'homeowner_id' => $invite->host_id,
            'name' => $name,
            'type' => 'Visitor',
            'contact' => $validated['contact'],
            'vehicle' => $validated['vehicle'] ? strtoupper(trim($validated['vehicle'])) : null,
            'expected_at' => $invite->expected_at,
            'status' => VisitorStatus::Expected,
            'notify_sms' => true,
        ]);

        // Issue gate pass
        $gatePass = $engine->issueGuestPass($visitor, $invite->host, PassCategory::Visitor);

        // Send SMS if phone number is valid
        $phoneE164 = PhoneNumber::toE164($visitor->contact);
        $smsSent = false;
        if ($phoneE164 && $sms->isConfigured()) {
            try {
                $sms->sendVisitorPass(
                    $phoneE164,
                    $visitor->guestPassUrl(),
                    $visitor->name,
                    $invite->host->display_name ?? 'Resident Host',
                    $invite->expected_at->format('M j, Y g:i A')
                );
                $smsSent = true;
            } catch (\Throwable $e) {
                // Non-blocking
            }
        }

        $community = Community::first();

        return view('blade.event-rsvp-success', [
            'visitor' => $visitor,
            'invite' => $invite,
            'community' => $community,
            'smsSent' => $smsSent,
        ]);
    }
}
