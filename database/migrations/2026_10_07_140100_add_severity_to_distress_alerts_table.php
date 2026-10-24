<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->string('severity_level', 32)->default('assistance')->after('assistance_type');
        });
    }

    public function down(): void
    {
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->dropColumn('severity_level');
        });
    }
};
