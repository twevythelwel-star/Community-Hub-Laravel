<?php

namespace App\Actions\GatePass;

use App\Models\User;
use App\Services\GateScanner;

class ConfirmScanAction
{
    public function __construct(
        protected GateScanner $scanner
    ) {}

    /**
     * Confirm a scanned gate decision.
     *
     * @return array<string, mixed>
     */
    public function execute(string $scanId, User $user, bool $accept, ?string $reason): array
    {
        return $this->scanner->confirm($scanId, $user, $accept, $reason);
    }
}
