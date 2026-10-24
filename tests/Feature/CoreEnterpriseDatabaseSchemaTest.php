<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Community;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\NotificationDelivery;
use App\Models\PaymentAttempt;
use App\Models\PaymentCustomer;
use App\Models\PaymentDispute;
use App\Models\PaymentMethod;
use App\Models\PaymentReceipt;
use App\Models\PaymentRefund;
use App\Models\Property;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationMatch;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreEnterpriseDatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_core_enterprise_payment_tables_exist_in_database(): void
    {
        $expectedTables = [
            'users',
            'communities',
            'properties',
            'invoices',
            'payment_customers',
            'payment_methods',
            'transactions',
            'payment_attempts',
            'transaction_events',
            'payment_refunds',
            'payment_disputes',
            'accounts',
            'ledger_entries',
            'payment_provider_accounts',
            'payment_provider_configs',
            'bank_transactions',
            'reconciliation_batches',
            'reconciliation_matches',
            'payment_links',
            'payment_qr_codes',
            'payment_receipts',
            'notification_deliveries',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Database table [{$table}] must exist in schema."
            );
        }
    }

    public function test_key_relational_hierarchy_from_user_to_transactions_and_sub_entities(): void
    {
        $community = Community::create([
            'name' => 'Cypress Bay Estates',
            'code' => 'CYPRESS',
            'slug' => 'cypress-bay',
            'contact_email' => 'admin@cypressbay.org',
            'currency' => 'JMD',
        ]);

        $resident = User::factory()->create([
            'name' => 'Dwight Watson',
            'email' => 'dwight@cypressbay.org',
            'phone' => '+18765558899',
            'lot' => 'Lot 15',
        ]);

        // 1. User -> Property
        $property = Property::create([
            'community_id' => $community->id,
            'owner_user_id' => $resident->id,
            'property_code' => 'PROP-CYP-0015',
            'lot_number' => 'Lot 15',
            'street_address' => '15 Royal Palm Way',
            'property_type' => 'Villa',
            'status' => 'Occupied',
        ]);
        $this->assertTrue($resident->properties->contains($property));
        $this->assertEquals($resident->id, $property->owner->id);

        // 2. User -> Payment Customer
        $customer = PaymentCustomer::create([
            'user_id' => $resident->id,
            'community_id' => $community->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_test_dwight15',
            'email' => $resident->email,
            'phone' => $resident->phone,
        ]);
        $this->assertTrue($resident->paymentCustomers->contains($customer));
        $this->assertEquals($resident->id, $customer->user->id);

        // 3. User -> Payment Methods
        $paymentMethod = PaymentMethod::create([
            'user_id' => $resident->id,
            'method_type' => 'card',
            'provider' => 'stripe',
            'provider_payment_method_id' => 'pm_test_card_123',
            'brand' => 'visa',
            'last_four' => '4242',
            'is_default' => true,
        ]);
        $this->assertTrue($resident->paymentMethods->contains($paymentMethod));
        $this->assertEquals($resident->id, $paymentMethod->user->id);

        // 4. User -> Invoices
        $invoice = Invoice::create([
            'user_id' => $resident->id,
            'reference' => 'INV-2026-00812',
            'amount_minor' => 6000000,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
            'status' => 'Unpaid',
        ]);
        $this->assertTrue($resident->invoices->contains($invoice));

        // 5. User -> Transactions
        $tx = Transaction::create([
            'user_id' => $resident->id,
            'payment_method_id' => $paymentMethod->id,
            'invoice_id' => $invoice->id,
            'transaction_id' => 'CH-TXN-2026-00088120',
            'amount_minor' => 6000000,
            'currency' => 'JMD',
            'payment_channel' => 'stripe_card',
            'reference' => 'pi_test_88120',
            'status' => 'completed',
        ]);
        $this->assertTrue($resident->transactions->contains($tx));

        // 6. Transaction -> Attempts
        $attempt1 = PaymentAttempt::create([
            'transaction_id' => $tx->id,
            'attempt_number' => 1,
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_test_88120_att1',
            'payment_method' => 'card',
            'amount_minor' => 6000000,
            'currency' => 'JMD',
            'status' => 'failed',
            'failure_code' => 'card_declined',
            'failure_reason' => 'Insufficient funds',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
        ]);

        $attempt2 = PaymentAttempt::create([
            'transaction_id' => $tx->id,
            'attempt_number' => 2,
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_test_88120_att2',
            'payment_method' => 'card',
            'amount_minor' => 6000000,
            'currency' => 'JMD',
            'status' => 'succeeded',
            'started_at' => now()->subMinutes(3),
            'completed_at' => now()->subMinutes(2),
        ]);
        $this->assertCount(2, $tx->attempts);
        $this->assertTrue($tx->attempts->contains($attempt1));
        $this->assertTrue($tx->attempts->contains($attempt2));

        // 7. Transaction -> Events
        $event = TransactionEvent::log($tx, 'webhook_received', 'completed', 'evt_test_123', 'Payment Intent Succeeded');
        $this->assertTrue($tx->events->contains($event));

        // 8. Transaction -> Refunds
        $refund = PaymentRefund::create([
            'transaction_id' => $tx->id,
            'refund_reference' => 'REF-2026-0001',
            'amount_minor' => 1000000,
            'currency' => 'JMD',
            'reason' => 'Overpayment adjustment',
            'status' => 'succeeded',
            'created_by' => $resident->id,
            'refunded_at' => now(),
        ]);
        $this->assertTrue($tx->refunds->contains($refund));

        // 9. Transaction -> Disputes
        $dispute = PaymentDispute::create([
            'transaction_id' => $tx->id,
            'provider_dispute_id' => 'dp_test_dispute_123',
            'amount_minor' => 6000000,
            'currency' => 'JMD',
            'reason' => 'fraudulent',
            'status' => 'needs_response',
            'evidence_due_by' => now()->addDays(7),
        ]);
        $this->assertTrue($tx->disputes->contains($dispute));

        // 10. Transaction -> Ledger Entries
        $cashAccount = Account::firstOrCreate(
            ['code' => Account::CODE_BANK_OPERATING],
            ['name' => 'Operating Bank Account', 'type' => 'asset', 'currency' => 'JMD', 'is_active' => true]
        );
        $revenueAccount = Account::firstOrCreate(
            ['code' => Account::CODE_MAINTENANCE_REVENUE],
            ['name' => 'Maintenance Fee Revenue', 'type' => 'revenue', 'currency' => 'JMD', 'is_active' => true]
        );

        $debitEntry = LedgerEntry::create([
            'transaction_id' => $tx->id,
            'account_id' => $cashAccount->id,
            'entry_type' => 'debit',
            'amount_minor' => 6000000,
            'currency' => 'JMD',
            'description' => 'Operating Cash Debit',
            'posted_at' => now(),
        ]);
        $this->assertTrue($tx->ledgerEntries->contains($debitEntry));

        // 11. Transaction -> Receipts
        $receipt = PaymentReceipt::create([
            'transaction_id' => $tx->id,
            'receipt_number' => 'REC-2026-00482',
            'receipt_type' => 'digital',
            'amount_minor' => 6000000,
            'currency' => 'JMD',
            'payer_name' => 'Dwight Watson',
            'payer_lot' => 'Lot 15',
            'issued_at' => now(),
        ]);
        $this->assertTrue($tx->receipts->contains($receipt));

        // 12. Transaction -> Notification Deliveries
        $delivery = NotificationDelivery::create([
            'transaction_id' => $tx->id,
            'user_id' => $resident->id,
            'channel' => 'sms',
            'recipient' => '+18765558899',
            'provider' => 'twilio',
            'provider_message_id' => 'SM_TEST_DELIVERY_99',
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
        $this->assertTrue($tx->notificationDeliveries->contains($delivery));
    }

    public function test_reconciliation_batch_and_bank_transactions_tri_party_matching(): void
    {
        $batch = ReconciliationBatch::create([
            'batch_number' => 'BATCH-2026-09-001',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'statement_balance_minor' => 50000000,
            'ledger_balance_minor' => 50000000,
            'variance_minor' => 0,
            'status' => 'balanced',
        ]);

        $resident = User::factory()->create();
        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-00055555',
            'amount_minor' => 2500000,
            'currency' => 'JMD',
            'payment_channel' => 'bank_wire',
            'reference' => 'CH-INV-004821-9284',
            'status' => 'completed',
        ]);

        $bankTx = BankTransaction::create([
            'reconciliation_batch_id' => $batch->id,
            'bank_name' => 'National Commercial Bank',
            'bank_reference' => 'NCB-TXN-998877',
            'transaction_date' => now()->toDateString(),
            'description' => 'Online Transfer CH-INV-004821-9284',
            'payer_reference' => 'CH-INV-004821-9284',
            'amount_minor' => 2500000,
            'currency' => 'JMD',
            'entry_type' => 'credit',
            'matched_transaction_id' => $tx->id,
            'status' => 'matched',
        ]);

        $match = ReconciliationMatch::create([
            'reconciliation_batch_id' => $batch->id,
            'bank_transaction_id' => $bankTx->id,
            'transaction_id' => $tx->id,
            'match_type' => 'exact_reference',
            'confidence_score' => 100,
            'matched_at' => now(),
        ]);

        $this->assertCount(1, $batch->bankTransactions);
        $this->assertCount(1, $batch->matches);
        $this->assertEquals($tx->id, $bankTx->matchedTransaction->id);
    }
}
