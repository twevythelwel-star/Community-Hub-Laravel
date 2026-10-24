<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fundraisers', function (Blueprint $table) {
            $table->json('images')->nullable()->after('cover_image_url');
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->string('status')->default('completed')->after('payment_channel');
            $table->timestamp('refunded_at')->nullable()->after('status');
            $table->string('refund_reason')->nullable()->after('refunded_at');
            $table->text('notes')->nullable()->after('refund_reason');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn(['status', 'refunded_at', 'refund_reason', 'notes']);
        });

        Schema::table('fundraisers', function (Blueprint $table) {
            $table->dropColumn(['images']);
        });
    }
};
