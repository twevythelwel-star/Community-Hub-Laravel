<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deals (businesses, vouchers, food apps), fundraising and billing.
 * Money is stored in minor units (cents) to avoid float drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('logo_url')->nullable();
            $table->string('ai_hint')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('food_apps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('logo_url')->nullable();
            $table->string('website_url');
            $table->string('ai_hint')->nullable();
            $table->unsignedTinyInteger('coupon_percentage')->nullable();
            $table->timestamps();
        });

        Schema::create('fundraisers', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->unsignedBigInteger('goal_minor');          // goal in cents
            $table->string('goal_currency', 3)->default('JMD');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('Upcoming');     // Active | Completed | Upcoming | Canceled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'end_date']);
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fundraiser_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('JMD');
            $table->string('donor_name')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->timestamp('donated_at');
            $table->timestamps();

            $table->index(['fundraiser_id', 'donated_at']);
        });

        // Replaces BillingProvider monthlyFee held in React state.
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_fee_minor')->default(500000); // JMD 5,000.00
            $table->string('currency', 3)->default('JMD');
            $table->unsignedTinyInteger('due_day_of_month')->default(1);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('JMD');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');
            $table->string('status')->default('Unpaid');   // Unpaid | Paid | Overdue | Waived
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_settings');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('fundraisers');
        Schema::dropIfExists('food_apps');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('businesses');
    }
};
