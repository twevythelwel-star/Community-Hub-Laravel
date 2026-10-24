<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Properties
        if (! Schema::hasTable('properties')) {
            Schema::create('properties', function (Blueprint $table) {
                $table->id();
                $table->foreignId('community_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('property_code', 32)->unique();
                $table->string('lot_number', 50);
                $table->string('street_address')->nullable();
                $table->string('property_type')->default('Single Family'); // Single Family, Townhouse, Villa, Commercial
                $table->string('status')->default('Occupied'); // Occupied, Vacant, Under Construction
                $table->timestamps();

                $table->index(['community_id', 'lot_number']);
            });
        }

        // 2. Payment Customers (Provider customer identity mapping)
        if (! Schema::hasTable('payment_customers')) {
            Schema::create('payment_customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('community_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider', 32); // stripe, wipay, authorizenet
                $table->string('provider_customer_id', 128);
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_customer_id']);
                $table->index(['user_id', 'provider']);
            });
        }

        // 3. Payment Attempts (Attempts per transaction)
        if (! Schema::hasTable('payment_attempts')) {
            Schema::create('payment_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('attempt_number')->default(1);
                $table->string('provider', 32); // stripe, wipay, apple_pay, google_pay, ncb_bank, cash_office
                $table->string('provider_payment_id', 128)->nullable();
                $table->string('payment_method', 64); // visa, mastercard, apple_pay, google_pay, ach, cash
                $table->unsignedBigInteger('amount_minor');
                $table->string('currency', 3)->default('JMD');
                $table->string('status', 32)->default('initiated'); // initiated, requires_action, authenticating, processing, succeeded, failed
                $table->string('failure_code', 64)->nullable();
                $table->text('failure_reason')->nullable();
                $table->string('idempotency_key', 128)->nullable();
                $table->timestamp('started_at');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['transaction_id', 'attempt_number']);
                $table->index(['provider', 'provider_payment_id']);
            });
        }

        // 4. Payment Refunds
        if (! Schema::hasTable('payment_refunds')) {
            Schema::create('payment_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
                $table->string('refund_reference', 64)->unique();
                $table->string('provider_refund_id', 128)->nullable();
                $table->unsignedBigInteger('amount_minor');
                $table->string('currency', 3)->default('JMD');
                $table->string('reason')->nullable();
                $table->string('status', 32)->default('pending'); // pending, succeeded, failed
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamps();

                $table->index(['transaction_id', 'status']);
            });
        }

        // 5. Payment Disputes
        if (! Schema::hasTable('payment_disputes')) {
            Schema::create('payment_disputes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
                $table->string('provider_dispute_id', 128)->unique();
                $table->unsignedBigInteger('amount_minor');
                $table->string('currency', 3)->default('JMD');
                $table->string('reason', 64)->default('general');
                $table->string('status', 32)->default('needs_response'); // needs_response, under_review, won, lost
                $table->timestamp('evidence_due_by')->nullable();
                $table->text('dispute_notes')->nullable();
                $table->timestamps();

                $table->index(['transaction_id', 'status']);
            });
        }

        // 6. Payment Provider Accounts (Multi-merchant accounts)
        if (! Schema::hasTable('payment_provider_accounts')) {
            Schema::create('payment_provider_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('community_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider', 32); // stripe, wipay, ncb_business, scotiabank
                $table->string('merchant_account_id', 128);
                $table->string('account_name');
                $table->string('currency', 3)->default('JMD');
                $table->string('settlement_bank_name')->nullable();
                $table->string('settlement_account_number')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['provider', 'merchant_account_id']);
                $table->index(['community_id', 'is_active']);
            });
        }

        // 7. Payment Provider Configs
        if (! Schema::hasTable('payment_provider_configs')) {
            Schema::create('payment_provider_configs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('community_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider', 32);
                $table->string('environment', 16)->default('production'); // sandbox, production
                $table->string('integration_mode', 32)->default('API'); // API, HOSTED_CHECKOUT, WEBHOOK, BANK_RECONCILIATION, MANUAL_VERIFICATION
                $table->boolean('enabled')->default(true);
                $table->json('credentials_masked')->nullable();
                $table->timestamps();

                $table->unique(['community_id', 'provider', 'environment']);
            });
        }

        // 8. Reconciliation Batches
        if (! Schema::hasTable('reconciliation_batches')) {
            Schema::create('reconciliation_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_number', 64)->unique();
                $table->foreignId('community_id')->nullable()->constrained()->nullOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->unsignedBigInteger('statement_balance_minor');
                $table->unsignedBigInteger('ledger_balance_minor');
                $table->bigInteger('variance_minor')->default(0);
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('unmatched_count')->default(0);
                $table->string('status', 32)->default('open'); // open, balanced, discrepancy, closed
                $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // 9. Bank Transactions (Raw bank feed rows)
        if (! Schema::hasTable('bank_transactions')) {
            Schema::create('bank_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reconciliation_batch_id')->nullable()->constrained('reconciliation_batches')->nullOnDelete();
                $table->string('bank_name', 64)->default('NCB');
                $table->string('bank_reference', 128)->nullable();
                $table->date('transaction_date');
                $table->date('value_date')->nullable();
                $table->text('description');
                $table->string('payer_reference', 64)->nullable();
                $table->unsignedBigInteger('amount_minor');
                $table->string('currency', 3)->default('JMD');
                $table->string('entry_type', 16)->default('credit'); // credit (deposit), debit (withdrawal)
                $table->foreignId('matched_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->string('status', 32)->default('unmatched'); // unmatched, matched, manual_review, excluded
                $table->timestamps();

                $table->index(['transaction_date', 'status']);
                $table->index('payer_reference');
            });
        }

        // 10. Reconciliation Matches (3-way tri-party reconciliation link)
        if (! Schema::hasTable('reconciliation_matches')) {
            Schema::create('reconciliation_matches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reconciliation_batch_id')->constrained('reconciliation_batches')->cascadeOnDelete();
                $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->string('match_type', 32)->default('exact_reference'); // exact_reference, amount_date, manual
                $table->unsignedSmallInteger('confidence_score')->default(100);
                $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('matched_at');
                $table->timestamps();
            });
        }

        // 11. Payment QR Codes
        if (! Schema::hasTable('payment_qr_codes')) {
            Schema::create('payment_qr_codes', function (Blueprint $table) {
                $table->id();
                $table->string('code_type', 32)->default('dynamic_invoice'); // dynamic_invoice, payment_link, poster_event
                $table->foreignId('payment_link_id')->nullable()->constrained('payment_links')->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->string('token', 64)->unique();
                $table->string('target_url');
                $table->mediumText('qr_data_uri')->nullable(); // In-memory PNG Data URI
                $table->unsignedInteger('scan_count')->default(0);
                $table->timestamp('expires_at')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index(['code_type', 'active']);
            });
        }

        // 12. Payment Receipts
        if (! Schema::hasTable('payment_receipts')) {
            Schema::create('payment_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
                $table->string('receipt_number', 64)->unique(); // REC-2026-0001, CR-2026-00081
                $table->string('receipt_type', 32)->default('digital'); // digital, cash_desk, bank_transfer
                $table->unsignedBigInteger('amount_minor');
                $table->string('currency', 3)->default('JMD');
                $table->string('payer_name');
                $table->string('payer_lot')->nullable();
                $table->string('issued_by_name')->nullable();
                $table->timestamp('issued_at');
                $table->string('pdf_storage_path')->nullable();
                $table->timestamps();

                $table->index(['transaction_id', 'receipt_type']);
            });
        }

        // 13. Notification Deliveries
        if (! Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('channel', 16); // sms, whatsapp, email
                $table->string('recipient', 128); // Phone or email
                $table->string('provider', 32)->default('twilio'); // twilio, mailgun, ses
                $table->string('provider_message_id', 128)->nullable(); // Twilio MessageSid
                $table->string('status', 32)->default('queued'); // queued, sent, delivered, undelivered, failed
                $table->string('error_code', 32)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();

                $table->index(['transaction_id', 'channel']);
                $table->index('provider_message_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payment_qr_codes');
        Schema::dropIfExists('reconciliation_matches');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('reconciliation_batches');
        Schema::dropIfExists('payment_provider_configs');
        Schema::dropIfExists('payment_provider_accounts');
        Schema::dropIfExists('payment_disputes');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_customers');
        Schema::dropIfExists('properties');
    }
};
