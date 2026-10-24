<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | In-person (card-present / NFC) payments.
 |
 | A tap is not something a resident does in the online payment centre, and an
 | NFC-capable phone is not a card reader. It is taken on a reader registered
 | with the processor, at a location whose country and currency the processor
 | supports: POS → reader → payment SDK/API → acquirer → card network → issuer.
 |
 | `payment_terminals` holds readers the estate has registered AND the
 | processor has confirmed (verified_at). Every in-person payment records the
 | terminal, device and location it was taken on, and the processor's own
 | transaction id, on the payment and on its ledger row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terminals', function (Blueprint $table) {
            $table->id();
            $table->string('provider');                     // e.g. stripe_terminal
            $table->string('terminal_id');                  // the processor's reader id (tmr_…)
            $table->string('device_id')->nullable();        // hardware serial number
            $table->string('device_type')->nullable();      // e.g. bbpos_wisepos_e, stripe_s700
            $table->string('location_id');                  // the processor's location id (tml_…)
            $table->string('country', 2);                   // from the processor's location record
            $table->string('label');
            $table->string('status')->default('active');    // active | retired
            $table->timestamp('verified_at')->nullable();   // when the processor confirmed it
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'terminal_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_terminal_id')->nullable()->after('device_identifier')->constrained()->nullOnDelete();
            $table->string('terminal_id')->nullable()->after('payment_terminal_id');
            $table->string('location_id')->nullable()->after('terminal_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('terminal_id')->nullable()->after('device_identifier');
            $table->string('location_id')->nullable()->after('terminal_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['terminal_id', 'location_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_terminal_id');
            $table->dropColumn(['terminal_id', 'location_id']);
        });

        Schema::dropIfExists('payment_terminals');
    }
};
