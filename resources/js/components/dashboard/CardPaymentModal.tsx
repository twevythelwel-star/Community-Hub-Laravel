import React, { useState, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { CreditCard, Lock, AlertCircle, ExternalLink } from 'lucide-react';

export interface CardPaymentModalProps {
  isOpen: boolean;
  onClose: () => void;
  amount: number;
  currency?: string;
  invoiceId?: number;
  invoiceReference?: string;
}

type PaymentStage = 'INPUT' | 'REDIRECTING' | 'FAILED';

/**
 * Card payment for a statement.
 *
 * Card details are entered on Stripe's own hosted Checkout page, never here:
 * this asks the server for a Checkout Session and sends the browser to it.
 * The statement is settled only once Stripe reports the money, so there is
 * no "approved" state to show on this side of the redirect.
 */
export function CardPaymentModal({
  isOpen,
  onClose,
  amount,
  currency = 'JMD',
  invoiceId,
  invoiceReference,
}: CardPaymentModalProps) {
  const [stage, setStage] = useState<PaymentStage>('INPUT');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const formattedAmount = `${currency} $${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;

  useEffect(() => {
    if (isOpen) {
      setStage('INPUT');
      setErrorMessage(null);
    }
  }, [isOpen]);

  const handlePay = async () => {
    setErrorMessage(null);
    setStage('REDIRECTING');

    try {
      const res = await (window as any).axios.post('/dashboard/billing/card-pay', {
        invoice_id: invoiceId,
        amount,
      });

      const checkoutUrl: string | undefined = res.data?.checkout_url;
      if (!checkoutUrl) {
        throw new Error('No checkout URL returned.');
      }

      window.location.assign(checkoutUrl);
    } catch (err: any) {
      setStage('FAILED');
      setErrorMessage(err?.response?.data?.message || 'Card checkout could not be started. Please try again shortly.');
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-md">
        <div className="flex items-center gap-2.5">
          <div className="w-9 h-9 rounded-xl bg-primary/20 text-primary flex items-center justify-center">
            <CreditCard className="w-5 h-5" />
          </div>
          <div>
            <DialogTitle className="text-lg font-bold font-headline">Pay by card</DialogTitle>
            <DialogDescription className="text-xs text-muted-foreground">
              You'll enter your card details on Stripe's secure checkout page.
            </DialogDescription>
          </div>
        </div>

        {stage !== 'FAILED' && (
          <div className="space-y-4">
            <div className="p-3 bg-muted/40 rounded-xl border border-border/60 space-y-1.5 text-xs">
              {invoiceReference && (
                <div className="flex justify-between items-center text-muted-foreground">
                  <span>Statement</span>
                  <span className="font-mono font-semibold text-foreground">{invoiceReference}</span>
                </div>
              )}
              <div className="flex justify-between items-center font-bold text-foreground">
                <span>Amount</span>
                <span className="text-sm text-primary font-black">{formattedAmount}</span>
              </div>
            </div>

            <Button
              onClick={handlePay}
              disabled={stage === 'REDIRECTING'}
              className="w-full h-11 text-sm font-bold gap-2"
            >
              {stage === 'REDIRECTING' ? (
                <>
                  <Lock className="w-4 h-4" /> Opening secure checkout…
                </>
              ) : (
                <>
                  <ExternalLink className="w-4 h-4" /> Continue to secure checkout
                </>
              )}
            </Button>
          </div>
        )}

        {stage === 'FAILED' && (
          <div className="space-y-4 text-center py-2">
            <div className="w-12 h-12 rounded-full bg-destructive/10 text-destructive flex items-center justify-center mx-auto">
              <AlertCircle className="w-7 h-7" />
            </div>
            <p className="text-sm text-muted-foreground">{errorMessage}</p>
            <div className="flex gap-2">
              <Button variant="outline" onClick={onClose} className="flex-1">
                Close
              </Button>
              <Button onClick={() => setStage('INPUT')} className="flex-1 font-bold">
                Try again
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
