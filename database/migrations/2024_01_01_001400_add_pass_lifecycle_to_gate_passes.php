<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gate pass lifecycle.
 *
 * `status` held three free-text values (Active, Suspended, Revoked). It now
 * holds App\Enums\PassStatus, whose transition table decides which moves are
 * legal. Guest passes (visitors, contractors) belong to a visitor rather than
 * an account and carry the window they are valid for. Every transition is
 * written to gate_pass_transitions, so a pass's history can be reconstructed:
 * who approved it, who checked it in, at which gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->foreignId('visitor_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('valid_from')->nullable()->after('designated_gate');
            $table->timestamp('valid_until')->nullable()->after('valid_from');
            $table->boolean('single_entry')->default(false)->after('valid_until');
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
            $table->timestamp('status_changed_at')->nullable()->after('checked_out_at');

            $table->index(['status', 'valid_until']);
        });

        foreach (['Active' => 'ACTIVE', 'Suspended' => 'SUSPENDED', 'Revoked' => 'REVOKED'] as $old => $new) {
            DB::table('gate_passes')->where('status', $old)->update(['status' => $new]);
        }

        Schema::table('gate_passes', function (Blueprint $table) {
            $table->string('status')->default('ACTIVE')->change();
        });

        Schema::create('gate_pass_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_pass_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('gate')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['gate_pass_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_pass_transitions');

        Schema::table('gate_passes', function (Blueprint $table) {
            $table->string('status')->default('Active')->change();
        });

        foreach (['ACTIVE' => 'Active', 'SUSPENDED' => 'Suspended', 'REVOKED' => 'Revoked'] as $new => $old) {
            DB::table('gate_passes')->where('status', $new)->update(['status' => $old]);
        }

        Schema::table('gate_passes', function (Blueprint $table) {
            $table->dropIndex(['status', 'valid_until']);
            $table->dropConstrainedForeignId('visitor_id');
            $table->dropColumn(['valid_from', 'valid_until', 'single_entry', 'checked_in_at', 'checked_out_at', 'status_changed_at']);
        });
    }
};
