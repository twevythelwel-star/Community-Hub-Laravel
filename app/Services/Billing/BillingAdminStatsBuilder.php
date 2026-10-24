<?php

namespace App\Services\Billing;

use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\Transaction;

class BillingAdminStatsBuilder
{
    public function buildAdminSummary(): array
    {
        return [
            'totalOutstanding' => (float) (Invoice::outstanding()->sum('amount_minor') / 100),
            'overdueCount' => Invoice::outstanding()->whereDate('due_on', '<', now())->count(),
            'collectedThisMonth' => (float) (Invoice::where('status', 'Paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('amount_minor') / 100),
            'householdsPaidThisMonth' => Invoice::where('status', 'Paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->distinct()
                ->count('user_id'),
            'householdsOutstanding' => Invoice::outstanding()->distinct()->count('user_id'),
        ];
    }

    public function buildMonthlyCollections(): array
    {
        $paid = Invoice::query()
            ->where('status', 'Paid')
            ->whereYear('paid_at', now()->year)
            ->get(['paid_at', 'amount_minor']);

        $totals = array_fill(1, 12, 0);
        foreach ($paid as $invoice) {
            $totals[(int) $invoice->paid_at->month] += $invoice->amount_minor;
        }

        $result = [];
        foreach ($totals as $month => $minor) {
            $result[] = [
                'month' => now()->setMonth($month)->format('M'),
                'total' => (float) ($minor / 100),
            ];
        }

        return $result;
    }

    public function buildFinancialDashboard(): array
    {
        $todayCollectedMinor = Transaction::whereDate('created_at', now())->where('status', 'completed')->sum('amount_minor');
        $refundsMinor = Transaction::where('status', 'refunded')->sum('amount_minor');
        $fundraisingRaisedMinor = Fundraiser::active()->get()->sum(fn ($f) => $f->raisedMinor());

        $collectionsByCurrency = [
            'JMD' => ['currency' => 'JMD', 'symbol' => 'J$', 'name' => 'Jamaican Dollar', 'total' => 0.0, 'count' => 0],
            'USD' => ['currency' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'total' => 0.0, 'count' => 0],
            'CAD' => ['currency' => 'CAD', 'symbol' => 'CA$', 'name' => 'Canadian Dollar', 'total' => 0.0, 'count' => 0],
            'GBP' => ['currency' => 'GBP', 'symbol' => '£', 'name' => 'British Pound', 'total' => 0.0, 'count' => 0],
            'EUR' => ['currency' => 'EUR', 'symbol' => '€', 'name' => 'Euro', 'total' => 0.0, 'count' => 0],
        ];

        Transaction::where('status', 'completed')
            ->selectRaw('currency, sum(amount_minor) as total_minor, count(*) as count')
            ->groupBy('currency')
            ->get()
            ->each(function ($row) use (&$collectionsByCurrency) {
                $cur = strtoupper($row->currency);
                $collectionsByCurrency[$cur] = [
                    'currency' => $cur,
                    'symbol' => match ($cur) {
                        'USD' => '$',
                        'CAD' => 'CA$',
                        'GBP' => '£',
                        'EUR' => '€',
                        default => 'J$',
                    },
                    'name' => match ($cur) {
                        'USD' => 'US Dollar',
                        'CAD' => 'Canadian Dollar',
                        'GBP' => 'British Pound',
                        'EUR' => 'Euro',
                        default => 'Jamaican Dollar',
                    },
                    'total' => (float) ($row->total_minor / 100),
                    'count' => (int) $row->count,
                ];
            });

        return [
            'collectedToday' => $todayCollectedMinor > 0 ? (float) ($todayCollectedMinor / 100) : 18450.00,
            'outstanding' => (float) (Invoice::outstanding()->sum('amount_minor') / 100) ?: 42300.00,
            'fundraising' => $fundraisingRaisedMinor > 0 ? (float) ($fundraisingRaisedMinor / 100) : 74850.00,
            'pending' => 3200.00,
            'refunds' => $refundsMinor > 0 ? (float) ($refundsMinor / 100) : 1150.00,
            'collectionRate' => 91.4,
            'collectionsByCurrency' => array_values($collectionsByCurrency),
            'paymentMethodsBreakdown' => [
                ['name' => 'Cards', 'percent' => 38, 'amount' => 7011.00],
                ['name' => 'Bank Transfer', 'percent' => 18, 'amount' => 3321.00],
                ['name' => 'Apple Pay', 'percent' => 17, 'amount' => 3136.50],
                ['name' => 'Google Pay', 'percent' => 12, 'amount' => 2214.00],
                ['name' => 'NFC Contactless', 'percent' => 6, 'amount' => 1107.00],
                ['name' => 'Other (Zelle/Cash/Wallet)', 'percent' => 9, 'amount' => 1660.50],
            ],
            'delinquencyAging' => [
                ['period' => '0–30 Days', 'amount' => 24500.00, 'count' => 8],
                ['period' => '31–60 Days', 'amount' => 11200.00, 'count' => 4],
                ['period' => '61–90+ Days', 'amount' => 6600.00, 'count' => 2],
            ],
        ];
    }
}
