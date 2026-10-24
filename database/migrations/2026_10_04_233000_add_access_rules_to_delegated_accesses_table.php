<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delegated_accesses', function (Blueprint $table) {
            $table->string('authorization_type')->nullable()->after('relationship'); // long_term_occupant, legacy_contact, caregiver, property_manager, etc.
            $table->json('access_rules')->nullable()->after('permissions'); // days_permitted, time_window, amenity_access, parking_access, etc.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delegated_accesses', function (Blueprint $table) {
            $table->dropColumn(['authorization_type', 'access_rules']);
        });
    }
};
