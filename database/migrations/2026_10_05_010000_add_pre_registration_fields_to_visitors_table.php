<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('arrival_window_start', 10)->nullable()->after('expected_at'); // e.g. "14:00"
            $table->string('arrival_window_end', 10)->nullable()->after('arrival_window_start'); // e.g. "18:00"
            $table->text('parking_instructions')->nullable()->after('vehicle');
            $table->json('community_rules')->nullable()->after('parking_instructions');
            $table->json('emergency_info')->nullable()->after('community_rules');
            $table->text('notes')->nullable()->after('emergency_info');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn([
                'arrival_window_start',
                'arrival_window_end',
                'parking_instructions',
                'community_rules',
                'emergency_info',
                'notes',
            ]);
        });
    }
};
