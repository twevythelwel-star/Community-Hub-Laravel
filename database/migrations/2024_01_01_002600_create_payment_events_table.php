<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_events')) {
            Schema::create('payment_events', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->string('event_id', 128);
                $table->string('event_type', 64);
                $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
                $table->string('merchant_account_id', 128)->nullable();
                $table->string('status', 32)->default('received'); // received, processed, duplicate, failed, ignored
                $table->longText('payload')->nullable();
                $table->text('signature')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'event_id']);
                $table->index(['provider', 'event_type']);
                $table->index(['transaction_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
