<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications, warnings (+ per-user responses), events, updates, feedback,
 * guidelines and the changelog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->json('target_roles')->nullable();   // AI-targeted notification audience
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index('published_at');
        });

        Schema::create('warnings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index('issued_at');
        });

        // Replaces client-side confirms/denies counters with auditable per-user votes.
        Schema::create('warning_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warning_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('response');   // confirmed | denied
            $table->timestamps();

            $table->unique(['warning_id', 'user_id']);
        });

        Schema::create('community_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->timestamp('start_date');
            $table->timestamp('end_date')->nullable();
            $table->string('image_url')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('start_date');
        });

        Schema::create('community_updates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('date');
            $table->text('summary');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('date');
        });

        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('submitted_by');
            $table->string('user_role');
            $table->string('type');                      // Issue | Suggestion
            $table->string('subject');
            $table->text('body')->nullable();
            $table->string('status')->default('New');    // New | In Progress | Resolved
            $table->text('admin_response')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
        });

        Schema::create('guidelines', function (Blueprint $table) {
            $table->id();
            $table->string('category')->index();
            $table->string('title');
            $table->text('description');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('changelog_entries', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->date('released_on');
            $table->string('title');
            $table->text('body');
            $table->timestamps();

            $table->index('released_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changelog_entries');
        Schema::dropIfExists('guidelines');
        Schema::dropIfExists('feedback');
        Schema::dropIfExists('community_updates');
        Schema::dropIfExists('community_events');
        Schema::dropIfExists('warning_responses');
        Schema::dropIfExists('warnings');
        Schema::dropIfExists('notifications');
    }
};
