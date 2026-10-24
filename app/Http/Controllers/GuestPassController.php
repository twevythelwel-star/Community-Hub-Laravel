<?php

namespace App\Http\Controllers;

use App\Enums\PassStatus;
use App\Enums\VisitorStatus;
use App\Models\BrandingSetting;
use App\Models\Community;
use App\Models\GatePass;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\QrCodePng;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class GuestPassController extends Controller
{
    public function __construct(
        private readonly GatePassEngine $engine,
        private readonly QrCodePng $qr,
    ) {}

    /**
     * Display the public, mobile-first guest pass.
     */
    public function show(string $token): View
    {
        $visitor = Visitor::with(['homeowner', 'gatePass'])->where('share_token', $token)->firstOrFail();
        $community = Community::first();

        $isExpired = $visitor->expired_at !== null ||
            ($visitor->expected_at && $visitor->expected_at->addHours(12)->isPast() && $visitor->status === VisitorStatus::Expected);

        $statusColor = match ($visitor->status) {
            VisitorStatus::CheckedIn => 'bg-emerald-500',
            VisitorStatus::CheckedOut => 'bg-slate-500',
            VisitorStatus::Expected => $isExpired ? 'bg-rose-500' : 'bg-primary',
        };

        return view('blade.guest-pass', [
            'visitor' => $visitor,
            'community' => $community,
            'branding' => BrandingSetting::current(),
            'isExpired' => $isExpired,
            'statusColor' => $statusColor,
            'code' => $this->codeFor($visitor->gatePass),
            'arrivalWindow' => $visitor->arrivalWindowLabel(),
            'parkingInstructions' => $visitor->parkingInstructions(),
            'communityRules' => $visitor->communityRules(),
            'emergencyInfo' => $visitor->emergencyInfo(),
            'hostProperty' => $visitor->hostPropertyLabel(),
        ]);
    }

    /**
     * The current gate code, refreshed by the page every few seconds.
     *
     * The QR used to be drawn by api.qrserver.com from the guest-pass URL, which
     * sent that URL (anyone holding it can open the pass) to a third party, and
     * the image it drew was a link, not a credential. It is now a short-lived
     * signed token, rendered here, that the gate scanner validates.
     */
    public function code(string $token): JsonResponse
    {
        $visitor = Visitor::with('gatePass')->where('share_token', $token)->firstOrFail();

        return response()->json($this->codeFor($visitor->gatePass));
    }

    /**
     * @return array{available: bool, message: string|null, qr: string|null, validUntil: string|null, status: string|null}
     */
    private function codeFor(?GatePass $pass): array
    {
        $unavailable = fn (string $message) => [
            'available' => false, 'message' => $message, 'qr' => null, 'validUntil' => null, 'status' => $pass?->status->value,
        ];

        if (! $pass) {
            return $unavailable('Show this page to the officer at the gate.');
        }

        if ($pass->status === PassStatus::Requested || $pass->status === PassStatus::Approved) {
            return $unavailable('Your pass is waiting for approval by estate security.');
        }

        if (! $pass->isActive()) {
            return $unavailable("This pass is {$pass->status->label()} and can no longer be used.");
        }

        if ($pass->status !== PassStatus::CheckedIn && $pass->valid_from?->isFuture()) {
            return $unavailable('Your code appears here from '.$pass->valid_from->format('D j M, g:i A').'.');
        }

        $issued = $this->engine->issueToken($pass);

        return [
            'available' => true,
            'message' => null,
            'qr' => 'data:image/png;base64,'.base64_encode($this->qr->render($issued['token'], 240, 2)),
            'validUntil' => $issued['valid_until']->toIso8601String(),
            'status' => $pass->status->value,
            'pin' => $pass->offline_pin,
        ];
    }
}
