<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_id')->nullable()->index();
            $table->string('version', 10)->default('v1')->index();
            $table->string('method', 10);
            $table->string('path', 255)->index();
            $table->unsignedSmallInteger('status_code')->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('query_params')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'status_code']);
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('estate_id', 50)->nullable()->index();
            $table->string('name', 100);
            $table->string('url', 500);
            $table->string('secret', 128);
            $table->json('events'); // array of event strings e.g. ["gate_pass.created", "visitor.checked_in"]
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('event', 100)->index();
            $table->string('url', 500);
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->boolean('is_success')->default(false)->index();
            $table->json('request_payload');
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'status_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_logs');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('api_request_logs');
    }
};
