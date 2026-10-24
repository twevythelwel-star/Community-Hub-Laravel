<?php

namespace App\Actions\GatePass;

use App\Exceptions\InvalidPassTransition;
use App\Models\GatePass;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class RevokePassAction
{
    /**
     * Revoke an active gate pass.
     *
     * @throws InvalidPassTransition
     */
    public function execute(GatePass $gatePass, User $user, string $reason): void
    {
        $gatePass->revoke($user, $reason);

        Log::channel('security')->notice('Gate pass revoked', [
            'pass_id' => $gatePass->pass_id,
            'revoked_by' => $user->uid,
            'reason' => $reason,
        ]);
    }
}
