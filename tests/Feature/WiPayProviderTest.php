<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Donation;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Card payments through WiPay's hosted page (Payments API 1.0.8).
 *
 * WiPay's only report of the outcome is the payer's browser returning with
 * the result in the query string, signed for successes as
 * md5(transaction_id . original total . API key). Nothing here reaches WiPay:
 * its API is faked at the HTTP layer, and results are signed exactly as
 * WiPay documents.
 */
class WiPayProviderTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = '123';

    private const WIPAY_TX = 'WPY-100';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.card_provider' => 'wipay',
            'payments.providers.wipay' => [
                'account_number' => '1234567890',
                'api_key' => self::API_KEY,
                'environment' => 'sandbox',
                'country_code' => 'JM',
                'fee_structure' => 'merchant_absorb',
                'origin' => 'CommunityHub',
            ],
        ]);
    }

    /** WiPay accepts the request and hands back its hosted page. */
    private function wipayAccepts(): void
    {
        Http::fake([
            'jm.wipayfinancial.com/plugins/payments/request' => Http::response([
                'url' => 'https://jm.wipayfinancial.com/hosted/abc123',
                'message' => 'OK',
                'transaction_id' => self::WIPAY_TX,
            ]),
        ]);
    }

    private function invoiceFor(User $user, int $amountMinor = 10_000): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'reference' => 'INV-WPY-'.fake()->unique()->numerify('####'),
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(10),
            'status' => 'Unpaid',
        ]);
    }

    private function payByCard(User $user, Invoice $invoice): Payment
    {
        $this->wipayAccepts();

        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'card',
                'amount' => $invoice->amount_minor / 100,
                'currency' => 'JMD',
                'invoice_id' => $invoice->id,
            ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://jm.wipayfinancial.com/hosted/abc123');

        return Payment::sole();
    }

    /** @return array<string, string> the query string WiPay returns for a success */
    private function success(Payment $payment, array $overrides = []): array
    {
        $total = number_format($payment->amount_minor / 100, 2, '.', '');

        return [
            'status' => 'success',
            'transaction_id' => self::WIPAY_TX,
            'order_id' => str_replace('-', '', $payment->transaction_id),
            'total' => $total,
            'currency' => 'JMD',
            'message' => '[1-R1]: Transaction is approved.',
            'card' => 'XXXXXXXXXXXX1111',
            'date' => now()->format('Y-m-d H:i:s'),
            'hash' => md5(self::WIPAY_TX.$total.self::API_KEY),
            ...$overrides,
        ];
    }

    private function returnFromWiPay(User $user, Payment $payment, array $query): TestResponse
    {
        // A plain browser visit, as WiPay's redirect is: not an Inertia request.
        return $this->flushHeaders()->actingAs($user)->get(route('dashboard.payments.return', $payment).'?'.http_build_query($query));
    }

    // ── Starting the payment ──

    public function test_card_payment_goes_to_the_wipay_hosted_page(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = $this->invoiceFor($user);

        $payment = $this->payByCard($user, $invoice);

        $this->assertSame('wipay', $payment->provider);
        $this->assertSame(PaymentState::Processing, $payment->state);
        $this->assertSame(self::WIPAY_TX, $payment->provider_payment_id);
        $this->assertSame(0, Transaction::count(), 'Nothing is on the ledger until WiPay confirms the money.');

        Http::assertSent(function (HttpRequest $request) use ($payment) {
            return $request->url() === 'https://jm.wipayfinancial.com/plugins/payments/request'
                && $request->hasHeader('Accept', 'application/json')
                && $request['account_number'] === '1234567890'
                && $request['country_code'] === 'JM'
                && $request['currency'] === 'JMD'
                && $request['environment'] === 'sandbox'
                && $request['method'] === 'credit_card'
                && $request['fee_structure'] === 'merchant_absorb'
                && $request['total'] === '100.00'
                && $request['order_id'] === str_replace('-', '', $payment->transaction_id)
                && strlen($request['order_id']) <= 16
                && $request['response_url'] === route('dashboard.payments.return', $payment)
                && ! isset($request['api_key']);
        });
    }

    public function test_a_refused_request_leaves_the_payment_failed_and_says_so(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid account_number'], 422)]);

        $user = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = $this->invoiceFor($user);

        $this->actingAs($user)
            ->post(route('dashboard.billing.pay'), ['channel' => 'card', 'amount' => 100, 'currency' => 'JMD', 'invoice_id' => $invoice->id])
            ->assertSessionHasErrors('amount');

        $this->assertSame(PaymentState::Failed, Payment::sole()->state);
        $this->assertSame('Invalid account_number', Payment::sole()->failure_reason);
    }

    // ── The payer comes back ──

    public function test_a_verified_success_pays_the_invoice(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create(['lot' => 'Lot 7']);
        $invoice = $this->invoiceFor($user);
        $payment = $this->payByCard($user, $invoice);

        $this->returnFromWiPay($user, $payment, $this->success($payment))
            ->assertRedirect(route('dashboard.billing'))
            ->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame(PaymentState::Paid, $payment->state);
        $this->assertSame('Paid', $invoice->fresh()->status);

        $row = $payment->ledgerPayment();
        $this->assertSame('wipay:'.self::WIPAY_TX, $row->reference);
        $this->assertSame('wipay', $row->provider);
        $this->assertSame('card', $row->payment_channel);
        $this->assertSame($payment->transaction_id, $row->transaction_id);

        // WiPay money is not Stripe's to clear.
        $accounts = LedgerEntry::with('account')->where('transaction_id', $row->id)->get()->pluck('account.code');
        $this->assertNotContains(Account::CODE_STRIPE_CLEARING, $accounts);
    }

    public function test_a_tampered_result_changes_nothing(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = $this->invoiceFor($user);
        $payment = $this->payByCard($user, $invoice);

        $this->returnFromWiPay($user, $payment, $this->success($payment, ['hash' => md5('forged')]))
            ->assertSessionHas('error');

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_a_genuine_success_for_another_payment_cannot_be_replayed_here(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = $this->invoiceFor($user);
        $payment = $this->payByCard($user, $invoice);

        // Correctly signed — but for a different WiPay transaction, and the
        // hash does not cover order_id, so only the stored id can catch it.
        $total = '100.00';
        $this->returnFromWiPay($user, $payment, $this->success($payment, [
            'transaction_id' => 'WPY-999',
            'hash' => md5('WPY-999'.$total.self::API_KEY),
        ]));

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_a_declined_card_fails_the_payment(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = $this->invoiceFor($user);
        $payment = $this->payByCard($user, $invoice);

        $this->returnFromWiPay($user, $payment, [
            'status' => 'failed',
            'transaction_id' => self::WIPAY_TX,
            'order_id' => str_replace('-', '', $payment->transaction_id),
            'message' => '[2-R2]: Transaction is declined.',
        ])->assertSessionHas('error');

        $payment->refresh();
        $this->assertSame(PaymentState::Failed, $payment->state);
        $this->assertStringContainsString('declined', $payment->failure_reason);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_returning_twice_records_the_money_once(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = $this->invoiceFor($user);
        $payment = $this->payByCard($user, $invoice);

        $this->returnFromWiPay($user, $payment, $this->success($payment));
        $this->returnFromWiPay($user, $payment, $this->success($payment));

        $this->assertSame(1, Transaction::where('status', 'completed')->count());
    }

    public function test_only_the_payer_can_bring_a_payment_back(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = $this->invoiceFor($user);
        $payment = $this->payByCard($user, $invoice);

        $this->returnFromWiPay(User::factory()->create(), $payment, $this->success($payment))->assertForbidden();

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
    }

    public function test_money_for_a_statement_already_settled_is_recorded_not_applied(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = $this->invoiceFor($user);
        $payment = $this->payByCard($user, $invoice);
        $invoice->markPaid();

        $this->returnFromWiPay($user, $payment, $this->success($payment))->assertSessionHas('info');

        $this->assertSame(PaymentState::Succeeded, $payment->fresh()->state);
        $this->assertSame(1, Transaction::where('status', 'completed')->count());
    }

    // ── Donations ──

    public function test_a_card_gift_is_written_only_once_wipay_confirms_it(): void
    {
        $donor = User::factory()->role(UserRole::Homeowner)->create();
        $fundraiser = Fundraiser::create([
            'title' => 'Playground', 'description' => 'New swings.', 'goal_minor' => 1_000_000,
            'goal_currency' => 'JMD', 'status' => 'Active', 'created_by' => $donor->id,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(),
        ]);

        $this->wipayAccepts();

        $this->actingAs($donor)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('dashboard.fundraising.donate', $fundraiser), ['amount' => 50, 'channel' => 'card', 'donor_name' => 'The Clarkes'])
            ->assertStatus(409);

        $payment = Payment::sole();
        $this->assertSame(0, Donation::count());

        $this->returnFromWiPay($donor, $payment, $this->success($payment))->assertRedirect(route('dashboard.fundraising'));

        $donation = Donation::sole();
        $this->assertSame('completed', $donation->status);
        $this->assertSame('The Clarkes', $donation->donor_name);
        $this->assertSame(5_000, $fundraiser->fresh()->raisedMinor());
    }
}
