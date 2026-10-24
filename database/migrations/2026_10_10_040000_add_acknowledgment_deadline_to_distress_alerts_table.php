<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->timestamp('acknowledgment_deadline_at')->nullable()->after('triggered_at');
        });
    }

    public function down(): void
    {
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->dropColumn(['acknowledgment_deadline_at']);
        });
    }
};
