<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('authorized_people') && ! Schema::hasColumn('authorized_people', 'metadata')) {
            Schema::table('authorized_people', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('permissions');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('authorized_people') && Schema::hasColumn('authorized_people', 'metadata')) {
            Schema::table('authorized_people', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
