<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add notification channel preferences to visitors table.
 *
 * This allows visitors to receive their QR code pass via email, SMS, or WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->boolean('notify_email')->default(true)->after('contact');
            $table->boolean('notify_sms')->default(false)->after('notify_email');
            $table->boolean('notify_whatsapp')->default(false)->after('notify_sms');
            $table->string('qr_code_path')->nullable()->after('notify_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn(['notify_email', 'notify_sms', 'notify_whatsapp', 'qr_code_path']);
        });
    }
};
