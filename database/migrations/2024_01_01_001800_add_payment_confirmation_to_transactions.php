<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Office confirmation of payments the app cannot verify itself.
 |
 | Bank wire, cash, QR, NFC, digital-wallet and peer-transfer payments are
 | recorded as `pending` and only count once an administrator confirms the
 | money arrived. These columns carry what that confirmation needs:
 |
 |   donation_id      — the donation a pending gift becomes, so confirming or
 |                      rejecting the payment settles the right donation;
 |   invoice_item_ids — line items the resident chose to pay, marked Paid only
 |                      on confirmation;
 |   reviewed_by/at   — who confirmed or rejected it, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('donation_id')->nullable()->after('fundraiser_id')->constrained()->nullOnDelete();
            $table->json('invoice_item_ids')->nullable()->after('invoice_item_id');
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('donation_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['invoice_item_ids', 'reviewed_at']);
        });
    }
};
