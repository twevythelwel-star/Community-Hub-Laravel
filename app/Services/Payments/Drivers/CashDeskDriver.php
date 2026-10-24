<?php

namespace App\Services\Payments\Drivers;

class CashDeskDriver implements PaymentDriverInterface
{
    public function key(): string
    {
        return 'cash_office';
    }

    public function label(): string
    {
        return 'Cash at Administration Desk';
    }

    public function initiate(array $params): array
    {
        $amountMinor = $params['amount_minor'] ?? 0;
        $currency = $params['currency'] ?? 'JMD';
        $year = date('Y');
        $cashReceiptNumber = sprintf('CR-%04d-%05d', (int) $year, mt_rand(1, 99999));
        $depositBatch = sprintf('DEP-%04d-%04d', (int) $year, mt_rand(1, 9999));
        $location = $params['location'] ?? 'Main Administration Office';

        return [
            'type' => 'cash_office',
            'cash_receipt_number' => $cashReceiptNumber,
            'deposit_batch' => $depositBatch,
            'location' => $location,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'instructions' => "Present this Cash Receipt slip ({$cashReceiptNumber}) at the {$location}. Official dual-staff stamped receipt will be issued.",
        ];
    }

    public function settle(array $params): array
    {
        $year = date('Y');
        $receipt = $params['cash_receipt_number'] ?? sprintf('CR-%04d-%05d', (int) $year, mt_rand(1, 99999));
        $depositBatch = $params['deposit_batch'] ?? sprintf('DEP-%04d-%04d', (int) $year, mt_rand(1, 9999));
        $staffCode = $params['staff_code'] ?? 'STAFF-029';
        $location = $params['location'] ?? 'Main Office';

        return [
            'success' => true,
            'reference' => $receipt,
            'deposit_batch' => $depositBatch,
            'collected_by' => $staffCode,
            'location' => $location,
            'verified' => true,
            'notes' => "Settled in physical cash at {$location}. Collected by {$staffCode}. Dual-signed and assigned to deposit batch {$depositBatch}.",
        ];
    }
}
