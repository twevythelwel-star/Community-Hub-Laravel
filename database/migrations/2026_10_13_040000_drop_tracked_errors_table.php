<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The error tracker belonged to the observability showcase removed on
 * 2026-10-09; nothing has written to or read this table since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tracked_errors');
    }

    public function down(): void
    {
        Schema::create('tracked_errors', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('exception_class');
            $table->text('message');
            $table->string('file');
            $table->unsignedInteger('line');
            $table->string('severity', 20)->default('error');
            $table->string('status', 20)->default('unresolved');
            $table->unsignedInteger('occurrences_count')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->json('context')->nullable();
            $table->longText('stack_trace')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index('last_seen_at');
        });
    }
};
