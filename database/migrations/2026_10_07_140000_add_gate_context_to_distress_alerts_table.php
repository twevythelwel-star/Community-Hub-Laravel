<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->string('event_type', 40)->nullable()->after('gate');
            $table->timestamp('event_at')->nullable()->after('event_type');
            $table->string('credential_reference', 100)->nullable()->after('event_at');
            $table->json('vehicle_context')->nullable()->after('credential_reference');
        });
    }

    public function down(): void
    {
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->dropColumn(['event_type', 'event_at', 'credential_reference', 'vehicle_context']);
        });
    }
};
