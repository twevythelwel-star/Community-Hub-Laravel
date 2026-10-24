<?php

namespace App\Http\Controllers\Api;

use App\Enums\GateId;
use App\Exceptions\ScanNotConfirmable;
use App\Http\Controllers\Controller;
use App\Http\Resources\GatePassResource;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile and scanner gate-pass endpoints.
 *
 * validateToken() and confirmScan() are what a handheld scanner calls. They
 * use the same GateScanner as the web scanner, so the two cannot drift apart:
 * the server decides CHECK_IN, CHECK_OUT or REJECT, and a confirmation names
 * only the scan it confirms.
 */
class GatePassApiController extends Controller
{
    public function __construct(
        private readonly GatePassEngine $engine,
        private readonly GateScanner $scanner,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $pass = $this->engine->issuePassFor($request->user());
        $category = $pass->category;

        return response()->json([
            'pass' => (new GatePassResource($pass))->resolve(),
            'visual' => [
                'config' => $this->engine->categoryConfig($category),
                'variant' => $this->engine->variantFor($pass),
                'shape' => $category->shape()->value,
            ],
            'policy' => $this->engine->policyFor($category),
        ]);
    }

    public function token(Request $request): JsonResponse
    {
        $pass = $this->engine->issuePassFor($request->user());

        if (! $pass->isActive()) {
            return response()->json([
                'message' => "Pass {$pass->pass_id} is {$pass->status->label()}. Contact estate security.",
                'status' => $pass->status->value,
            ], 423);
        }

        $issued = $this->engine->issueToken($pass);

        return response()->json([
            'token' => $issued['token'],
            'validFrom' => $issued['valid_from']->toIso8601String(),
            'validUntil' => $issued['valid_until']->toIso8601String(),
            'secondsRemaining' => max(0, $issued['valid_until']->getTimestamp() - now()->getTimestamp()),
        ]);
    }

    public function rotate(Request $request): JsonResponse
    {
        $pass = $this->engine->issuePassFor($request->user());
        $rotation = $this->engine->rotateVisualIdentity($pass);

        return response()->json([
            'rotationSeq' => $rotation['next_seq'],
            'variant' => $rotation['variant'],
            'rotatedAt' => $rotation['rotated_at'],
        ]);
    }

    public function validateToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'gate' => ['nullable', 'string', Rule::in(['GATE-01', 'GATE-02'])],
        ]);

        $gate = GateId::tryFrom($validated['gate'] ?? '') ?? GateId::Gate01;
        $result = $this->scanner->scan($validated['token'], $gate, $request->user(), 'Handheld Scanner');

        return response()->json([
            'allowed' => $result['decision'] !== 'REJECT',
            ...$result,
        ]);
    }

    public function confirmScan(Request $request, string $scan): JsonResponse
    {
        $validated = $request->validate([
            'accept' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->scanner->confirm($scan, $request->user(), $validated['accept'], $validated['reason'] ?? null);
        } catch (ScanNotConfirmable $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, ...$result]);
    }
}
