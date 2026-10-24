<?php

namespace App\Http\Controllers\Api;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Exceptions\ScanNotConfirmable;
use App\Http\Controllers\Controller;
use App\Http\Resources\VisitorResource;
use App\Models\AccessLogEntry;
use App\Models\BlocklistEntry;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitorApiController extends Controller
{
    public function __construct(
        private readonly GatePassEngine $engine,
        private readonly GateScanner $scanner,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $visitors = Visitor::query()
            ->when(! $user->can('manageSecurity'), fn ($q) => $q->where('homeowner_id', $user->id))
            ->when($request->string('status')->isNotEmpty(),
                fn ($q) => $q->where('status', $request->string('status')))
            ->latest('expected_at')
            ->limit(100)
            ->get();

        return response()->json(['visitors' => VisitorResource::collection($visitors)->resolve()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('registerVisitors');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:120'],
            'type' => ['required', 'in:One-time,Recurring'],
            'expected_at' => ['required', 'date'],
            'date_range' => ['nullable', 'string', 'max:120'],
            'notify_email' => ['nullable', 'boolean'],
            'notify_sms' => ['nullable', 'boolean'],
            'notify_whatsapp' => ['nullable', 'boolean'],
            'pass_category' => ['nullable', 'in:VISITOR,CONTRACTOR'],
        ]);

        $passCategory = PassCategory::from($validated['pass_category'] ?? PassCategory::Visitor->value);
        unset($validated['pass_category']);

        if ($this->isOnBlocklist($validated['name'])) {
            return response()->json([
                'message' => 'This person is on the community blocklist and cannot be registered.',
                'errors' => ['name' => ['Blocked by community security.']],
            ], 422);
        }

        $user = $request->user();

        $visitor = Visitor::create([
            ...$validated,
            'status' => 'Expected',
            'homeowner_id' => $user->id,
            'homeowner_name' => $user->display_name,
            'notify_email' => $validated['notify_email'] ?? true,
            'notify_sms' => $validated['notify_sms'] ?? false,
            'notify_whatsapp' => $validated['notify_whatsapp'] ?? false,
        ]);

        $pass = $this->engine->issueGuestPass($visitor, $user, $passCategory);

        // Send the pass on whichever chosen channels can actually deliver it.
        $channels = $visitor->passNotificationChannels();

        if ($channels !== [] && $pass->status !== PassStatus::Requested) {
            $visitor->sendPassNotification($channels);
        }

        return response()->json([
            'visitor' => ['id' => $visitor->id],
            'pass' => ['passId' => $pass->pass_id, 'status' => $pass->status->value],
        ], 201);
    }

    public function checkIn(Request $request, Visitor $visitor): JsonResponse
    {
        if ($visitor->gatePass) {
            try {
                $this->scanner->manualCheckIn($visitor->gatePass, $request->user(), $this->gateFrom($request));
            } catch (ScanNotConfirmable $e) {
                return response()->json(['allowed' => false, 'reason' => $e->getMessage()], 422);
            }

            return response()->json(['allowed' => true, 'status' => $visitor->fresh()->status->value]);
        }

        if ($this->isOnBlocklist($visitor->name)) {
            $visitor->update(['is_blocked' => true]);

            return response()->json([
                'allowed' => false,
                'reason' => 'BLOCKLIST: Visitor is on the community blocklist.',
            ], 422);
        }

        $visitor->checkIn();

        AccessLogEntry::create([
            'user_id' => $visitor->homeowner_id,
            'user_name' => $visitor->name,
            'user_role' => 'Visitor',
            'method' => 'Visitor Pass',
            'gate' => $request->string('gate')->toString() ?: 'Main Gate',
            'result' => 'ALLOW',
            'scanned_by' => $request->user()->id,
            'occurred_at' => now(),
        ]);

        return response()->json(['allowed' => true, 'status' => $visitor->status->value]);
    }

    public function checkOut(Request $request, Visitor $visitor): JsonResponse
    {
        if ($visitor->gatePass) {
            try {
                $this->scanner->manualCheckOut($visitor->gatePass, $request->user(), $this->gateFrom($request));
            } catch (ScanNotConfirmable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(['status' => $visitor->fresh()->status->value]);
        }

        $visitor->checkOut();

        return response()->json(['status' => $visitor->status->value]);
    }

    private function isOnBlocklist(string $name): bool
    {
        return BlocklistEntry::blocks($name);
    }

    private function gateFrom(Request $request): GateId
    {
        return GateId::tryFrom($request->string('gate')->toString()) ?? GateId::Gate01;
    }
}
