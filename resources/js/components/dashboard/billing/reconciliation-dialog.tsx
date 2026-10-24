import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useToast } from '@/hooks/use-toast';
import { Scale } from 'lucide-react';
import type { OfficePayment } from '@/components/dashboard/transactions-ledger';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  currency: string;
  /** Office payments in flight; the ones received but not yet verified can be ticked off here. */
  pendingPayments: OfficePayment[];
};

/** Records a bank statement against the ledger, verifying the office payments it shows. */
export function ReconciliationDialog({ open, onOpenChange, currency, pendingPayments }: Props) {
  const { toast } = useToast();
  const [reconDate, setReconDate] = useState<string>(new Date().toISOString().split('T')[0]);
  const [reconBalance, setReconBalance] = useState<string>('');
  const [reconNotes, setReconNotes] = useState<string>('');
  const [reconVerifyIds, setReconVerifyIds] = useState<number[]>([]);
  const [reconError, setReconError] = useState<string | null>(null);
  const [isSubmittingRecon, setIsSubmittingRecon] = useState(false);
  const receivedPayments = pendingPayments.filter((p) => p.state === 'received');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!reconDate || !reconBalance) return;

    setIsSubmittingRecon(true);
    router.post(
      route('dashboard.billing.reconciliations.store'),
      {
        bank_statement_date: reconDate,
        statement_balance: parseFloat(reconBalance),
        notes: reconNotes,
        verify_payment_ids: reconVerifyIds,
      },
      {
        preserveScroll: true,
        onFinish: () => setIsSubmittingRecon(false),
        onSuccess: () => {
          onOpenChange(false);
          setReconBalance('');
          setReconNotes('');
          setReconVerifyIds([]);
          setReconError(null);
          toast({
            title: 'Bank Reconciliation Recorded',
            description: 'Variance verification against master transaction ledger completed.',
          });
        },
        onError: (errors) => {
          setReconError((Object.values(errors)[0] as string) ?? null);
          toast({
            variant: 'destructive',
            title: 'Reconciliation Failed',
            description: (Object.values(errors)[0] as string) || 'Could not record reconciliation.',
          });
        },
      }
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <div className="flex items-center gap-2 mb-1">
              <span className="p-1.5 rounded-lg bg-violet-500/10 text-violet-600">
                <Scale className="h-5 w-5" />
              </span>
              <DialogTitle className="text-lg font-bold">New Bank Statement Reconciliation</DialogTitle>
            </div>
            <DialogDescription className="text-xs">
              Compare external bank account ending balance against the general ledger to verify zero financial variance.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-3">
            <div className="space-y-1.5">
              <Label htmlFor="recon-date" className="text-xs font-semibold">Statement Cut-Off Date</Label>
              <Input
                id="recon-date"
                type="date"
                value={reconDate}
                onChange={(e) => setReconDate(e.target.value)}
                className="text-xs font-mono"
                required
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="recon-balance" className="text-xs font-semibold">
                Official Statement Ending Balance ({currency})
              </Label>
              <Input
                id="recon-balance"
                type="number"
                step="0.01"
                min="0"
                placeholder="e.g. 18450.00"
                value={reconBalance}
                onChange={(e) => setReconBalance(e.target.value)}
                className="text-xs font-mono"
                required
              />
            </div>

            {receivedPayments.length > 0 && (
              <fieldset className="space-y-2 rounded-lg border border-border p-3">
                <legend className="px-1 text-xs font-semibold">Verify office payments on this statement</legend>
                <p className="text-[11px] text-muted-foreground">
                  Tick each payment you can see on the statement. It is applied to its statement or campaign, and
                  counted in this reconciliation. You cannot verify a payment you logged as received yourself.
                </p>
                <ul className="space-y-1.5">
                  {receivedPayments.map((p) => (
                    <li key={p.id}>
                      <label className={`flex items-start gap-2 text-xs ${p.canVerify ? '' : 'opacity-60'}`}>
                        <input
                          type="checkbox"
                          className="mt-0.5"
                          disabled={!p.canVerify}
                          checked={reconVerifyIds.includes(p.id)}
                          onChange={(e) =>
                            setReconVerifyIds((ids) => (e.target.checked ? [...ids, p.id] : ids.filter((id) => id !== p.id)))
                          }
                        />
                        <span>
                          <span className="font-mono font-semibold">{p.transactionId}</span>{' '}
                          {p.currency} {p.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })} · {p.paymentMethod} ·{' '}
                          {p.homeowner ?? 'Unknown payer'}
                          {p.bankReference ? ` · bank ref ${p.bankReference}` : ''}
                          <span className="block text-[10px] text-muted-foreground">
                            {p.canVerify
                              ? `Received by ${p.receivedBy ?? 'another administrator'} on ${p.receivedAt ?? '—'}`
                              : 'You logged this as received; another administrator must verify it.'}
                          </span>
                        </span>
                      </label>
                    </li>
                  ))}
                </ul>
              </fieldset>
            )}

            {reconError && (
              <p role="alert" className="text-xs text-destructive">{reconError}</p>
            )}

            <div className="space-y-1.5">
              <Label htmlFor="recon-notes" className="text-xs font-semibold">Statement Reference / Audit Notes (Optional)</Label>
              <Input
                id="recon-notes"
                placeholder="e.g. NCB Monthly Operating Account #209485"
                value={reconNotes}
                onChange={(e) => setReconNotes(e.target.value)}
                className="text-xs"
              />
            </div>
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" size="sm" disabled={isSubmittingRecon || !reconBalance} className="bg-violet-600 hover:bg-violet-700 text-white gap-1.5">
              <Scale className="h-4 w-4" />
              <span>{isSubmittingRecon ? 'Auditing Ledger...' : 'Run Audit'}</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
