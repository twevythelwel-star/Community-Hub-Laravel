<?php

namespace App\Services\Payments\Drivers;

use Illuminate\Support\Str;

class BankTransferDriver implements PaymentDriverInterface
{
    public function key(): string
    {
        return 'bank_wire';
    }

    public function label(): string
    {
        return 'Direct Bank Transfer / ACH';
    }

    public function initiate(array $params): array
    {
        $amountMinor = $params['amount_minor'] ?? 0;
        $currency = $params['currency'] ?? 'JMD';
        $invNum = isset($params['invoice_id']) ? (int) $params['invoice_id'] : 4821;
        $reference = sprintf('CH-INV-%06d-%04d', $invNum, mt_rand(1000, 9999));

        return [
            'type' => 'bank_wire',
            'bank_name' => 'National Commercial Bank (NCB)',
            'account_name' => 'Cypress Bay Community HOA Ltd.',
            'account_number' => '102938475',
            'branch' => 'Kingston 001',
            'wire_reference' => $reference,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'instructions' => 'Include reference '.$reference.' in your bank transfer description. Upload proof receipt to clear pending status.',
        ];
    }

    public function settle(array $params): array
    {
        return [
            'success' => true,
            'reference' => $params['wire_reference'] ?? ('WIRE-'.strtoupper(Str::random(8))),
            'notes' => 'Settled via Direct Bank Wire transfer.',
        ];
    }
}
