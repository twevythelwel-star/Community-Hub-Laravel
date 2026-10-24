<?php

namespace Database\Seeders;

use App\Models\BillingSetting;
use App\Models\Business;
use App\Models\FoodApp;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Deals, fundraising and billing. The monthly fee reproduces the JMD 5,000
 * default held in BillingProvider state in src/context/billing-context.tsx.
 */
class CommerceSeeder extends Seeder
{
    public function run(): void
    {
        BillingSetting::updateOrCreate([], [
            'monthly_fee_minor' => 500000,   // JMD 5,000.00
            'currency' => 'JMD',
            'due_day_of_month' => 1,
        ]);

        $businesses = [
            ['name' => 'Bayview Pharmacy',    'logo_url' => 'https://picsum.photos/120?q=pharmacy', 'ai_hint' => 'pharmacy storefront'],
            ['name' => 'Palm Grove Grocers',  'logo_url' => 'https://picsum.photos/120?q=grocer',   'ai_hint' => 'grocery store'],
            ['name' => 'Coral Auto Care',     'logo_url' => 'https://picsum.photos/120?q=auto',     'ai_hint' => 'car service garage'],
            ['name' => 'Hibiscus Hair Studio', 'logo_url' => 'https://picsum.photos/120?q=salon',    'ai_hint' => 'hair salon interior'],
        ];

        $vouchers = [
            'Bayview Pharmacy' => ['title' => '10% off prescriptions',     'description' => 'Show your resident pass at the counter for 10% off all prescription collections.'],
            'Palm Grove Grocers' => ['title' => 'Free delivery over $5,000', 'description' => 'Complimentary delivery within the estate on grocery orders over JMD 5,000.'],
            'Coral Auto Care' => ['title' => '15% off servicing',         'description' => 'Residents receive 15% off standard vehicle servicing, booking required.'],
            'Hibiscus Hair Studio' => ['title' => 'Resident Tuesday',          'description' => '20% off all services every Tuesday for verified residents.'],
        ];

        foreach ($businesses as $data) {
            $business = Business::updateOrCreate(['name' => $data['name']], [...$data, 'active' => true]);

            if (isset($vouchers[$data['name']])) {
                $business->vouchers()->updateOrCreate(
                    ['title' => $vouchers[$data['name']]['title']],
                    $vouchers[$data['name']],
                );
            }
        }

        $foodApps = [
            ['name' => 'QuickEats',    'website_url' => 'https://example.com/quickeats',    'logo_url' => 'https://picsum.photos/120?q=food1', 'ai_hint' => 'food delivery app', 'coupon_percentage' => 15],
            ['name' => 'IslandDine',   'website_url' => 'https://example.com/islanddine',   'logo_url' => 'https://picsum.photos/120?q=food2', 'ai_hint' => 'caribbean cuisine',  'coupon_percentage' => 10],
            ['name' => 'FreshBasket',  'website_url' => 'https://example.com/freshbasket',  'logo_url' => 'https://picsum.photos/120?q=food3', 'ai_hint' => 'fresh produce box',  'coupon_percentage' => null],
        ];

        foreach ($foodApps as $app) {
            FoodApp::updateOrCreate(['name' => $app['name']], $app);
        }

        $admin = User::where('role', 'Admin')->first();

        $fundraisers = [
            [
                'title' => 'Clubhouse Roof Repair Fund',
                'description' => 'Raising funds to replace the clubhouse roof ahead of the next storm season. Any contribution helps.',
                'goal_minor' => 150000000,      // JMD 1,500,000.00
                'start_date' => now()->subDays(20)->toDateString(),
                'end_date' => now()->addDays(40)->toDateString(),
                'status' => 'Active',
            ],
            [
                'title' => 'Playground Shade Structures',
                'description' => 'Shade sails over the playground so children can play comfortably through the midday heat.',
                'goal_minor' => 45000000,       // JMD 450,000.00
                'start_date' => now()->addDays(10)->toDateString(),
                'end_date' => now()->addDays(70)->toDateString(),
                'status' => 'Upcoming',
            ],
            [
                'title' => 'Security Camera Upgrade',
                'description' => 'Completed drive to upgrade perimeter cameras to night-capable units.',
                'goal_minor' => 80000000,
                'start_date' => now()->subDays(120)->toDateString(),
                'end_date' => now()->subDays(30)->toDateString(),
                'status' => 'Completed',
            ],
        ];

        $residents = User::whereIn('role', ['Homeowner', 'Temporary Homeowner'])->get();

        foreach ($fundraisers as $data) {
            $fundraiser = Fundraiser::updateOrCreate(
                ['title' => $data['title']],
                [...$data, 'goal_currency' => 'JMD', 'created_by' => $admin?->id],
            );

            if ($fundraiser->donations()->exists() || $residents->isEmpty()) {
                continue;
            }

            // Seed a plausible spread of donations for the non-upcoming drives.
            if ($data['status'] === 'Upcoming') {
                continue;
            }

            $donationCount = $data['status'] === 'Completed' ? 14 : 9;

            for ($i = 0; $i < $donationCount; $i++) {
                $donor = $residents->random();
                $anonymous = $i % 4 === 0;

                $fundraiser->donations()->create([
                    'user_id' => $donor->id,
                    'amount_minor' => random_int(20, 400) * 10000,   // JMD 200 - 4,000
                    'currency' => 'JMD',
                    'donor_name' => $anonymous ? null : $donor->display_name,
                    'is_anonymous' => $anonymous,
                    'donated_at' => now()->subDays(random_int(1, 25)),
                ]);
            }
        }

        // Three months of invoices per resident, most recent unpaid.
        $fee = BillingSetting::current();

        foreach ($residents as $resident) {
            for ($monthsAgo = 2; $monthsAgo >= 0; $monthsAgo--) {
                $periodStart = now()->subMonths($monthsAgo)->startOfMonth();

                Invoice::updateOrCreate(
                    [
                        'user_id' => $resident->id,
                        'reference' => sprintf('INV-%s-%04d', $periodStart->format('Ym'), $resident->id),
                    ],
                    [
                        'amount_minor' => $fee->monthly_fee_minor,
                        'currency' => $fee->currency,
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
                        'due_on' => $periodStart->copy()->day($fee->due_day_of_month)->toDateString(),
                        'status' => $monthsAgo === 0 ? 'Unpaid' : 'Paid',
                        'paid_at' => $monthsAgo === 0 ? null : $periodStart->copy()->addDays(3),
                    ],
                );
            }
        }
    }
}
