<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe webhook idempotency.
 *
 * Stripe delivers each event at least once and retries on any non-2xx, so the
 * same `checkout.session.completed` can arrive twice. The unique index on
 * `event_id` is the lock: the insert happens in the same database transaction
 * as the event's effects, so a duplicate delivery either sees the row and
 * stops, or fails the insert and rolls back. The same pattern as
 * gate_pass_nonces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestamp('processed_at');
            $table->timestamps();
        });

        // Refund events identify the payment by its PaymentIntent.
        Schema::table('invoices', function (Blueprint $table) {
            $table->index('stripe_payment_intent');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['stripe_payment_intent']);
        });

        Schema::dropIfExists('stripe_events');
    }
};
