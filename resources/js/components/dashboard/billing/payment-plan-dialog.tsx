import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';
import { Calendar, CheckCircle2 } from 'lucide-react';
import type { OutstandingItem } from './types';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  outstanding: OutstandingItem[];
  /** The statement to put on a plan; the Outstanding tab can preselect it. */
  invoiceId: string;
  onInvoiceIdChange: (invoiceId: string) => void;
};

/** Splits an outstanding statement into scheduled installments. */
export function PaymentPlanDialog({ open, onOpenChange, outstanding, invoiceId, onInvoiceIdChange }: Props) {
  const { toast } = useToast();
  const [planInstallments, setPlanInstallments] = useState<number>(4);
  const [planFrequency, setPlanFrequency] = useState<string>('monthly');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!invoiceId) return;

    router.post(
      route('dashboard.billing.payment-plans.store'),
      {
        invoice_id: invoiceId,
        total_installments: planInstallments,
        frequency: planFrequency,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          onOpenChange(false);
          onInvoiceIdChange('');
          toast({
            title: 'Installment Plan Activated',
            description: `Created ${planInstallments}-part ${planFrequency} payment schedule.`,
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
              <span className="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-600">
                <Calendar className="h-5 w-5" />
              </span>
              <DialogTitle className="text-lg font-bold">Configure Installment Payment Plan</DialogTitle>
            </div>
            <DialogDescription className="text-xs">
              Divide an outstanding assessment statement into structured, scheduled recurring installments.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-3">
            <div className="space-y-1.5">
              <Label className="text-xs font-semibold">Select Statement / Account</Label>
              <Select value={invoiceId} onValueChange={onInvoiceIdChange}>
                <SelectTrigger className="text-xs font-mono">
                  <SelectValue placeholder="Select outstanding invoice..." />
                </SelectTrigger>
                <SelectContent>
                  {outstanding.map((out) => (
                    <SelectItem key={out.id} value={out.id.toString()} className="text-xs font-mono">
                      {out.reference} &bull; {out.homeowner} (Lot {out.lot}) &bull; {out.currency} {out.amount.toFixed(2)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Total Installments</Label>
                <Select
                  value={planInstallments.toString()}
                  onValueChange={(v) => setPlanInstallments(parseInt(v, 10))}
                >
                  <SelectTrigger className="text-xs font-mono">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="2">2 Installments</SelectItem>
                    <SelectItem value="3">3 Installments</SelectItem>
                    <SelectItem value="4">4 Installments</SelectItem>
                    <SelectItem value="6">6 Installments</SelectItem>
                    <SelectItem value="12">12 Installments</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Cadence / Frequency</Label>
                <Select value={planFrequency} onValueChange={setPlanFrequency}>
                  <SelectTrigger className="text-xs font-mono">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="monthly">Monthly</SelectItem>
                    <SelectItem value="biweekly">Bi-Weekly</SelectItem>
                    <SelectItem value="weekly">Weekly</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" size="sm" disabled={!invoiceId} className="bg-emerald-600 hover:bg-emerald-700 text-white gap-1.5">
              <CheckCircle2 className="h-4 w-4" />
              <span>Activate Schedule</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
