import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/hooks/use-toast';
import { RotateCcw, AlertTriangle } from 'lucide-react';

export type DonationRefundTarget = {
  id: number;
  receiptNumber?: string | null;
  donorName: string;
  fundraiserTitle: string;
  amount: number;
  currency: string;
};

type RefundDonationDialogProps = {
  donation: DonationRefundTarget | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function RefundDonationDialog({
  donation,
  open,
  onOpenChange,
}: RefundDonationDialogProps) {
  const { toast } = useToast();
  const [submitting, setSubmitting] = useState(false);
  const [reason, setReason] = useState('Resident requested refund / accidental duplicate pledge');

  if (!donation) return null;

  const handleRefund = (e: React.FormEvent) => {
    e.preventDefault();
    if (!reason.trim()) {
      toast({
        variant: 'destructive',
        title: 'Reason required',
        description: 'Please provide an audit explanation for this refund.',
      });
      return;
    }

    setSubmitting(true);

    router.post(
      `/dashboard/fundraising/donations/${donation.id}/refund`,
      { reason },
      {
        preserveScroll: true,
        onSuccess: () => {
          onOpenChange(false);
          toast({
            title: 'Donation refunded',
            description: `Successfully processed refund for receipt #${donation.receiptNumber ?? donation.id}.`,
          });
        },
        onError: (errors) => {
          toast({
            variant: 'destructive',
            title: 'Refund failed',
            description: Object.values(errors)[0] ?? 'Could not complete the refund request.',
          });
        },
        onFinish: () => setSubmitting(false),
      }
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <div className="p-2 rounded-full bg-destructive/10 text-destructive">
              <RotateCcw className="h-5 w-5" />
            </div>
            <div>
              <DialogTitle>Refund Contribution</DialogTitle>
              <DialogDescription>
                Process an official refund for this campaign donation.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <form onSubmit={handleRefund} className="space-y-4">
          <div className="rounded-lg border border-amber-200 dark:border-amber-800/60 bg-amber-50/60 dark:bg-amber-950/30 p-3 text-xs space-y-1.5">
            <div className="flex items-center gap-1.5 font-semibold text-amber-800 dark:text-amber-300">
              <AlertTriangle className="h-4 w-4" />
              <span>Financial Ledger Impact</span>
            </div>
            <p className="text-amber-700 dark:text-amber-400 leading-relaxed">
              Refunding will reverse the donation from &ldquo;{donation.fundraiserTitle}&rdquo;, decrementing the campaign net total and generating an audited refund record in the Master Transaction Ledger.
            </p>
          </div>

          <div className="bg-muted/40 p-3 rounded-md text-xs space-y-1">
            <div className="flex justify-between">
              <span className="text-muted-foreground">Receipt Number:</span>
              <span className="font-mono font-medium">{donation.receiptNumber ?? `#${donation.id}`}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Donor:</span>
              <span className="font-medium">{donation.donorName}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Refund Amount:</span>
              <span className="font-bold text-destructive">
                {donation.currency} {donation.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
              </span>
            </div>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-foreground">Refund Audit Reason</label>
            <Textarea
              rows={3}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="State reason for compliance and donor audit records..."
              required
            />
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" variant="destructive" disabled={submitting}>
              {submitting ? 'Refunding...' : 'Confirm Refund'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
