<?php

namespace App\Services\Payments\Drivers;

use App\Models\User;

class CommunityWalletDriver implements PaymentDriverInterface
{
    public function key(): string
    {
        return 'wallet';
    }

    public function label(): string
    {
        return 'Community Digital Wallet';
    }

    public function initiate(array $params): array
    {
        $amountMinor = $params['amount_minor'] ?? 0;
        $currency = $params['currency'] ?? 'JMD';
        $user = isset($params['user_id']) ? User::find($params['user_id']) : null;
        $wallet = $user?->wallet;

        $usable = $wallet ? $wallet->totalUsableMinor() : 0;
        $hasSufficient = $usable >= $amountMinor;

        return [
            'type' => 'wallet',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'wallet_balance_minor' => $usable,
            'sufficient_funds' => $hasSufficient,
            'instructions' => $hasSufficient
                ? 'Deduct directly from your available Community Wallet credits.'
                : 'Insufficient wallet balance. Top up your wallet or use another payment method.',
        ];
    }

    public function settle(array $params): array
    {
        $amountMinor = $params['amount_minor'] ?? 0;
        $user = isset($params['user_id']) ? User::find($params['user_id']) : null;
        $wallet = $user?->wallet;

        if (! $wallet || $wallet->totalUsableMinor() < $amountMinor) {
            return [
                'success' => false,
                'reference' => '',
                'notes' => 'Insufficient wallet funds.',
            ];
        }

        $walletTx = $wallet->debit($amountMinor, $params['description'] ?? 'Payment for community dues');

        return [
            'success' => true,
            'reference' => $walletTx ? $walletTx->reference : 'WAL-DEBIT',
            'notes' => 'Settled via Community Wallet balance deduction.',
        ];
    }
}
