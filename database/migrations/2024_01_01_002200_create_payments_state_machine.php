<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Payments as an enforced state machine (App\Enums\PaymentState).
 |
 | `payments` holds one row per payment attempt and the state it is in, from
 | the moment the payer is shown its CH-YYYY-NNNNNNNNNN number. `transactions`
 | goes back to being the ledger of money that moved: the row that records a
 | payment is written when the payment is Paid, and carries the payment's
 | number. Every state change is written to `payment_state_transitions`.
 |
 | `id_sequences` hands out CH- and LED- numbers. They were "latest + 1"
 | read without a lock, on unique columns, so two payments at once could
 | collide and fail.
 |
 | Existing rows are backfilled so their history carries over. Ledger rows
 | that were only ever placeholders (pending card attempts, office payments
 | awaiting confirmation) get a payment in the matching state; the placeholder
 | row is promoted to the ledger row if the payment is later paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_sequences', function (Blueprint $table) {
            $table->string('scope');                   // "transaction", "ledger_entry"
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->primary(['scope', 'year']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 32)->unique();   // CH-YYYY-NNNNNNNNNN
            $table->string('applies_to');              // invoice, donation, payment_link
            $table->string('purpose', 100);            // Transaction::PURPOSE_*
            $table->string('channel');                 // card, wallet, bank_wire, cash_office, ...
            $table->string('provider');                // stripe, internal, office
            $table->string('state')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fundraiser_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('donation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('provider_session_id')->nullable()->unique();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('payer_reference')->nullable();
            $table->string('device_identifier', 100)->nullable();
            $table->json('invoice_item_ids')->nullable();
            $table->json('metadata')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('retry_of_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('bank_reference')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('bank_reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'state']);
            $table->index(['user_id', 'state']);
        });

        Schema::create('payment_state_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source');                  // "admin", "resident", "stripe:evt_…", "migration", …
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_id', 'id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $this->seedSequences();
        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });

        Schema::dropIfExists('payment_state_transitions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('id_sequences');
    }

    /** Start each counter above the highest number already issued. */
    private function seedSequences(): void
    {
        $highest = [];

        foreach (['transaction' => ['transactions', 'transaction_id', 'CH'], 'ledger_entry' => ['ledger_entries', 'entry_id', 'LED']] as $scope => [$table, $column, $prefix]) {
            DB::table($table)->whereNotNull($column)->pluck($column)->each(function (string $id) use ($scope, $prefix, &$highest) {
                if (preg_match("/^{$prefix}-(\\d{4})-(\\d+)$/", $id, $m)) {
                    $highest[$scope][(int) $m[1]] = max($highest[$scope][(int) $m[1]] ?? 0, (int) $m[2]);
                }
            });
        }

        foreach ($highest as $scope => $years) {
            foreach ($years as $year => $value) {
                DB::table('id_sequences')->insert(['scope' => $scope, 'year' => $year, 'last_value' => $value]);
            }
        }
    }

    /** One payment per existing ledger payment row; refund rows attach to theirs. */
    private function backfill(): void
    {
        $now = now();
        $office = ['bank_wire', 'cash_office', 'qr_code', 'nfc_pos', 'apple_pay', 'google_pay', 'samsung_wallet', 'zelle', 'cash_app'];

        DB::table('transactions')
            ->whereNotIn('status', ['refunded', 'disputed', 'reinstated'])
            ->where('reference', 'not like', 'stripe-refund:%')
            ->where('reference', 'not like', 'stripe-dispute:%')
            ->where('reference', 'not like', 'stripe-failed:%')
            ->orderBy('id')
            ->each(function (object $tx) use ($now, $office) {
                $isCard = in_array($tx->payment_channel, ['card', 'stripe_card'], true);
                $paymentIntent = str_starts_with($tx->reference, 'stripe:') ? substr($tx->reference, strlen('stripe:')) : null;
                $sessionId = str_starts_with((string) $tx->provider_reference, 'cs_') ? $tx->provider_reference : null;

                $state = match (true) {
                    $tx->status === 'completed' => 'paid',
                    $tx->status === 'rejected' => 'rejected',
                    $tx->status === 'failed' => $isCard ? 'failed' : 'rejected',
                    $tx->status === 'received' => 'received',
                    $tx->status === 'verified' => 'verified',
                    // A card attempt that never completed stays open, so a late
                    // Stripe confirmation can still settle it.
                    $isCard => 'created',
                    default => 'awaiting_transfer',
                };

                if ($paymentIntent && $state === 'paid') {
                    $refunded = (int) DB::table('transactions')
                        ->where('reference', 'like', "stripe-refund:{$paymentIntent}:%")
                        ->sum('amount_minor');

                    $state = match (true) {
                        $refunded >= (int) $tx->amount_minor => 'refunded',
                        $refunded > 0 => 'partially_refunded',
                        default => 'paid',
                    };
                }

                $channel = $tx->payment_channel === 'stripe_card' ? 'card' : $tx->payment_channel;

                $paymentId = DB::table('payments')->insertGetId([
                    'transaction_id' => $tx->transaction_id ?? sprintf('CH-%04d-M%09d', (int) date('Y', strtotime($tx->created_at)), $tx->id),
                    'applies_to' => $tx->invoice_id ? 'invoice' : ($tx->fundraiser_id ? 'donation' : 'payment_link'),
                    'purpose' => $tx->purpose ?? ($tx->fundraiser_id ? 'Fundraising Donation' : 'HOA Assessment'),
                    'channel' => $channel,
                    'provider' => $channel === 'card' ? 'stripe' : ($channel === 'wallet' ? 'internal' : 'office'),
                    'state' => $state,
                    'user_id' => $tx->user_id,
                    'invoice_id' => $tx->invoice_id,
                    'fundraiser_id' => $tx->fundraiser_id,
                    'donation_id' => $tx->donation_id,
                    'payment_link_id' => $tx->payment_link_id,
                    'payment_method_id' => $tx->payment_method_id ?? null,
                    'amount_minor' => $tx->amount_minor,
                    'currency' => $tx->currency,
                    'provider_session_id' => $sessionId,
                    'provider_payment_id' => $paymentIntent,
                    'payer_reference' => $paymentIntent || ! in_array($channel, $office, true) ? null : $tx->reference,
                    'device_identifier' => $tx->device_identifier ?? null,
                    'invoice_item_ids' => $tx->invoice_item_ids,
                    'received_by' => $tx->status === 'received' ? $tx->reviewed_by : null,
                    'received_at' => $tx->status === 'received' ? $tx->reviewed_at : null,
                    'paid_at' => $state === 'paid' ? ($tx->settled_at ?? $tx->created_at) : null,
                    'created_at' => $tx->created_at,
                    'updated_at' => $now,
                ]);

                DB::table('payment_state_transitions')->insert([
                    'payment_id' => $paymentId,
                    'from_state' => null,
                    'to_state' => $state,
                    'actor_id' => $tx->reviewed_by,
                    'source' => 'migration',
                    'note' => "Backfilled from ledger row {$tx->reference} ({$tx->status})",
                    'created_at' => $now,
                ]);

                DB::table('transactions')->where('id', $tx->id)->update(['payment_id' => $paymentId]);

                if ($paymentIntent) {
                    DB::table('transactions')
                        ->where('reference', 'like', "stripe-refund:{$paymentIntent}:%")
                        ->update(['payment_id' => $paymentId]);
                }
            });
    }
};
