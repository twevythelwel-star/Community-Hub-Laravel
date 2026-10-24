<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('type');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('stripe_session_id')->nullable()->after('reference');
            $table->string('stripe_payment_intent')->nullable()->after('stripe_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['stripe_session_id', 'stripe_payment_intent']);
        });
    }
};
