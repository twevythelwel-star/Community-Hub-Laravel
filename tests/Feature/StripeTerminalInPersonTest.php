<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentTerminal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Providers\StripeTerminal\StripeTerminalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Stripe\Exception\InvalidRequestException;
use Tests\TestCase;

/**
 * In-person (tap / insert) card payments on Stripe Terminal smart readers.
 *
 * CommunityHub POS → reader → Stripe → acquirer → network → issuer → webhook.
 * A reader is usable only once Stripe has confirmed it; each payment is still
 * Stripe's to accept or refuse; and it settles only on Stripe's webhook. The
 * Stripe API is replaced by a fake reader client; webhooks are signed exactly
 * as Stripe signs them.
 */
class StripeTerminalInPersonTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_terminal_tests';

    /** Stands in for Stripe's Terminal API and records what was asked of it. */
    private FakeTerminalClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_terminal',
            'services.stripe.webhook_secret' => self::SECRET,
            'payments.in_person_provider' => 'stripe_terminal',
        ]);

        $this->client = new FakeTerminalClient;
        $this->app->instance(StripeTerminalClient::class, $this->client);
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::Admin)->create();
    }

    private function invoice(int $amountMinor = 25_000): Invoice
    {
        return Invoice::create([
            'user_id' => User::factory()->role(UserRole::Homeowner)->create()->id,
            'reference' => 'INV-POS-'.fake()->unique()->numerify('####'),
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(10),
            'status' => 'Unpaid',
        ]);
    }

    private function charge(User $admin, Invoice $invoice, PaymentTerminal $terminal, ?float $amount = null): TestResponse
    {
        return $this->actingAs($admin)->post(route('dashboard.billing.terminals.charge'), [
            'invoice_id' => $invoice->id,
            'amount' => $amount ?? $invoice->amount_minor / 100,
            'terminal_id' => $terminal->id,
        ]);
    }

    /** @param  array<string, mixed>  $object */
    private function deliver(string $type, array $object): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.fake()->unique()->bothify('????????'), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
        $timestamp = time();

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET),
        ], $payload);
    }

    // ── Readers ──

    public function test_a_reader_is_recorded_only_once_stripe_confirms_it(): void
    {
        $this->actingAs($this->admin())
            ->post(route('dashboard.billing.terminals.store'), ['registration_code' => 'simulated-wpe', 'location_id' => 'tml_office', 'label' => 'Office reader'])
            ->assertSessionHasNoErrors();

        $terminal = PaymentTerminal::sole();
        $this->assertSame('tmr_fake_1', $terminal->terminal_id);
        $this->assertSame('WSC513000001', $terminal->device_id);
        $this->assertSame('tml_office', $terminal->location_id);
        $this->assertSame('US', $terminal->country, 'The country comes from Stripe\'s location record.');
        $this->assertTrue($terminal->isUsable());
    }

    public function test_a_reader_stripe_refuses_is_not_recorded(): void
    {
        $this->client->refuseRegistration = true;

        $this->actingAs($this->admin())
            ->post(route('dashboard.billing.terminals.store'), ['registration_code' => 'wrong-code', 'location_id' => 'tml_office', 'label' => 'Office reader'])
            ->assertSessionHasErrors('registration_code');

        $this->assertSame(0, PaymentTerminal::count());
    }

    // ── Taking a payment ──

    public function test_a_payment_goes_to_the_reader_and_waits_for_stripe(): void
    {
        $invoice = $this->invoice();
        $terminal = PaymentTerminal::factory()->create(['terminal_id' => 'tmr_office', 'location_id' => 'tml_office', 'device_id' => 'WSC513000042']);

        $this->charge($this->admin(), $invoice, $terminal)->assertSessionHasNoErrors();

        $payment = Payment::sole();
        $this->assertSame('nfc_pos', $payment->channel);
        $this->assertSame('stripe_terminal', $payment->provider);
        $this->assertSame(PaymentState::Processing, $payment->state);
        $this->assertSame('tmr_office', $payment->terminal_id);
        $this->assertSame('WSC513000042', $payment->device_identifier);
        $this->assertSame('tml_office', $payment->location_id);
        $this->assertSame('pi_fake_1', $payment->provider_payment_id);
        $this->assertSame([['tmr_office', 'pi_fake_1']], $this->client->processed);
        $this->assertSame(['card_present'], $this->client->intents[0]['payment_method_types']);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count(), 'Nothing is on the ledger until Stripe confirms the money.');
    }

    public function test_stripe_s_confirmation_settles_it_with_the_terminal_on_record(): void
    {
        $invoice = $this->invoice();
        $terminal = PaymentTerminal::factory()->create(['terminal_id' => 'tmr_office', 'location_id' => 'tml_office', 'device_id' => 'WSC513000042']);
        $this->charge($this->admin(), $invoice, $terminal);
        $payment = Payment::sole();

        $this->deliver('payment_intent.succeeded', [
            'id' => 'pi_fake_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 25_000, 'currency' => 'usd',
            'metadata' => ['payment_id' => (string) $payment->id, 'invoice_id' => (string) $invoice->id],
        ])->assertOk()->assertJson(['outcome' => 'settled']);

        $this->assertSame(PaymentState::Paid, $payment->fresh()->state);
        $this->assertSame('Paid', $invoice->fresh()->status);

        $row = $payment->fresh()->ledgerPayment();
        $this->assertSame('stripe:pi_fake_1', $row->reference);
        $this->assertSame('stripe_terminal', $row->provider);
        $this->assertSame('nfc_pos', $row->payment_channel);
        $this->assertSame('tmr_office', $row->terminal_id);
        $this->assertSame('tml_office', $row->location_id);
        $this->assertSame('WSC513000042', $row->device_identifier);
    }

    public function test_a_declined_card_fails_and_a_retry_reuses_the_same_charge(): void
    {
        $invoice = $this->invoice();
        $terminal = PaymentTerminal::factory()->create(['terminal_id' => 'tmr_office']);
        $admin = $this->admin();
        $this->charge($admin, $invoice, $terminal);
        $payment = Payment::sole();

        $this->deliver('terminal.reader.action_failed', [
            'id' => 'tmr_office', 'object' => 'terminal.reader',
            'action' => [
                'type' => 'process_payment_intent', 'status' => 'failed',
                'failure_code' => 'card_declined', 'failure_message' => 'Your card has insufficient funds.',
                'process_payment_intent' => ['payment_intent' => 'pi_fake_1'],
            ],
        ])->assertOk()->assertJson(['outcome' => 'reader_failed']);

        $payment->refresh();
        $this->assertSame(PaymentState::Failed, $payment->state);
        $this->assertSame('Your card has insufficient funds.', $payment->failure_reason);

        $this->actingAs($admin)->post(route('dashboard.billing.terminals.retry', $payment))->assertSessionHasNoErrors();

        // Stripe: reuse the PaymentIntent after a decline, never a second one.
        $this->assertCount(1, $this->client->intents);
        $this->assertSame([['tmr_office', 'pi_fake_1'], ['tmr_office', 'pi_fake_1']], $this->client->processed);
        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
    }

    public function test_a_lost_connection_is_left_to_the_payment_intent(): void
    {
        $invoice = $this->invoice();
        $this->charge($this->admin(), $invoice, PaymentTerminal::factory()->create(['terminal_id' => 'tmr_office']));

        // The card may have been authorised before the reader dropped off.
        $this->deliver('terminal.reader.action_failed', [
            'id' => 'tmr_office', 'object' => 'terminal.reader',
            'action' => ['status' => 'failed', 'failure_code' => 'connection_error', 'process_payment_intent' => ['payment_intent' => 'pi_fake_1']],
        ])->assertOk();

        $this->assertSame(PaymentState::Processing, Payment::sole()->state);
    }

    public function test_a_reader_stripe_refuses_the_payment_on_fails_it(): void
    {
        $this->client->refuseProcessing = 'Reader is currently offline.';

        $this->charge($this->admin(), $this->invoice(), PaymentTerminal::factory()->create())
            ->assertSessionHasErrors('terminal_id');

        $this->assertSame(PaymentState::Failed, Payment::sole()->state);
    }

    public function test_an_unconfirmed_or_retired_reader_cannot_take_payments(): void
    {
        $admin = $this->admin();

        $this->charge($admin, $this->invoice(), PaymentTerminal::factory()->unverified()->create())->assertSessionHasErrors('terminal_id');
        $this->charge($admin, $this->invoice(), PaymentTerminal::factory()->retired()->create())->assertSessionHasErrors('terminal_id');

        $this->assertSame(0, Payment::count());
        $this->assertSame([], $this->client->processed);
    }

    public function test_a_payment_can_be_cleared_from_the_reader(): void
    {
        $this->charge($admin = $this->admin(), $this->invoice(), PaymentTerminal::factory()->create(['terminal_id' => 'tmr_office']));
        $payment = Payment::sole();

        $this->actingAs($admin)->post(route('dashboard.billing.terminals.cancel', $payment))->assertSessionHasNoErrors();

        $this->assertSame(PaymentState::Canceled, $payment->fresh()->state);
        $this->assertSame(['tmr_office'], $this->client->cancelled);
    }

    // ── Not a resident channel, and off without a provider ──

    public function test_residents_cannot_choose_nfc_online(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = Invoice::create([
            'user_id' => $resident->id, 'reference' => 'INV-POS-RES', 'amount_minor' => 10_000, 'currency' => 'USD',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'due_on' => now()->addDays(5), 'status' => 'Unpaid',
        ]);

        $this->actingAs($resident)
            ->post(route('dashboard.billing.pay'), ['channel' => 'nfc_pos', 'amount' => 100, 'invoice_id' => $invoice->id])
            ->assertSessionHasErrors('channel');

        $this->actingAs($resident)
            ->get(route('dashboard.billing'))
            ->assertInertia(fn (Assert $page) => $page->where('paymentCenter.availableChannels', fn ($channels) => collect($channels)->pluck('key')->doesntContain('nfc_pos')));

        $this->assertSame(0, Payment::count());
    }

    public function test_staff_only(): void
    {
        $this->charge(User::factory()->role(UserRole::Homeowner)->create(), $this->invoice(), PaymentTerminal::factory()->create())->assertForbidden();
    }

    public function test_without_an_in_person_provider_nothing_can_be_taken_in_person(): void
    {
        config(['payments.in_person_provider' => null]);

        $admin = $this->admin();
        $this->charge($admin, $this->invoice(), PaymentTerminal::factory()->create())->assertSessionHasErrors('terminal_id');

        $this->actingAs($admin)
            ->get(route('dashboard.billing'))
            ->assertInertia(fn (Assert $page) => $page->where('inPerson.available', false));
    }
}

/** A Stripe Terminal API that answers like Stripe and remembers what it was asked. */
class FakeTerminalClient extends StripeTerminalClient
{
    public bool $refuseRegistration = false;

    public ?string $refuseProcessing = null;

    /** @var list<array<string, mixed>> */
    public array $intents = [];

    /** @var list<array{0: string, 1: string}> */
    public array $processed = [];

    /** @var list<string> */
    public array $cancelled = [];

    public function registerReader(string $registrationCode, string $locationId, string $label): array
    {
        if ($this->refuseRegistration) {
            throw InvalidRequestException::factory('The registration code is invalid.');
        }

        return ['id' => 'tmr_fake_1', 'serial_number' => 'WSC513000001', 'device_type' => 'bbpos_wisepos_e', 'location' => $locationId, 'label' => $label];
    }

    public function locationCountry(string $locationId): string
    {
        return 'US';
    }

    public function createCardPresentIntent(int $amountMinor, string $currency, array $metadata, string $idempotencyKey): string
    {
        $this->intents[] = ['amount' => $amountMinor, 'currency' => $currency, 'payment_method_types' => ['card_present'], 'metadata' => $metadata];

        return 'pi_fake_'.count($this->intents);
    }

    public function processOnReader(string $readerId, string $paymentIntentId): void
    {
        if ($this->refuseProcessing) {
            throw InvalidRequestException::factory($this->refuseProcessing);
        }

        $this->processed[] = [$readerId, $paymentIntentId];
    }

    public function cancelReaderAction(string $readerId): void
    {
        $this->cancelled[] = $readerId;
    }
}
