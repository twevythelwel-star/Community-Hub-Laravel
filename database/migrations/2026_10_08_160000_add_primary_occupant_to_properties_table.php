<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('properties') && ! Schema::hasColumn('properties', 'primary_occupant_id')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->foreignId('primary_occupant_id')->nullable()->after('ownership_entity_id')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('properties') && Schema::hasColumn('properties', 'primary_occupant_id')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropConstrainedForeignId('primary_occupant_id');
            });
        }
    }
};
