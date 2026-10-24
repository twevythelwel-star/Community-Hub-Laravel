<?php

namespace App\Services\Payments\Drivers;

use Illuminate\Support\Str;

class PeerPaymentDriver implements PaymentDriverInterface
{
    public function __construct(
        protected string $rail = 'zelle'
    ) {}

    public function key(): string
    {
        return $this->rail;
    }

    public function label(): string
    {
        return match ($this->rail) {
            'cash_app' => 'Cash App',
            default => 'Zelle',
        };
    }

    public function initiate(array $params): array
    {
        $amountMinor = $params['amount_minor'] ?? 0;
        $currency = $params['currency'] ?? 'JMD';
        $memo = 'LOT-'.($params['lot'] ?? 'CYPRESS').'-'.strtoupper(Str::random(4));

        $deepLink = match ($this->rail) {
            'cash_app' => 'https://cash.app/$CypressBayHOA/'.($amountMinor / 100),
            default => 'mailto:payments@cypressbay.org?subject=Zelle%20Payment%20'.$memo,
        };

        return [
            'type' => $this->rail,
            'recipient' => match ($this->rail) {
                'cash_app' => '$CypressBayHOA',
                default => 'payments@cypressbay.org',
            },
            'memo' => $memo,
            'deep_link' => $deepLink,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'instructions' => 'Send payment to '.($this->rail === 'cash_app' ? '$CypressBayHOA' : 'payments@cypressbay.org').' with memo: '.$memo,
        ];
    }

    public function settle(array $params): array
    {
        return [
            'success' => true,
            'reference' => strtoupper($this->rail).'-'.strtoupper(Str::random(8)),
            'notes' => 'Settled via '.$this->label().' transfer verification.',
        ];
    }
}
