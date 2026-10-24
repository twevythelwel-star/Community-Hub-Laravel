<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider')->default('stripe'); // stripe, bank_provider, manual, internal
            $table->string('method_type'); // wallet, card, bank_transfer, card_present, peer_transfer
            $table->string('provider_customer_id')->nullable();
            $table->string('provider_payment_method_id')->nullable();
            $table->string('display_name')->nullable();
            $table->string('last_four', 4)->nullable();
            $table->string('brand', 30)->nullable();
            $table->string('wallet_type', 30)->nullable(); // apple_pay, google_pay, samsung_wallet
            $table->string('bank_name')->nullable();
            $table->string('status')->default('active'); // active, inactive, expired
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
            $table->index(['provider', 'provider_payment_method_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('payment_channel')->constrained('payment_methods')->nullOnDelete();
            $table->string('provider')->nullable()->after('payment_method'); // stripe, bank_provider, internal, cash_office, manual
            $table->string('device_identifier', 50)->nullable()->after('provider'); // e.g. SECURITY-TABLET-04 for NFC POS
            $table->json('metadata')->nullable()->after('notes');
            $table->timestamp('authorized_at')->nullable()->after('settled_at');
            $table->timestamp('captured_at')->nullable()->after('authorized_at');
            $table->timestamp('failed_at')->nullable()->after('captured_at');
            $table->timestamp('refunded_at')->nullable()->after('failed_at');
        });

        Schema::create('transaction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('event_type'); // created, payment_started, authorized, captured, paid, webhook_received, notification_sent, failed
            $table->string('provider_event_id')->nullable();
            $table->string('status');
            $table->text('payload_reference')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['transaction_id', 'event_type']);
            $table->index('provider_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_events');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn([
                'provider',
                'device_identifier',
                'metadata',
                'authorized_at',
                'captured_at',
                'failed_at',
                'refunded_at',
            ]);
        });

        Schema::dropIfExists('payment_methods');
    }
};
