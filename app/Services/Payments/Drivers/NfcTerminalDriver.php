<?php

namespace App\Services\Payments\Drivers;

use Illuminate\Support\Str;

/**
 * NFC Contactless Payment POS Driver.
 *
 * NOTE: Strictly separated from GatePassEngine (perimeter physical access).
 * This driver operates within the EMV / POS Financial Acceptance domain.
 */
class NfcTerminalDriver implements PaymentDriverInterface
{
    public function key(): string
    {
        return 'nfc_pos';
    }

    public function label(): string
    {
        return 'Tap to Pay / Contactless';
    }

    public function initiate(array $params): array
    {
        $amountMinor = $params['amount_minor'] ?? 0;
        $currency = $params['currency'] ?? 'JMD';
        $terminalSessionId = 'term_'.Str::random(20);

        return [
            'type' => 'nfc_pos',
            'terminal_session_id' => $terminalSessionId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'terminal_id' => 'POS-GATEHOUSE-01',
            'nonce' => bin2hex(random_bytes(16)),
            'instructions' => 'Hold your physical card near the contactless card-present payment reader terminal.',
        ];
    }

    public function settle(array $params): array
    {
        return [
            'success' => true,
            'reference' => 'NFC-POS-'.strtoupper(Str::random(10)),
            'notes' => 'Settled via Contactless NFC POS reader.',
        ];
    }
}
