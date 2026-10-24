<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_channel_settings', function (Blueprint $table) {
            $table->id();
            $table->string('channel_key')->unique(); // card, apple_pay, google_pay, samsung_wallet, bank_wire, zelle, cash_app, qr_code, nfc_pos, cash_office, wallet
            $table->boolean('enabled')->default(true);
            $table->string('display_label');
            $table->text('instructions')->nullable();
            $table->string('account_identifier')->nullable(); // e.g. CashApp $tag, Zelle email/phone, Bank Account #
            $table->decimal('fee_surcharge_percent', 5, 2)->default(0.00);
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('available_balance_minor')->default(0);
            $table->unsignedBigInteger('pending_balance_minor')->default(0);
            $table->unsignedBigInteger('rewards_balance_minor')->default(0);
            $table->string('currency', 3)->default('JMD');
            $table->boolean('auto_reload_enabled')->default(false);
            $table->unsignedBigInteger('auto_reload_threshold_minor')->default(0);
            $table->unsignedBigInteger('auto_reload_amount_minor')->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('JMD');
            $table->string('balance_type')->default('available'); // available, pending, rewards
            $table->string('type'); // credit, debit
            $table->string('reference')->unique();
            $table->string('description');
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable(); // null means payer chooses amount
            $table->string('currency', 3)->default('JMD');
            $table->string('category')->default('General');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // hoa_dues, special_assessment, late_fee, community_project, fundraising
            $table->string('title');
            $table->unsignedBigInteger('amount_minor');
            $table->string('status')->default('Unpaid'); // Unpaid, Paid
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
        });

        Schema::table('fundraisers', function (Blueprint $table) {
            $table->string('beneficiary')->nullable()->after('description');
            $table->string('cover_image_url')->nullable()->after('beneficiary');
            $table->boolean('allow_anonymous')->default(true)->after('cover_image_url');
            $table->boolean('allow_recurring')->default(true)->after('allow_anonymous');
            $table->json('suggested_amounts')->nullable()->after('allow_recurring');
            $table->string('matching_sponsor')->nullable()->after('suggested_amounts');
            $table->unsignedTinyInteger('matching_multiplier')->default(1)->after('matching_sponsor');
            $table->unsignedBigInteger('max_matching_minor')->default(0)->after('matching_multiplier');
            $table->json('fund_allocation')->nullable()->after('max_matching_minor');
            $table->boolean('show_leaderboard')->default(true)->after('fund_allocation');
        });

        Schema::create('fundraiser_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fundraiser_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('image_url')->nullable();
            $table->timestamps();

            $table->index(['fundraiser_id', 'created_at']);
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('is_anonymous');
            $table->string('frequency')->nullable()->after('is_recurring'); // monthly, quarterly, annual
            // Defaults to false. Deductibility depends on the recipient's
            // charitable registration, which this application does not hold or
            // check, so it must not be assumed true for every donation.
            $table->boolean('tax_deductible')->default(false)->after('frequency');
            $table->string('receipt_number')->nullable()->after('tax_deductible');
            $table->string('payment_channel')->nullable()->after('receipt_number');
        });

        Schema::create('autopay_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->string('cadence')->default('monthly'); // monthly, quarterly, annual, custom
            $table->unsignedTinyInteger('charge_day_of_month')->default(1);
            $table->string('payment_channel')->default('card');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('total_installments')->default(3);
            $table->string('frequency')->default('monthly');
            $table->unsignedBigInteger('installment_amount_minor');
            $table->unsignedTinyInteger('remaining_installments');
            $table->string('status')->default('Active'); // Active, Completed, Defaulted
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('invoice_item_id')->nullable();
            $table->foreignId('payment_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fundraiser_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('JMD');
            $table->string('payment_channel'); // stripe_card, apple_pay, google_pay, samsung_wallet, bank_wire, zelle, cash_app, qr_code, nfc_pos, cash_office, wallet
            $table->string('reference')->unique();
            $table->string('status')->default('completed'); // completed, pending, failed, refunded
            $table->string('receipt_number')->unique();
            $table->string('proof_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('payment_channel');
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('vendor_name');
            $table->string('category'); // Security, Landscaping, Utilities, Maintenance, General
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('JMD');
            $table->string('status')->default('Scheduled'); // Scheduled, Processed, Canceled
            $table->date('scheduled_for');
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->date('bank_statement_date');
            $table->bigInteger('statement_balance_minor');
            $table->bigInteger('ledger_balance_minor');
            $table->bigInteger('difference_minor');
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('Draft'); // Draft, Reconciled
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_plans');
        Schema::dropIfExists('autopay_settings');

        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn(['is_recurring', 'frequency', 'tax_deductible', 'receipt_number', 'payment_channel']);
        });

        Schema::dropIfExists('fundraiser_updates');

        Schema::table('fundraisers', function (Blueprint $table) {
            $table->dropColumn([
                'beneficiary', 'cover_image_url', 'allow_anonymous', 'allow_recurring',
                'suggested_amounts', 'matching_sponsor', 'matching_multiplier',
                'max_matching_minor', 'fund_allocation', 'show_leaderboard',
            ]);
        });

        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('payment_links');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('payment_channel_settings');
    }
};
