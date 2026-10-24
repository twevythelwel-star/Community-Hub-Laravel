<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safety_profiles', function (Blueprint $table) {
            $table->json('allowed_assistance')->nullable()->after('preferred_assistance');
            $table->boolean('allow_contact_sharing')->default(false)->after('silent_alerts_enabled');
        });

        Schema::table('safety_profiles', function (Blueprint $table) {
            $table->foreignId('gate_pass_id')->nullable()->unique()->constrained('gate_passes')->nullOnDelete();
            $table->foreignId('visitor_id')->nullable()->unique()->constrained('visitors')->nullOnDelete();
        });

        Schema::table('safety_profiles', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->foreignId('safety_profile_id')->nullable()->constrained('safety_profiles')->nullOnDelete()->after('user_id');
            $table->foreignId('visitor_id')->nullable()->constrained('visitors')->nullOnDelete()->after('safety_profile_id');
        });

        Schema::table('distress_alert_events', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('distress_alert_events', function (Blueprint $table) {
            $table->foreignId('safety_profile_id')->nullable()->constrained('safety_profiles')->nullOnDelete()->after('user_id');
            $table->foreignId('visitor_id')->nullable()->constrained('visitors')->nullOnDelete()->after('safety_profile_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('distress_alerts') && Schema::hasTable('distress_alert_events')
            && (DB::table('distress_alerts')->whereNull('user_id')->exists()
                || DB::table('distress_alert_events')->whereNull('user_id')->exists())) {
            throw new RuntimeException('Cannot remove credential-holder safety support while accountless audit incidents exist.');
        }

        Schema::table('distress_alert_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visitor_id');
            $table->dropConstrainedForeignId('safety_profile_id');
        });

        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visitor_id');
            $table->dropConstrainedForeignId('safety_profile_id');
        });

        Schema::table('safety_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gate_pass_id');
            $table->dropConstrainedForeignId('visitor_id');
            $table->dropColumn(['allowed_assistance', 'allow_contact_sharing']);
        });

        Schema::table('safety_profiles', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('distress_alert_events', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
