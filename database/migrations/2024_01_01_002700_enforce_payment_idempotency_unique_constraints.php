<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Transactions: enforce provider_event_id UNIQUE and idempotency_key UNIQUE
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'provider_event_id')) {
                $table->string('provider_event_id', 128)->nullable()->unique()->after('provider_reference');
            }
            if (! Schema::hasColumn('transactions', 'idempotency_key')) {
                $table->string('idempotency_key', 128)->nullable()->unique()->after('provider_event_id');
            }
        });

        // 2. Payments: enforce idempotency_key UNIQUE
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'idempotency_key')) {
                $table->string('idempotency_key', 128)->nullable()->unique()->after('transaction_id');
            }
        });

        // 3. Payment Receipts: enforce transaction_id UNIQUE to guarantee exactly 1 receipt per transaction
        Schema::table('payment_receipts', function (Blueprint $table) {
            // Ensure 1:1 transaction to receipt
            $table->unique('transaction_id', 'payment_receipts_transaction_id_unique');
        });

        // 4. Payment Events: ensure provider_event_id column is present and indexed/unique with provider
        Schema::table('payment_events', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_events', 'provider_event_id')) {
                $table->string('provider_event_id', 128)->nullable()->after('event_id');
                $table->index('provider_event_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            if (Schema::hasColumn('payment_events', 'provider_event_id')) {
                $table->dropColumn('provider_event_id');
            }
        });

        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropUnique('payment_receipts_transaction_id_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
            if (Schema::hasColumn('transactions', 'provider_event_id')) {
                $table->dropColumn('provider_event_id');
            }
        });
    }
};
