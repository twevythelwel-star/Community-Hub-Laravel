<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessLogEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessLogApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entries = AccessLogEntry::query()
            ->when($request->string('gate')->isNotEmpty(), fn ($q) => $q->where('gate', $request->string('gate')))
            ->when($request->string('result')->isNotEmpty(), fn ($q) => $q->where('result', $request->string('result')))
            ->when($request->date('from'), fn ($q, $from) => $q->where('occurred_at', '>=', $from))
            ->latest('occurred_at')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'entries' => $entries->through(fn (AccessLogEntry $e) => [
                'id' => $e->id,
                'userName' => $e->user_name,
                'userRole' => $e->user_role,
                'method' => $e->method,
                'gate' => $e->gate,
                'passId' => $e->pass_id,
                'result' => $e->result,
                'denyReason' => $e->deny_reason,
                'timestamp' => $e->occurred_at->toIso8601String(),
            ]),
        ]);
    }
}
