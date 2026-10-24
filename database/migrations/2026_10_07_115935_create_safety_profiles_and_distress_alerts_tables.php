<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Discreet distress / silent assistance.
 |
 | A resident's safety profile hangs off an opaque, revocable Safety Credential
 | ID, never off anything printed in a QR code. The gate pass token stays
 | exactly as it is: a distress signal travels separately (an armed credential,
 | a duress PIN, or an in-app request), so nothing an observer can scan or see
 | tells a distress entry from a normal one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Opaque and revocable; resolves to this profile for security only.
            $table->string('safety_credential_id', 40)->unique();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->string('emergency_contact_relationship', 60)->nullable();
            // police | medical | fire | security | contact
            $table->string('preferred_assistance', 20)->default('security');
            $table->text('medical_notes')->nullable();
            $table->boolean('silent_alerts_enabled')->default(true);
            // Keyed hash of the duress PIN, so it can be looked up but not read back.
            $table->string('duress_pin_hash', 64)->nullable()->unique();
            // An armed credential raises silent assistance on its next gate scan.
            $table->timestamp('armed_until')->nullable();
            $table->timestamps();
        });

        Schema::create('distress_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gate_pass_id')->nullable()->constrained()->nullOnDelete();
            // app | qr_gesture | qr_scan | duress_pin
            $table->string('channel', 20);
            // police | medical | fire | security | contact
            $table->string('assistance_type', 20);
            // open | acknowledged | resolved | false_alarm
            $table->string('status', 20)->default('open');
            $table->string('gate', 40)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('triggered_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distress_alerts');
        Schema::dropIfExists('safety_profiles');
    }
};
