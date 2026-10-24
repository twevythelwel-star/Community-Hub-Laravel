<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwilioTransactionTruthAndDeliveryCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const TWILIO_AUTH_TOKEN = 'test_twilio_secret_token_12345';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.sid' => 'AC_test_account_sid',
            'services.twilio.token' => self::TWILIO_AUTH_TOKEN,
            'services.twilio.from' => '+18765550000',
        ]);

        Community::create([
            'name' => 'Cypress Bay Estates',
            'code' => 'CYPRESS',
            'slug' => 'cypress-bay',
            'contact_email' => 'admin@cypressbay.org',
            'currency' => 'JMD',
        ]);
    }

    public function test_twilio_notification_is_only_dispatched_after_transaction_truth_is_completed(): void
    {
        $resident = User::factory()->create([
            'name' => 'Adrian Miller',
            'phone' => '+18765551234',
        ]);

        $invoice = Invoice::create([
            'user_id' => $resident->id,
            'reference' => 'INV-2026-00999',
            'amount_minor' => 4500000,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(7),
            'status' => 'Unpaid',
        ]);

        $orchestrator = app(PaymentOrchestratorService::class);

        Http::fake([
            'https://api.twilio.com/*' => Http::response([
                'sid' => 'SM998877665544332211',
                'status' => 'queued',
            ], 201),
        ]);

        // 1. Transaction in 'pending' status - Twilio must NOT be notified
        $pendingTx = Transaction::create([
            'user_id' => $resident->id,
            'invoice_id' => $invoice->id,
            'transaction_id' => 'CH-TXN-2026-00099911',
            'amount_minor' => 4500000,
            'currency' => 'JMD',
            'payment_channel' => 'bank_wire',
            'reference' => 'WIRE-999',
            'status' => 'pending',
        ]);

        $orchestrator->sendPaymentConfirmationNotification($pendingTx);
        Http::assertNothingSent();

        // 2. Transaction becomes 'completed' (authoritative truth established by provider/ledger)

        $completedTx = Transaction::create([
            'user_id' => $resident->id,
            'invoice_id' => $invoice->id,
            'transaction_id' => 'CH-TXN-2026-00099912',
            'amount_minor' => 4500000,
            'currency' => 'JMD',
            'payment_channel' => 'stripe_card',
            'reference' => 'pi_test_authoritative_123',
            'status' => 'completed',
        ]);

        $orchestrator->sendPaymentConfirmationNotification($completedTx);

        // Twilio HTTP API was called AFTER payment completion
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com')
                && str_contains($request['Body'], 'Receipt CH-TXN-2026-00099912');
        });

        // Event logged with Twilio MessageSid
        $event = TransactionEvent::where('transaction_id', $completedTx->id)
            ->where('event_type', 'notification_sent')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('SM998877665544332211', $event->provider_event_id);
    }

    public function test_twilio_webhook_requires_valid_x_twilio_signature(): void
    {
        $resident = User::factory()->create();
        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-00088801',
            'amount_minor' => 2000000,
            'currency' => 'JMD',
            'payment_channel' => 'stripe_card',
            'reference' => 'pi_test_sig',
            'status' => 'completed',
        ]);

        TransactionEvent::log($tx, 'notification_sent', 'completed', 'SM_TEST_SIG_1', 'Dispatched');

        $url = route('webhooks.twilio.status');
        $params = [
            'MessageSid' => 'SM_TEST_SIG_1',
            'MessageStatus' => 'delivered',
            'To' => '+18765551234',
        ];

        // 1. Missing signature header -> 403 Forbidden
        $response = $this->post($url, $params);
        $response->assertStatus(403);

        // 2. Tampered / invalid signature -> 403 Forbidden
        $response = $this->post($url, $params, [
            'X-Twilio-Signature' => 'invalid_signature_value',
        ]);
        $response->assertStatus(403);

        // 3. Valid signature -> 200 OK
        $validSignature = $this->twilioSignature($url, $params);

        $response = $this->post($url, $params, [
            'X-Twilio-Signature' => $validSignature,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
    }

    public function test_twilio_delivery_status_callback_updates_delivery_log_without_altering_transaction_truth(): void
    {
        $resident = User::factory()->create();
        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-00077701',
            'amount_minor' => 1000000,
            'currency' => 'JMD',
            'payment_channel' => 'stripe_card',
            'reference' => 'pi_test_delivery',
            'status' => 'completed', // Payment truth already established
        ]);

        TransactionEvent::log($tx, 'notification_sent', 'completed', 'SM_DELIVERY_TEST_123', 'Dispatched');

        $url = route('webhooks.twilio.status');
        $params = [
            'MessageSid' => 'SM_DELIVERY_TEST_123',
            'MessageStatus' => 'delivered',
            'To' => '+18765551234',
        ];

        $signature = $this->twilioSignature($url, $params);

        $response = $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        // Delivery event recorded
        $deliveryEvent = TransactionEvent::where('transaction_id', $tx->id)
            ->where('event_type', 'twilio_delivery_status')
            ->first();

        $this->assertNotNull($deliveryEvent);
        $this->assertStringContainsString('delivered', $deliveryEvent->payload_reference);

        // Transaction status MUST remain strictly 'completed'
        $this->assertEquals('completed', $tx->fresh()->status);
    }

    public function test_twilio_delivery_failure_callback_preserves_payment_truth(): void
    {
        $resident = User::factory()->create();
        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-00077702',
            'amount_minor' => 1000000,
            'currency' => 'JMD',
            'payment_channel' => 'stripe_card',
            'reference' => 'pi_test_undelivered',
            'status' => 'completed',
        ]);

        TransactionEvent::log($tx, 'notification_sent', 'completed', 'SM_FAIL_TEST_456', 'Dispatched');

        $url = route('webhooks.twilio.status');
        $params = [
            'MessageSid' => 'SM_FAIL_TEST_456',
            'MessageStatus' => 'undelivered',
            'ErrorCode' => '30008',
            'ErrorMessage' => 'Unknown mobile device error',
            'To' => '+18765551234',
        ];

        $signature = $this->twilioSignature($url, $params);

        $response = $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        // A failed text is NOT a failed payment: transaction remains completed
        $this->assertEquals('completed', $tx->fresh()->status);

        $event = TransactionEvent::where('transaction_id', $tx->id)
            ->where('event_type', 'twilio_delivery_status')
            ->first();

        $this->assertNotNull($event);
        $this->assertStringContainsString('Error 30008', $event->payload_reference);
    }

    public function test_signatures_match_twilios_own_algorithm(): void
    {
        // Expected value computed with twilio/sdk's RequestValidator for these inputs.
        $params = [
            'CallSid' => 'CA1234567890ABCDE',
            'Caller' => '+12349013030',
            'Digits' => '1234',
            'From' => '+12349013030',
            'To' => '+18005551212',
        ];

        $this->assertSame('vNe7KK2kJwCsxc9K3OLkkKB3qqI=', $this->twilioSignature('https://example.com/myapp.php?foo=1&bar=2', $params, '12345'));
    }

    public function test_callbacks_are_refused_when_no_auth_token_is_configured(): void
    {
        config(['services.twilio.token' => null]);

        $this->post(route('webhooks.twilio.status'), ['MessageSid' => 'SM_X', 'MessageStatus' => 'delivered'])
            ->assertStatus(403);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function twilioSignature(string $url, array $params, string $token = self::TWILIO_AUTH_TOKEN): string
    {
        ksort($params);

        $signed = $url;
        foreach ($params as $name => $value) {
            $signed .= $name.$value;
        }

        return base64_encode(hash_hmac('sha1', $signed, $token, true));
    }
}
