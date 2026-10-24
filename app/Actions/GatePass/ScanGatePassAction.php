<?php

namespace App\Actions\GatePass;

use App\Enums\GateId;
use App\Models\User;
use App\Services\GateScanner;

class ScanGatePassAction
{
    public function __construct(
        protected GateScanner $scanner
    ) {}

    /**
     * Scan and evaluate gate pass QR token.
     *
     * @return array<string, mixed>
     */
    public function execute(
        string $token,
        ?string $gateStr,
        User $user,
        string $direction = 'in',
        string $method = 'Digital Pass',
        bool $overridePassback = false,
        ?string $overrideReason = null
    ): array {
        $gate = GateId::tryFrom($gateStr ?? '') ?? GateId::Gate01;

        return $this->scanner->scan($token, $gate, $user, $method, $direction, $overridePassback, $overrideReason);
    }
}
