<?php

namespace Database\Seeders;

use App\Models\AutoPaySetting;
use App\Models\BankReconciliation;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\PaymentChannelSetting;
use App\Models\PaymentLink;
use App\Models\Payout;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RevenueEngineSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Payment Channel Settings
        $channels = PaymentChannelSetting::defaultChannels();
        foreach ($channels as $channel) {
            PaymentChannelSetting::updateOrCreate(
                ['channel_key' => $channel['channel_key']],
                $channel
            );
        }

        // 2. Wallets for Users
        $homeowner = User::where('email', 'marcus.vance@residence.net')->first()
            ?? User::where('role', 'Homeowner')->first();

        if ($homeowner) {
            $wallet = Wallet::updateOrCreate(
                ['user_id' => $homeowner->id],
                [
                    'available_balance_minor' => 35000, // $350.00 JMD
                    'pending_balance_minor' => 7500,   // $75.00 JMD
                    'rewards_balance_minor' => 2500,   // $25.00 JMD
                    'currency' => 'JMD',
                    'auto_reload_enabled' => true,
                    'auto_reload_threshold_minor' => 10000, // reload when below $100
                    'auto_reload_amount_minor' => 25000,    // top up $250
                ]
            );

            // Wallet transaction history
            $wallet->transactions()->delete();
            $wallet->transactions()->createMany([
                [
                    'amount_minor' => 50000,
                    'currency' => 'JMD',
                    'balance_type' => 'available',
                    'type' => 'debit',
                    'reference' => 'WAL-DB-001',
                    'description' => 'Applied to August Maintenance Dues',
                    'created_at' => now()->subDays(20),
                ],
                [
                    'amount_minor' => 2500,
                    'currency' => 'JMD',
                    'balance_type' => 'rewards',
                    'type' => 'credit',
                    'reference' => 'WAL-CR-002',
                    'description' => 'Early Bird Payment Incentive Credit',
                    'created_at' => now()->subDays(15),
                ],
                [
                    'amount_minor' => 7500,
                    'currency' => 'JMD',
                    'balance_type' => 'pending',
                    'type' => 'credit',
                    'reference' => 'WAL-CR-003',
                    'description' => 'Bank Wire Deposit (Pending Verification)',
                    'created_at' => now()->subDays(2),
                ],
            ]);

            // AutoPay setting
            AutoPaySetting::updateOrCreate(
                ['user_id' => $homeowner->id],
                [
                    'is_active' => true,
                    'cadence' => 'monthly',
                    'charge_day_of_month' => 1,
                    'payment_channel' => 'card',
                    'last_run_at' => now()->subMonth()->startOfMonth(),
                    'next_run_at' => now()->startOfMonth()->addMonth(),
                ]
            );
        }

        // 3. Itemized Charges on Homeowner Invoices
        if ($homeowner) {
            $unpaidInvoice = $homeowner->invoices()->where('status', '!=', 'Paid')->first();
            if (! $unpaidInvoice) {
                $unpaidInvoice = Invoice::create([
                    'user_id' => $homeowner->id,
                    'reference' => 'INV-'.strtoupper(Str::random(8)),
                    'amount_minor' => 12500000, // $125,000.00 JMD
                    'currency' => 'JMD',
                    'period_start' => now()->startOfMonth(),
                    'period_end' => now()->endOfMonth(),
                    'due_on' => now()->addDays(10),
                    'status' => 'Unpaid',
                ]);
            } else {
                $unpaidInvoice->update(['amount_minor' => 12500000]);
            }

            $unpaidInvoice->items()->delete();
            $unpaidInvoice->items()->createMany([
                ['category' => 'hoa_dues', 'title' => 'HOA / Monthly Maintenance Fee', 'amount_minor' => 5000000, 'status' => 'Unpaid'],
                ['category' => 'special_assessment', 'title' => 'Special Assessment (Road Resurfacing Phase 1)', 'amount_minor' => 4500000, 'status' => 'Unpaid'],
                ['category' => 'community_project', 'title' => 'Community Project (Solar Gate Lights)', 'amount_minor' => 1500000, 'status' => 'Unpaid'],
                ['category' => 'late_fee', 'title' => 'Late Fee (September Overdue)', 'amount_minor' => 500000, 'status' => 'Unpaid'],
                ['category' => 'fundraising', 'title' => 'Youth Sports & Community Fund Contribution', 'amount_minor' => 1000000, 'status' => 'Unpaid'],
            ]);
        }

        // 4. Universal Payment Links
        PaymentLink::updateOrCreate(
            ['token' => 'csu-2026-9f8a'],
            [
                'title' => '$150 — Community Security Upgrade',
                'description' => 'Voluntary contribution for perimeter optical sensor installation at the north wall.',
                'amount_minor' => 15000, // $150.00 JMD
                'currency' => 'JMD',
                'category' => 'Security Assessment',
                'active' => true,
                'uses_count' => 14,
            ]
        );

        PaymentLink::updateOrCreate(
            ['token' => 'us-overseas-dues-2026'],
            [
                'title' => '$100 USD — International Homeowner Dues',
                'description' => 'Direct settlement portal for North American & overseas property owners.',
                'amount_minor' => 10000, // $100.00 USD
                'currency' => 'USD',
                'category' => 'Overseas Assessment',
                'active' => true,
                'uses_count' => 6,
            ]
        );

        // 5. First-Class Community Playground Project
        $playground = Fundraiser::updateOrCreate(
            ['title' => 'Community Playground Project'],
            [
                'description' => 'Building a modern, safe playground with shade sails and recycled rubber surface for community children.',
                'beneficiary' => 'Cypress Bay Youth & Families Fund',
                'goal_minor' => 6000000, // $60,000.00 JMD
                'goal_currency' => 'JMD',
                'start_date' => now()->subDays(16)->toDateString(),
                'end_date' => now()->addDays(14)->toDateString(),
                'status' => 'Active',
                'allow_anonymous' => true,
                'allow_recurring' => true,
                'suggested_amounts' => [1000, 2500, 5000, 10000],
                'matching_sponsor' => 'NCB Foundation (1:1 Match)',
                'matching_multiplier' => 1,
                'max_matching_minor' => 2000000,
                'fund_allocation' => [
                    'Equipment & Structures' => 60,
                    'Site Prep & Safety Turf' => 30,
                    'Contingency & Landscaping' => 10,
                ],
                'show_leaderboard' => true,
            ]
        );

        $playground->donations()->delete();
        $playground->donations()->createMany([
            [
                'user_id' => $homeowner?->id,
                'donor_name' => $homeowner?->name ?? 'Marcus Vance',
                'amount_minor' => 1000000, // $10,000
                'currency' => 'JMD',
                'is_anonymous' => false,
                'is_recurring' => false,
                'tax_deductible' => true,
                'receipt_number' => 'DON-REC-2026-001',
                'donated_at' => now()->subDays(10),
            ],
            [
                'donor_name' => 'Digicel Business (Corporate Sponsor)',
                'amount_minor' => 1500000, // $15,000
                'currency' => 'JMD',
                'is_anonymous' => false,
                'is_recurring' => false,
                'tax_deductible' => true,
                'receipt_number' => 'DON-REC-2026-002',
                'donated_at' => now()->subDays(7),
            ],
            [
                'donor_name' => 'Sophia Taylor',
                'amount_minor' => 250000, // $2,500
                'currency' => 'JMD',
                'is_anonymous' => false,
                'is_recurring' => true,
                'frequency' => 'monthly',
                'tax_deductible' => true,
                'receipt_number' => 'DON-REC-2026-003',
                'donated_at' => now()->subDays(4),
            ],
            [
                'donor_name' => 'Anonymous Resident',
                'amount_minor' => 500000, // $5,000
                'currency' => 'JMD',
                'is_anonymous' => true,
                'is_recurring' => false,
                'tax_deductible' => true,
                'receipt_number' => 'DON-REC-2026-004',
                'donated_at' => now()->subDays(2),
            ],
            [
                'donor_name' => 'General Supporters (Batch)',
                'amount_minor' => 1000000, // $10,000
                'currency' => 'JMD',
                'is_anonymous' => true,
                'is_recurring' => false,
                'tax_deductible' => true,
                'receipt_number' => 'DON-REC-2026-005',
                'donated_at' => now()->subDay(),
            ],
        ]);

        $playground->updates()->delete();
        $playground->updates()->createMany([
            [
                'title' => 'Groundwork & Soil Testing Completed',
                'content' => 'Engineers completed soil stability tests on the playground parcel. Drainage grading scheduled for Tuesday.',
                'created_at' => now()->subDays(5),
            ],
            [
                'title' => 'Equipment Delivered to Main Clubhouse',
                'content' => 'The swing sets and climbing dome have arrived safely from the distributor. Volunteer assembly date to be announced!',
                'created_at' => now()->subDays(1),
            ],
        ]);

        // 6. Master Transactions for Admin Financial Dashboard
        Transaction::query()->delete();
        $channelsBreakdown = [
            ['channel' => 'stripe_card',   'amount' => 701100,  'currency' => 'JMD', 'notes' => 'Resident Dues online payment'],
            ['channel' => 'bank_wire',     'amount' => 332100,  'currency' => 'JMD', 'notes' => 'Direct Wire from Lot 12'],
            ['channel' => 'apple_pay',     'amount' => 313650,  'currency' => 'JMD', 'notes' => '1-tap Apple Pay from mobile'],
            ['channel' => 'google_pay',    'amount' => 221400,  'currency' => 'JMD', 'notes' => 'Google Pay dues checkout'],
            ['channel' => 'nfc_pos',       'amount' => 110700,  'currency' => 'JMD', 'notes' => 'Clubhouse NFC kiosk tap'],
            ['channel' => 'zelle',         'amount' => 166050,  'currency' => 'JMD', 'notes' => 'Zelle payment memo LOT-42'],

            // Multi-Currency International Collections
            ['channel' => 'stripe_card',   'amount' => 150000,  'currency' => 'USD', 'notes' => 'Overseas Homeowner Dues ($1,500.00 USD)'],
            ['channel' => 'apple_pay',     'amount' => 95000,   'currency' => 'USD', 'notes' => 'US Resident Assessment ($950.00 USD)'],
            ['channel' => 'bank_wire',     'amount' => 120000,  'currency' => 'CAD', 'notes' => 'Canadian Wire Transfer (CA$1,200.00 CAD)'],
            ['channel' => 'google_pay',    'amount' => 85000,   'currency' => 'GBP', 'notes' => 'UK Resident Contribution (£850.00 GBP)'],
            ['channel' => 'stripe_card',   'amount' => 92000,   'currency' => 'EUR', 'notes' => 'European Member Annual Fee (€920.00 EUR)'],
        ];

        foreach ($channelsBreakdown as $entry) {
            Transaction::create([
                'user_id' => $homeowner?->id,
                'amount_minor' => $entry['amount'],
                'currency' => $entry['currency'],
                'payment_channel' => $entry['channel'],
                'reference' => 'TX-'.strtoupper(Str::random(10)),
                'status' => 'completed',
                'notes' => $entry['notes'],
                'created_at' => now(),
            ]);
        }

        // Add 1 refund record ($1,150.00 JMD)
        Transaction::create([
            'user_id' => $homeowner?->id,
            'amount_minor' => 115000,
            'currency' => 'JMD',
            'payment_channel' => 'stripe_card',
            'reference' => 'TX-REFUND-001',
            'status' => 'refunded',
            'notes' => 'Duplicate clubhouse booking reversal',
            'created_at' => now()->subHours(3),
        ]);

        // 7. Payouts
        Payout::query()->delete();
        Payout::create([
            'vendor_name' => 'Apex Security Systems Ltd',
            'category' => 'Security',
            'amount_minor' => 850000, // $8,500.00
            'currency' => 'JMD',
            'status' => 'Processed',
            'scheduled_for' => now()->subDays(3)->toDateString(),
            'processed_at' => now()->subDays(2),
        ]);
        Payout::create([
            'vendor_name' => 'GreenThumb Island Landscaping',
            'category' => 'Landscaping',
            'amount_minor' => 320000, // $3,200.00
            'currency' => 'JMD',
            'status' => 'Scheduled',
            'scheduled_for' => now()->addDays(5)->toDateString(),
        ]);
        Payout::create([
            'vendor_name' => 'Kingston Solar & Electrical',
            'category' => 'Maintenance',
            'amount_minor' => 400000, // $4,000.00
            'currency' => 'JMD',
            'status' => 'Scheduled',
            'scheduled_for' => now()->addDays(12)->toDateString(),
        ]);

        // 8. Bank Reconciliation
        BankReconciliation::query()->delete();
        BankReconciliation::create([
            'bank_statement_date' => now()->subDays(5)->toDateString(),
            'statement_balance_minor' => 15240000, // $152,400.00
            'ledger_balance_minor' => 15240000,
            'difference_minor' => 0,
            'status' => 'Reconciled',
            'notes' => 'Audited monthly reconciliation. All bank credits match ledger invoices.',
        ]);
    }
}
