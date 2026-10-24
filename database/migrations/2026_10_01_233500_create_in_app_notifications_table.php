<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('in_app_notifications')) {
            Schema::create('in_app_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('tenant_id', 64)->nullable()->index();
                $table->string('category', 48)->default('general')->index();
                $table->string('title');
                $table->text('body');
                $table->string('action_url', 512)->nullable();
                $table->string('priority', 24)->default('normal'); // low, normal, high, urgent
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable()->index();
                $table->timestamps();

                $table->index(['user_id', 'read_at']);
                $table->index(['category', 'priority']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
    }
};
