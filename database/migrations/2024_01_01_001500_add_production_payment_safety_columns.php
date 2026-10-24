<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            $table->string('status')->default('processed')->after('type');
            $table->text('payload')->nullable()->after('status');
            $table->text('error_message')->nullable()->after('payload');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('fee_minor')->default(0)->after('amount_minor');
            $table->bigInteger('net_amount_minor')->nullable()->after('fee_minor');
            $table->timestamp('settled_at')->nullable()->after('status');
            $table->string('payout_reference')->nullable()->after('settled_at');
            $table->string('dispute_reason')->nullable()->after('payout_reference');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'fee_minor',
                'net_amount_minor',
                'settled_at',
                'payout_reference',
                'dispute_reason',
            ]);
        });

        Schema::table('stripe_events', function (Blueprint $table) {
            $table->dropColumn(['status', 'payload', 'error_message']);
        });
    }
};
