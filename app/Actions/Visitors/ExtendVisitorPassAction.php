<?php

namespace App\Actions\Visitors;

use App\Enums\PassStatus;
use App\Enums\VisitorStatus;
use App\Models\Visitor;

class ExtendVisitorPassAction
{
    public function execute(Visitor $visitor, int $hours): void
    {
        $base = $visitor->expected_at && $visitor->expected_at->isFuture()
            ? $visitor->expected_at
            : now();

        $newExpectedAt = $base->copy()->addHours($hours);

        $visitor->update([
            'expected_at' => $newExpectedAt,
            'expired_at' => null,
            'status' => $visitor->status === VisitorStatus::CheckedIn ? VisitorStatus::CheckedIn : VisitorStatus::Expected,
        ]);

        if ($visitor->gatePass) {
            $passBase = $visitor->gatePass->valid_until && $visitor->gatePass->valid_until->isFuture()
                ? $visitor->gatePass->valid_until
                : now();

            $visitor->gatePass->update([
                'valid_until' => $passBase->copy()->addHours($hours),
                'status' => $visitor->gatePass->status === PassStatus::Expired ? PassStatus::Active : $visitor->gatePass->status,
            ]);
        }
    }
}
