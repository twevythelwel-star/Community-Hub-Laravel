<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safety_profiles', function (Blueprint $table) {
            $table->json('assistance_preferences')->nullable()->after('preferred_assistance');
            $table->text('emergency_contacts')->nullable()->after('emergency_contact_relationship');
            $table->string('preferred_gate', 40)->nullable()->after('assistance_preferences');
            $table->unsignedBigInteger('preferred_vehicle_id')->nullable()->after('preferred_gate');
        });
    }

    public function down(): void
    {
        Schema::table('safety_profiles', function (Blueprint $table) {
            $table->dropColumn(['assistance_preferences', 'emergency_contacts', 'preferred_gate', 'preferred_vehicle_id']);
        });
    }
};
