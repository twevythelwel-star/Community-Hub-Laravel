<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_channel_settings', function (Blueprint $table) {
            $table->string('integration_mode', 32)->default('MANUAL_VERIFICATION')->after('account_identifier');
        });

        // Set authoritative integration modes for existing default channels
        $modes = [
            'card' => 'HOSTED_CHECKOUT',
            'apple_pay' => 'WEBHOOK',
            'google_pay' => 'WEBHOOK',
            'samsung_wallet' => 'WEBHOOK',
            'bank_wire' => 'BANK_RECONCILIATION',
            'zelle' => 'BANK_RECONCILIATION',
            'cash_app' => 'BANK_RECONCILIATION',
            'qr_code' => 'HOSTED_CHECKOUT',
            'nfc_pos' => 'API',
            'cash_office' => 'MANUAL_VERIFICATION',
            'wallet' => 'API',
        ];

        foreach ($modes as $channel => $mode) {
            DB::table('payment_channel_settings')
                ->where('channel_key', $channel)
                ->update(['integration_mode' => $mode]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_channel_settings', function (Blueprint $table) {
            $table->dropColumn('integration_mode');
        });
    }
};
