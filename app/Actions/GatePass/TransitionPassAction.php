<?php

namespace App\Actions\GatePass;

use App\Enums\PassStatus;
use App\Exceptions\InvalidPassTransition;
use App\Models\GatePass;
use App\Models\User;
use App\Services\GatePassEngine;
use Illuminate\Support\Facades\Log;

class TransitionPassAction
{
    public function __construct(
        protected GatePassEngine $engine
    ) {}

    /**
     * Transition a gate pass through manual operator status changes.
     *
     * @throws InvalidPassTransition
     */
    public function execute(GatePass $gatePass, PassStatus $target, User $user, ?string $reason): void
    {
        $isHostCancelling = $target === PassStatus::Cancelled
            && $gatePass->visitor?->homeowner_id === $user->id;

        abort_unless($user->can('manageSecurity') || $isHostCancelling, 403);

        $target === PassStatus::Approved
            ? $this->engine->approve($gatePass, $user, $reason)
            : $gatePass->transitionTo($target, $user, $reason);

        // A contractor's pass is only sent once it is approved.
        $visitor = $gatePass->visitor;
        if ($target === PassStatus::Approved && $visitor && ($channels = $visitor->passNotificationChannels()) !== []) {
            $visitor->sendPassNotification($channels);
        }

        Log::channel('security')->notice('Gate pass status changed', [
            'pass_id' => $gatePass->pass_id,
            'status' => $gatePass->status->value,
            'by' => $user->uid,
            'reason' => $reason,
        ]);
    }
}
