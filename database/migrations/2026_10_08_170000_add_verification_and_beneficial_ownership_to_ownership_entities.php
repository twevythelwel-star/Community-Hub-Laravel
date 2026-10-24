<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ownership_entities')) {
            Schema::table('ownership_entities', function (Blueprint $table) {
                if (! Schema::hasColumn('ownership_entities', 'verification_status')) {
                    $table->string('verification_status', 32)->default('unverified')->after('status');
                }
                if (! Schema::hasColumn('ownership_entities', 'verified_at')) {
                    $table->timestamp('verified_at')->nullable()->after('verification_status');
                }
                if (! Schema::hasColumn('ownership_entities', 'verified_by_user_id')) {
                    $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('ownership_entities', 'verification_source')) {
                    $table->string('verification_source', 128)->nullable()->after('verified_by_user_id');
                }
                if (! Schema::hasColumn('ownership_entities', 'verification_notes')) {
                    $table->text('verification_notes')->nullable()->after('verification_source');
                }
                if (! Schema::hasColumn('ownership_entities', 'verification_checklist')) {
                    $table->json('verification_checklist')->nullable()->after('verification_notes');
                }
                if (! Schema::hasColumn('ownership_entities', 'beneficial_ownership')) {
                    $table->json('beneficial_ownership')->nullable()->after('verification_checklist');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ownership_entities')) {
            Schema::table('ownership_entities', function (Blueprint $table) {
                $columns = [
                    'beneficial_ownership',
                    'verification_checklist',
                    'verification_notes',
                    'verification_source',
                    'verified_by_user_id',
                    'verified_at',
                    'verification_status',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('ownership_entities', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
