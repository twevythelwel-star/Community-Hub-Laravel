<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\Invoice;
use App\Models\PaymentChannelSetting;
use App\Models\PaymentPlan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingEngineExpansionTest extends TestCase
{
    use RefreshDatabase;

    private function sysAdmin(): User
    {
        return User::factory()->role(UserRole::SystemAdmin)->create();
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::Admin)->create();
    }

    private function resident(): User
    {
        return User::factory()->role(UserRole::Homeowner)->create();
    }

    public function test_admin_can_validate_channel_integration(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->post('/dashboard/billing/channels/bank_wire/validate');

        $response->assertSessionHas('success');
    }

    public function test_admin_can_toggle_channel_production_availability(): void
    {
        $admin = $this->admin();

        PaymentChannelSetting::create([
            'channel_key' => 'cash_office',
            'enabled' => true,
            'display_label' => 'Cash at Office',
        ]);

        $this->actingAs($admin)
            ->post('/dashboard/billing/channels/cash_office/toggle')
            ->assertSessionHas('success');

        $this->assertFalse(PaymentChannelSetting::where('channel_key', 'cash_office')->first()->enabled);
    }

    public function test_admin_can_configure_installment_payment_plan(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();

        $invoice = Invoice::create([
            'user_id' => $resident->id,
            'reference' => 'INV-PLAN-TEST',
            'amount_minor' => 1200000,
            'currency' => 'JMD',
            'status' => 'Pending',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
        ]);

        $response = $this->actingAs($admin)
            ->post('/dashboard/billing/payment-plans', [
                'invoice_id' => $invoice->id,
                'total_installments' => 4,
                'frequency' => 'monthly',
            ]);

        $response->assertSessionHas('success');

        $plan = PaymentPlan::firstWhere('invoice_id', $invoice->id);
        $this->assertNotNull($plan);
        $this->assertSame(4, $plan->total_installments);
        $this->assertSame(300000, $plan->installment_amount_minor);
        $this->assertSame('Active', $plan->status);
    }

    public function test_user_can_download_their_own_transaction_receipt(): void
    {
        Community::default();
        $resident = $this->resident();

        $tx = Transaction::create([
            'user_id' => $resident->id,
            'amount_minor' => 2500000,
            'currency' => 'JMD',
            'payment_channel' => 'bank_wire',
            'reference' => 'REC-TX-1001',
            'receipt_number' => 'REC-2026-1001',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($resident)
            ->get("/dashboard/billing/transactions/{$tx->id}/receipt");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_user_cannot_download_neighbor_transaction_receipt(): void
    {
        Community::default();
        $resident = $this->resident();
        $neighbor = $this->resident();

        $tx = Transaction::create([
            'user_id' => $neighbor->id,
            'amount_minor' => 2500000,
            'currency' => 'JMD',
            'payment_channel' => 'bank_wire',
            'reference' => 'REC-TX-NEIGHBOR',
            'receipt_number' => 'REC-2026-NEIGHBOR',
            'status' => 'completed',
        ]);

        $this->actingAs($resident)
            ->get("/dashboard/billing/transactions/{$tx->id}/receipt")
            ->assertForbidden();
    }
}
