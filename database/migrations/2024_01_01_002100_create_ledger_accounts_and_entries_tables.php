<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type'); // asset, liability, equity, revenue, expense
            $table->string('currency')->default('USD');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fundraiser_id')->nullable()->constrained('fundraisers')->nullOnDelete();
            $table->bigInteger('balance_minor')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('user_id');
            $table->index('fundraiser_id');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id')->unique(); // e.g. LED-2026-0000000001
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('entry_type'); // debit, credit
            $table->bigInteger('amount_minor');
            $table->string('currency')->default('USD');
            $table->string('description');
            $table->bigInteger('balance_after_minor')->default(0);
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('bank_reconciliation_id')->nullable()->constrained('bank_reconciliations')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('transaction_id');
            $table->index('account_id');
            $table->index('entry_type');
            $table->index('reconciled_at');
        });

        // Seed Core Chart of Accounts
        $now = now();
        $defaultAccounts = [
            ['code' => '1010', 'name' => 'Payment Clearing', 'type' => 'asset', 'currency' => 'USD', 'description' => 'In-transit payments clearing rail'],
            ['code' => '1020', 'name' => 'Stripe In-Transit Clearing', 'type' => 'asset', 'currency' => 'USD', 'description' => 'Card and wallet payments clearing through Stripe'],
            ['code' => '1030', 'name' => 'Bank Operating (NCB)', 'type' => 'asset', 'currency' => 'JMD', 'description' => 'Main community operating account with NCB'],
            ['code' => '1200', 'name' => 'Resident Accounts Receivable', 'type' => 'asset', 'currency' => 'USD', 'description' => 'Receivables from property owners for dues and assessments'],
            ['code' => '2010', 'name' => 'Resident Prepaid Dues & Wallet Liabilities', 'type' => 'liability', 'currency' => 'USD', 'description' => 'Resident prepaid credits and community wallet holdings'],
            ['code' => '4010', 'name' => 'Community Revenue - HOA Assessments', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Primary recurring HOA and assessment revenue'],
            ['code' => '4020', 'name' => 'Community Revenue - Maintenance Fees', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Special grounds and facility maintenance revenue'],
            ['code' => '4030', 'name' => 'Community Revenue - Late Fees', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Penalties assessed on delinquent accounts'],
            ['code' => '4040', 'name' => 'Community Revenue - Amenity Bookings', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Clubhouse and sports facility rental fees'],
            ['code' => '4050', 'name' => 'Community Revenue - Gate Access Fees', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Security barcode decals and gate passes'],
            ['code' => '4060', 'name' => 'Community Revenue - Event Tickets', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Community social and gathering events'],
            ['code' => '4070', 'name' => 'Fundraising Campaign Contributions', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Restricted donations and capital project fundraising'],
            ['code' => '4080', 'name' => 'Fundraising Sponsorships', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Corporate and resident project sponsorships'],
            ['code' => '4090', 'name' => 'Community Project Fund', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Targeted capital improvements fund'],
            ['code' => '4100', 'name' => 'Emergency Reserve Fund', 'type' => 'revenue', 'currency' => 'USD', 'description' => 'Contingency and storm emergency capital reserves'],
        ];

        foreach ($defaultAccounts as $acc) {
            DB::table('accounts')->insertOrIgnore([
                ...$acc,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('accounts');
    }
};
