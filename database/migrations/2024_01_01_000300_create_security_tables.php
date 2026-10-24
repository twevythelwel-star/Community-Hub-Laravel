<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security: blocklist, gate passes, anti-replay nonce ledger and the access log.
 *
 * gate_pass_nonces replaces the in-process USED_NONCE_CACHE Map in
 * src/lib/gate-pass-engine/engine.ts, which lost all state on page reload and
 * could not detect replay across devices. REVOKED_PASS_IDS becomes the
 * gate_passes.revoked_at column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocklist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('photo_url')->nullable();
            $table->text('reason');
            $table->timestamp('date_added');
            $table->timestamp('expiry_date')->nullable();   // null = permanent
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('added_by');
            $table->timestamps();

            $table->index(['name', 'expiry_date']);
        });

        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            $table->string('pass_id')->unique();        // e.g. GP-HO-001
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category')->index();        // App\Enums\PassCategory
            $table->string('holder_name');
            $table->string('property')->nullable();
            $table->string('access_zone')->nullable();
            $table->string('designated_gate')->default('GATE-ANY');
            $table->string('color_variant')->nullable();
            $table->unsignedInteger('rotation_seq')->default(1);
            $table->string('status')->default('Active'); // Active | Suspended | Revoked
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason')->nullable();
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'category']);
        });

        Schema::create('gate_pass_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('nonce')->unique();
            $table->string('pass_id')->index();
            $table->timestamp('first_seen_at');
            $table->timestamp('expires_at')->index();   // prunable once the window closes
            $table->timestamps();
        });

        Schema::create('access_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->string('user_role');
            $table->string('method');    // Digital Pass | Staff Pass | Visitor Pass | Manual Entry
            $table->string('gate');      // Main Gate | Service Gate | Pedestrian Gate
            $table->string('pass_id')->nullable();
            $table->string('result')->default('ALLOW');  // ALLOW | DENY
            $table->string('deny_reason')->nullable();
            $table->json('validation_report')->nullable(); // full engine report, for audit
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['occurred_at', 'result']);
            $table->index(['gate', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_log_entries');
        Schema::dropIfExists('gate_pass_nonces');
        Schema::dropIfExists('gate_passes');
        Schema::dropIfExists('blocklist_entries');
    }
};
