<?php

namespace App\Actions\Billing;

use App\Models\BankReconciliation;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use Illuminate\Support\Facades\DB;

class StoreReconciliationAction
{
    public function __construct(
        private readonly PaymentOrchestratorService $orchestrator,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{reconciliation: BankReconciliation, verified: int, message: string}
     */
    public function execute(array $validated, User $admin): array
    {
        $date = $validated['bank_statement_date'];
        $statementBalanceMinor = (int) round(((float) $validated['statement_balance']) * 100);

        [$reconciliation, $verified] = DB::transaction(function () use ($validated, $admin, $date, $statementBalanceMinor): array {
            $reconciliation = BankReconciliation::create([
                'bank_statement_date' => $date,
                'statement_balance_minor' => $statementBalanceMinor,
                'ledger_balance_minor' => 0,
                'difference_minor' => 0,
                'reconciled_by' => $admin->id,
                'status' => 'Discrepancy',
                'notes' => $validated['notes'] ?? null,
            ]);

            $verified = 0;
            foreach (Payment::query()->whereKey($validated['verify_payment_ids'] ?? [])->get() as $payment) {
                $this->orchestrator->verifyReceived($payment, $admin, $reconciliation);
                $verified++;
            }

            // Money received less money refunded, by the date it arrived.
            $asOf = fn ($query) => $query->whereRaw('date(coalesce(settled_at, created_at)) <= ?', [$date]);
            $completedMinor = (int) $asOf(Transaction::where('status', Transaction::STATUS_COMPLETED))->sum('amount_minor');
            $refundedMinor = (int) $asOf(Transaction::where('status', Transaction::STATUS_REFUNDED))->sum('amount_minor');

            $ledgerBalanceMinor = $completedMinor - $refundedMinor;
            $diffMinor = $statementBalanceMinor - $ledgerBalanceMinor;

            $reconciliation->update([
                'ledger_balance_minor' => $ledgerBalanceMinor,
                'difference_minor' => $diffMinor,
                'status' => $diffMinor === 0 ? 'Reconciled' : 'Discrepancy',
            ]);

            return [$reconciliation, $verified];
        });

        $statusWord = $reconciliation->status === 'Reconciled'
            ? 'balanced perfectly'
            : 'has a variance of J$'.number_format($reconciliation->difference_minor / 100, 2);
        $verifiedWord = $verified > 0 ? " {$verified} office ".($verified === 1 ? 'payment' : 'payments').' verified and applied.' : '';

        $admin->recordActivity("Completed bank reconciliation for {$date}: {$statusWord}".($verified ? "; verified {$verified} office payments" : ''));

        return [
            'reconciliation' => $reconciliation,
            'verified' => $verified,
            'message' => "Bank reconciliation recorded. Status: {$reconciliation->status} ({$statusWord}).{$verifiedWord}",
        ];
    }
}
