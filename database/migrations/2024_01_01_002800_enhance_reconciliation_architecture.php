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
        if (Schema::hasTable('reconciliation_matches')) {
            Schema::table('reconciliation_matches', function (Blueprint $table) {
                if (! Schema::hasColumn('reconciliation_matches', 'status')) {
                    $table->string('status', 32)->default('MATCHED')->after('match_type');
                }
                if (! Schema::hasColumn('reconciliation_matches', 'provider')) {
                    $table->string('provider', 64)->nullable()->after('status');
                }
                if (! Schema::hasColumn('reconciliation_matches', 'provider_reference')) {
                    $table->string('provider_reference', 128)->nullable()->after('provider');
                }
                if (! Schema::hasColumn('reconciliation_matches', 'discrepancy_details')) {
                    $table->json('discrepancy_details')->nullable()->after('provider_reference');
                }
                if (! Schema::hasColumn('reconciliation_matches', 'resolution_action')) {
                    $table->string('resolution_action', 64)->nullable()->after('discrepancy_details');
                }
                if (! Schema::hasColumn('reconciliation_matches', 'resolution_notes')) {
                    $table->text('resolution_notes')->nullable()->after('resolution_action');
                }
                if (! Schema::hasColumn('reconciliation_matches', 'resolved_at')) {
                    $table->timestamp('resolved_at')->nullable()->after('resolution_notes');
                }
                if (! Schema::hasColumn('reconciliation_matches', 'resolved_by')) {
                    $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete()->after('resolved_at');
                }
            });
        }

        if (Schema::hasTable('reconciliation_batches')) {
            Schema::table('reconciliation_batches', function (Blueprint $table) {
                if (! Schema::hasColumn('reconciliation_batches', 'discrepancy_count')) {
                    $table->unsignedInteger('discrepancy_count')->default(0)->after('unmatched_count');
                }
                if (! Schema::hasColumn('reconciliation_batches', 'resolved_count')) {
                    $table->unsignedInteger('resolved_count')->default(0)->after('discrepancy_count');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('reconciliation_matches')) {
            Schema::table('reconciliation_matches', function (Blueprint $table) {
                $table->dropForeign(['resolved_by']);
                $table->dropColumn([
                    'status',
                    'provider',
                    'provider_reference',
                    'discrepancy_details',
                    'resolution_action',
                    'resolution_notes',
                    'resolved_at',
                    'resolved_by',
                ]);
            });
        }

        if (Schema::hasTable('reconciliation_batches')) {
            Schema::table('reconciliation_batches', function (Blueprint $table) {
                $table->dropColumn(['discrepancy_count', 'resolved_count']);
            });
        }
    }
};
