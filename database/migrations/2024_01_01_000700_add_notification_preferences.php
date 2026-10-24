<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification channel preferences.
 *
 * The settings page showed three switches — email, push, SMS — with a
 * "Save Preferences" button that had no handler. The switches were uncontrolled
 * (`defaultChecked` on the first, nothing on the other two), so a resident could
 * toggle them, click Save, and have nothing recorded anywhere.
 *
 * Email defaults on and the other two off, matching what the form displayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->boolean('notify_email')->default(true)->after('map_show_landmarks');
            $table->boolean('notify_push')->default(false)->after('notify_email');
            $table->boolean('notify_sms')->default(false)->after('notify_push');
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn(['notify_email', 'notify_push', 'notify_sms']);
        });
    }
};
