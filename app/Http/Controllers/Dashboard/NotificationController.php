<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Events\Realtime\LiveNotificationEvent;
use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\InAppNotification;
use App\Models\Notification;
use App\Models\User;
use App\Models\Visitor;
use App\Services\NotificationEngine\NotificationEngine;
use App\Services\NotificationTargetingService;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backs src/app/dashboard/notifications/page.tsx and notification-form.tsx.
 *
 * Three things the original page only appeared to do:
 *
 *   1. "Send Notification" called console.log and raised a toast saying the
 *      notice had been sent. Nothing was stored and nobody received it.
 *   2. The notice list was two hardcoded objects, so a published notice could
 *      never have appeared there anyway.
 *   3. The AI helper imported a client-side Genkit flow that never called a
 *      model, under a caption reading "Powered by Google Gemini".
 *
 * And one it did not do at all: the flow is named "generate targeted
 * notifications", the table carries `target_roles` and Notification::forRole()
 * filters on it — but no control ever set an audience, so every notice went to
 * everyone. The composer now picks the audience explicitly.
 */
class NotificationController extends Controller
{
    public function index(Request $request, NotificationEngine $engine): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Notifications', [
            'notifications' => Notification::forRole($user->role->value)
                ->latest('published_at')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Notification $n) => [
                    'id' => $n->id,
                    'title' => $n->title,
                    'content' => $n->content,
                    'author' => $n->author_name,
                    'timestamp' => $n->published_at->toIso8601String(),
                    'targetRoles' => $n->target_roles,
                ]),

            'canBroadcast' => $user->can('broadcastNotices'),
            'roles' => array_column(UserRole::cases(), 'value'),
            'community' => $this->communityName(),

            /*
             | Whether the AI helper will reach a model at all. Without a key the
             | service returns the original stub's four canned lines, and the
             | composer should not be told Gemini wrote them.
             */
            'aiEnabled' => filled(config('services.googleai.key')),
            'notificationChannels' => $engine->getChannelsStatus(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'content' => ['required', 'string', 'max:5000'],
            'target_roles' => ['nullable', 'array'],
            'target_roles.*' => ['string', 'in:'.implode(',', array_column(UserRole::cases(), 'value'))],
        ]);

        $user = $request->user();
        $roles = array_values(array_unique($validated['target_roles'] ?? []));

        /*
         | An empty array matches neither branch of Notification::forRole() — not
         | the whereNull, not the whereJsonContains — so a notice stored with []
         | would be invisible to everybody, including the person who wrote it.
         | "No audience chosen" has to mean everyone, which is null.
         */
        $notification = Notification::create([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'target_roles' => $roles === [] ? null : $roles,
            'author_id' => $user->id,
            'author_name' => $user->display_name,
            'published_at' => now(),
        ]);

        $user->recordActivity("Published notice: {$notification->title}");

        return back()->with('success', $roles === []
            ? 'Notice published to the whole community.'
            : 'Notice published to '.implode(', ', $roles).'.');
    }

    /**
     * Drafts notice copy from a pasted document.
     *
     * Consent is checked here rather than only in the dialog. The promise made
     * to the user is that their text reaches Google only if they agree, and a
     * promise enforced in the browser is not enforced at all — the endpoint was
     * reachable by anyone with `broadcastNotices` regardless of what they had
     * agreed to, and the agreement itself lived in localStorage, so clearing
     * site data silently revoked a consent no record was ever kept of.
     */
    public function suggestAudience(Request $request, NotificationTargetingService $targeting): JsonResponse
    {
        $user = $request->user();

        if (! $user->ai_consent) {
            return response()->json([
                'message' => 'Your consent is needed before any text is sent to Google for processing.',
                'reason' => 'ai_consent_required',
            ], 403);
        }

        $validated = $request->validate([
            'document' => ['required', 'string', 'max:20000'],
        ]);

        /*
         | The community is not the composer's to type. The original offered a
         | free-text "Target Community" box defaulting to "Green Meadows", which
         | only ever decided what the model was told the estate was called.
         */
        $result = $targeting->generate($validated['document'], $this->communityName());

        $user->recordActivity('Sent a document to the AI drafting service');

        return response()->json($result);
    }

    /**
     * Dispatch an emergency broadcast notice across Push, SMS, WhatsApp, Email, and In-App.
     */
    public function broadcastEmergency(
        Request $request,
        SmsService $sms,
        WhatsAppService $whatsapp
    ): RedirectResponse|JsonResponse {
        $user = $request->user();
        if (! $user->can('broadcastNotices') && ! $user->can('manageSecurity')) {
            abort(403, 'Unauthorized to dispatch emergency broadcasts.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'content' => ['required', 'string', 'max:1000'],
            'severity' => ['required', 'string', 'in:info,advisory,emergency'],
            'audience' => ['required', 'string', 'in:all,building,property,residents,staff,security,legacy_contacts,long_term_guests,visitors'],
            'target_building' => ['nullable', 'required_if:audience,building', 'string', 'max:100'],
            'target_property' => ['nullable', 'required_if:audience,property', 'string', 'max:100'],
            'send_push' => ['nullable', 'boolean'],
            'send_sms' => ['nullable', 'boolean'],
            'send_whatsapp' => ['nullable', 'boolean'],
            'send_email' => ['nullable', 'boolean'],
            'send_in_app' => ['nullable', 'boolean'],
        ]);

        $title = $validated['title'];
        $content = $validated['content'];
        $severity = strtoupper($validated['severity']);
        $audience = $validated['audience'];
        $targetBuilding = $validated['target_building'] ?? null;
        $targetProperty = $validated['target_property'] ?? null;

        $sendPush = (bool) ($validated['send_push'] ?? true);
        $sendSms = (bool) ($validated['send_sms'] ?? false);
        $sendWhatsApp = (bool) ($validated['send_whatsapp'] ?? false);
        $sendEmail = (bool) ($validated['send_email'] ?? false);
        $sendInApp = (bool) ($validated['send_in_app'] ?? true);

        $prefix = match ($validated['severity']) {
            'emergency' => '🚨 [EMERGENCY ALERT] ',
            'advisory' => '⚠️ [ADVISORY] ',
            default => '📢 [COMMUNITY NOTICE] ',
        };

        // Determine recipient users and contacts based on target audience
        $usersQuery = User::query()->whereNull('deactivated_at');
        $visitorQuery = Visitor::query()->where('status', VisitorStatus::CheckedIn->value);

        match ($audience) {
            'residents' => $usersQuery->whereIn('role', [UserRole::Homeowner->value, UserRole::TemporaryHomeowner->value]),
            'staff' => $usersQuery->where('role', UserRole::Staff->value),
            'security' => $usersQuery->where('role', UserRole::Security->value),
            'building' => $usersQuery->when($targetBuilding, function ($q) use ($targetBuilding) {
                $term = strtolower(trim($targetBuilding));
                $q->where(function ($sub) use ($term) {
                    $sub->whereRaw('LOWER(street) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(lot) LIKE ?', ["%{$term}%"]);
                });
            }),
            'property' => $usersQuery->when($targetProperty, function ($q) use ($targetProperty) {
                $clean = preg_replace('/[^0-9]/', '', $targetProperty);
                $cleanUnit = $clean ?: trim($targetProperty);
                $q->where(function ($sub) use ($cleanUnit, $targetProperty) {
                    $sub->where('lot', $cleanUnit)
                        ->orWhere('lot', $targetProperty)
                        ->orWhereRaw('LOWER(street) LIKE ?', ['%'.strtolower($targetProperty).'%']);
                });
            }),
            default => null,
        };

        $targetUsers = in_array($audience, ['visitors'], true) ? collect() : $usersQuery->get();

        // Audience roles list for notice persistence
        $noticeTargetRoles = match ($audience) {
            'residents' => [UserRole::Homeowner->value, UserRole::TemporaryHomeowner->value],
            'staff' => [UserRole::Staff->value],
            'security' => [UserRole::Security->value],
            default => null,
        };

        $notice = Notification::create([
            'title' => $prefix.$title,
            'content' => $content,
            'target_roles' => $noticeTargetRoles,
            'author_id' => $user->id,
            'author_name' => $user->display_name,
            'published_at' => now(),
        ]);

        $inAppCount = 0;
        $pushCount = 0;
        $smsCount = 0;
        $whatsAppCount = 0;
        $emailCount = 0;

        // 1. In-App Notifications & Realtime Push
        foreach ($targetUsers as $recipUser) {
            if ($sendInApp) {
                InAppNotification::create([
                    'user_id' => $recipUser->id,
                    'category' => 'emergency',
                    'title' => $prefix.$title,
                    'body' => $content,
                    'priority' => ($severity === 'EMERGENCY' ? 'urgent' : 'high'),
                    'action_url' => '/dashboard/occupancy',
                ]);
                $inAppCount++;
            }

            if ($sendPush) {
                try {
                    LiveNotificationEvent::dispatch(
                        $recipUser->id,
                        $prefix.$title,
                        $content,
                        '/dashboard/occupancy',
                        $severity === 'EMERGENCY' ? 'urgent' : 'high',
                        'emergency'
                    );
                    $pushCount++;
                } catch (\Throwable $e) {
                    // Ignore broadcast driver hiccups in test environments
                }
            }
        }

        // Collect phone numbers for SMS & WhatsApp
        $phoneNumbers = collect();
        if (in_array($audience, ['all', 'residents', 'staff', 'security', 'building', 'property'], true)) {
            $phoneNumbers = $phoneNumbers->merge($targetUsers->pluck('phone'));
        }

        // Legacy contacts / delegates
        if (in_array($audience, ['all', 'legacy_contacts'], true)) {
            $delegates = DelegatedAccess::where('status', 'active')
                ->whereNotNull('phone')
                ->pluck('phone');
            $phoneNumbers = $phoneNumbers->merge($delegates);
        }

        // Long-term guests
        if (in_array($audience, ['all', 'long_term_guests'], true)) {
            $ltgPhones = GatePass::where('category', PassCategory::LongTermOccupant)
                ->whereIn('status', [PassStatus::Active, PassStatus::CheckedIn])
                ->get()
                ->map(fn ($p) => $p->metadata['contact'] ?? null)
                ->filter();
            $phoneNumbers = $phoneNumbers->merge($ltgPhones);
        }

        // Active visitors on site
        if (in_array($audience, ['all', 'visitors'], true) || ($audience === 'property' && $targetProperty)) {
            $visitorPhones = $visitorQuery
                ->when($audience === 'property' && $targetProperty, function ($q) use ($targetProperty) {
                    $clean = preg_replace('/[^0-9]/', '', $targetProperty);
                    $q->whereHas('homeowner', fn ($sub) => $sub->where('lot', $clean ?: $targetProperty));
                })
                ->whereNotNull('contact')
                ->pluck('contact');
            $phoneNumbers = $phoneNumbers->merge($visitorPhones);
        }

        $uniquePhones = $phoneNumbers->filter()->unique()->values();
        $alertMessage = "{$prefix}{$title}: {$content}";

        // 2. SMS Alerts
        if ($sendSms && $sms->isConfigured()) {
            foreach ($uniquePhones as $phone) {
                try {
                    $sms->send((string) $phone, $alertMessage);
                    $smsCount++;
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch emergency SMS to {$phone}: ".$e->getMessage());
                }
            }
        }

        // 3. WhatsApp Alerts
        if ($sendWhatsApp && $whatsapp->isConfigured()) {
            foreach ($uniquePhones as $phone) {
                try {
                    $whatsapp->send((string) $phone, $alertMessage);
                    $whatsAppCount++;
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch emergency WhatsApp to {$phone}: ".$e->getMessage());
                }
            }
        }

        // 4. Email Alerts
        if ($sendEmail) {
            $emails = $targetUsers->pluck('email')->filter()->unique();
            foreach ($emails as $email) {
                try {
                    Mail::raw($alertMessage, function ($m) use ($email, $prefix, $title) {
                        $m->to($email)->subject("{$prefix}{$title}");
                    });
                    $emailCount++;
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch emergency Email to {$email}: ".$e->getMessage());
                }
            }
        }

        $summaryMsg = "Emergency broadcast dispatched: {$inAppCount} In-App, {$smsCount} SMS, {$whatsAppCount} WhatsApp, {$emailCount} Emails, {$pushCount} Push alerts.";
        $user->recordActivity("Dispatched {$severity} broadcast to audience '{$audience}': {$title} ({$smsCount} SMS, {$whatsAppCount} WhatsApp, {$emailCount} Email)");

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $summaryMsg,
                'stats' => [
                    'in_app' => $inAppCount,
                    'sms' => $smsCount,
                    'whatsapp' => $whatsAppCount,
                    'email' => $emailCount,
                    'push' => $pushCount,
                ],
            ]);
        }

        return back()->with('success', $summaryMsg);
    }

    private function communityName(): string
    {
        return Community::query()
            ->where('code', config('gatepass.default_community_id'))
            ->value('name') ?? config('app.name');
    }
}
