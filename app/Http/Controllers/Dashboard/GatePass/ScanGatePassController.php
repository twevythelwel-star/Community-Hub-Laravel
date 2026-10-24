<?php

namespace App\Http\Controllers\Dashboard\GatePass;

use App\Actions\GatePass\ScanGatePassAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\GatePass\ScanGatePassRequest;
use Illuminate\Http\JsonResponse;

/**
 * ScanGatePassController handles QR code scanning at estate gates.
 *
 * Architecture Flow:
 * ScanGatePassController
 *         ↓
 * ScanGatePassRequest
 *         ↓
 * ScanGatePassAction
 *         ↓
 * GatePassEngine / GateScanner
 *         ↓
 * GatePassRepository
 */
class ScanGatePassController extends Controller
{
    public function __invoke(ScanGatePassRequest $request, ScanGatePassAction $action): JsonResponse
    {
        $result = $action->execute(
            $request->validated('token'),
            $request->validated('gate'),
            $request->user(),
            $request->validated('direction') ?? 'in',
            $request->validated('method') ?? 'Digital Pass',
            (bool) $request->validated('override_passback', false),
            $request->validated('override_reason')
        );

        return response()->json($result);
    }
}
