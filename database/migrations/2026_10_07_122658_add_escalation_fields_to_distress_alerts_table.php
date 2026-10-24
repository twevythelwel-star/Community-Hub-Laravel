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
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->string('escalation_level')->nullable()->after('status')->comment('silent_alert, security_escalation, emergency_services');
            $table->timestamp('escalated_at')->nullable()->after('triggered_at');
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete()->after('triggered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->dropForeign(['escalated_by']);
            $table->dropColumn(['escalation_level', 'escalated_at', 'escalated_by']);
        });
    }
};
