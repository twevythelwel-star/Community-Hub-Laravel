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
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->foreignId('delegated_access_id')->nullable()->after('visitor_id')->constrained('delegated_accesses')->nullOnDelete();
            $table->json('metadata')->nullable()->after('revocation_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->dropForeign(['delegated_access_id']);
            $table->dropColumn(['delegated_access_id', 'metadata']);
        });
    }
};
