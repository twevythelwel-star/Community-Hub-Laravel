<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('authorization_documents')) {
            Schema::table('authorization_documents', function (Blueprint $table) {
                if (! Schema::hasColumn('authorization_documents', 'ownership_entity_id')) {
                    $table->foreignId('ownership_entity_id')->nullable()->after('household_id')->constrained('ownership_entities')->nullOnDelete();
                }
                if (! Schema::hasColumn('authorization_documents', 'authorized_person_id')) {
                    $table->foreignId('authorized_person_id')->nullable()->after('ownership_entity_id')->constrained('authorized_people')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('authorization_documents')) {
            Schema::table('authorization_documents', function (Blueprint $table) {
                if (Schema::hasColumn('authorization_documents', 'authorized_person_id')) {
                    $table->dropConstrainedForeignId('authorized_person_id');
                }
                if (Schema::hasColumn('authorization_documents', 'ownership_entity_id')) {
                    $table->dropConstrainedForeignId('ownership_entity_id');
                }
            });
        }
    }
};
