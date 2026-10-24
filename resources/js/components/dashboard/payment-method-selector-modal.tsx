import React, { useState, useMemo } from 'react';
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
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { useToast } from '@/hooks/use-toast';
import {
  CreditCard,
  Landmark,
  QrCode,
  Banknote,
  Smartphone,
  Wallet as WalletIcon,
  CheckCircle2,
  ArrowRight,
  ShieldCheck,
  AlertCircle,
  Clock,
  Sparkles,
  Info,
  Check,
  Copy,
  Receipt,
  Loader2,
} from 'lucide-react';
import { CardPaymentModal } from './CardPaymentModal';
import { channelSurcharge, type PaymentChannel } from '@/lib/payment-channels';
import type { InvoiceRow } from './billing-summary';

export type PaymentMethodSelectorModalProps = {
  isOpen?: boolean;
  open?: boolean;
  onClose?: () => void;
  onOpenChange?: (open: boolean) => void;
  invoice?: any;
  amountDue?: number;
  currency?: string;
  availableChannels?: any[];
  walletBalance?: number;
  onSuccess?: () => void;
};

type SlipData = {
  transaction_id: string;
  public_transaction_id?: string;
  amount: number | string;
  currency: string;
  payment_method: string;
  status: string;
  invoice?: string;
  instructions?: string;
  bank_details?: {
    bank_name: string;
    account_number: string;
    account_name: string;
    branch: string;
  };
  cash_receipt_reference?: string;
  qr_code_url?: string;
  created?: string;
};

export function PaymentMethodSelectorModal({
  isOpen,
  open,
  onClose,
  onOpenChange,
  invoice,
  amountDue = 0,
  currency = 'JMD',
  availableChannels = [],
  walletBalance = 0,
  onSuccess,
}: PaymentMethodSelectorModalProps) {
  const { toast } = useToast();
  const [selectedChannelKey, setSelectedChannelKey] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [confirmedSlip, setConfirmedSlip] = useState<SlipData | null>(null);
  const [copiedRef, setCopiedRef] = useState(false);
  const [cardModalOpen, setCardModalOpen] = useState(false);

  const modalOpen = Boolean(isOpen ?? open);
  const handleClose = () => {
    if (onClose) onClose();
    if (onOpenChange) onOpenChange(false);
  };

  const targetAmount = Number(
    invoice?.amount ?? (invoice?.amountMinor ? invoice.amountMinor / 100 : amountDue)
  ) || 0;
  const targetCurrency = invoice?.currency || currency || 'JMD';
  const targetReference = invoice?.reference || invoice?.invoiceNumber || 'Monthly Assessment';

  // Standard channels fallback if none provided
  const configuredChannels = useMemo(() => {
    if (availableChannels && availableChannels.length > 0) {
      return availableChannels;
    }
    return [
      { key: 'card', label: 'Credit / Debit Card', integration_mode: 'HOSTED_CHECKOUT', provider: 'Stripe / WiPay', fee_surcharge_percent: 0 },
      { key: 'apple_pay', label: 'Apple Pay', integration_mode: 'WEBHOOK', provider: 'Apple Device Wallet', fee_surcharge_percent: 0 },
      { key: 'google_pay', label: 'Google Wallet / Pay', integration_mode: 'WEBHOOK', provider: 'Google Wallet', fee_surcharge_percent: 0 },
      { key: 'samsung_wallet', label: 'Samsung Wallet', integration_mode: 'WEBHOOK', provider: 'Samsung Pay', fee_surcharge_percent: 0 },
      { key: 'bank_wire', label: 'Direct Bank Transfer', integration_mode: 'BANK_RECONCILIATION', provider: 'NCB / Scotiabank', fee_surcharge_percent: 0 },
      { key: 'qr_code', label: 'Scan & Pay QR Code', integration_mode: 'HOSTED_CHECKOUT', provider: 'Lynx / Mobile Banking', fee_surcharge_percent: 0 },
      { key: 'cash_office', label: 'Cash at Community Office', integration_mode: 'MANUAL_VERIFICATION', provider: 'Administration Office', fee_surcharge_percent: 0 },
      { key: 'zelle', label: 'Zelle', integration_mode: 'BANK_RECONCILIATION', provider: 'Bank Transfer', fee_surcharge_percent: 0 },
      { key: 'cash_app', label: 'Cash App', integration_mode: 'BANK_RECONCILIATION', provider: 'Bank Transfer', fee_surcharge_percent: 0 },
      { key: 'wallet', label: 'Community Wallet', integration_mode: 'API', provider: 'Prepaid Balance', fee_surcharge_percent: 0 },
    ];
  }, [availableChannels]);

  // Selected channel details
  const selectedChannel = useMemo(() => {
    return configuredChannels.find((c) => c.key === selectedChannelKey) || null;
  }, [configuredChannels, selectedChannelKey]);

  // Calculate surcharge fee
  const surchargePercent = selectedChannel?.fee_surcharge_percent ?? 0;
  const surchargeAmount = surchargePercent > 0 ? Math.round((targetAmount * (surchargePercent / 100)) * 100) / 100 : 0;
  const finalTotal = Math.round((targetAmount + surchargeAmount) * 100) / 100;

  const handleSelectChannel = (key: string) => {
    setSelectedChannelKey(key);
  };

  const handleCopy = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedRef(true);
    setTimeout(() => setCopiedRef(false), 2000);
    toast({
      title: 'Reference Copied',
      description: `Copied "${text}" to clipboard.`,
    });
  };

  const handleProceed = async () => {
    if (!selectedChannelKey) {
      toast({
        variant: 'destructive',
        title: 'Payment Method Required',
        description: 'Please select a payment method before proceeding.',
      });
      return;
    }

    setIsSubmitting(true);

    try {
      // 1. If Card -> Open dedicated interactive card payment modal
      if (selectedChannelKey === 'card') {
        setIsSubmitting(false);
        setCardModalOpen(true);
        return;
      }

      // 2. If Apple Pay / Google Wallet / Samsung Wallet -> Use the standard checkout / pay endpoint
      if (
        selectedChannelKey === 'apple_pay' ||
        selectedChannelKey === 'google_pay' ||
        selectedChannelKey === 'samsung_wallet'
      ) {
        router.post(
          '/dashboard/billing/pay',
          {
            channel: selectedChannelKey,
            amount: finalTotal,
            currency: targetCurrency,
            invoice_id: invoice?.id,
            payer_reference: targetReference,
          },
          {
            preserveScroll: true,
            onError: (errs) => {
              setIsSubmitting(false);
              toast({
                variant: 'destructive',
                title: 'Checkout Error',
                description: (Object.values(errs)[0] as string) || 'Could not initialize card checkout.',
              });
            },
          }
        );
        return;
      }

      // 2. For Bank Wire, Cash, QR Code, Zelle, Cash App, Wallet -> Initiate payment slip
      const res = await window.axios.post('/dashboard/billing/transactions/initiate', {
        channel: selectedChannelKey,
        amount: finalTotal,
        currency: targetCurrency,
        invoice_id: invoice?.id,
        purpose: 'HOA Assessment',
      });

      const slip = res.data?.slip ?? res.data?.transaction;
      setConfirmedSlip(slip);
      setIsSubmitting(false);

      toast({
        title: 'Payment Request Registered',
        description: `Reference #${slip.transaction_id} generated. Please complete according to instructions.`,
      });

      if (onSuccess) {
        onSuccess();
      }
    } catch (e: any) {
      setIsSubmitting(false);
      toast({
        variant: 'destructive',
        title: 'Payment Initialization Failed',
        description: e?.response?.data?.message || 'Could not initiate payment. Please try again.',
      });
    }
  };

  const getChannelIcon = (key: string) => {
    switch (key) {
      case 'card':
        return <CreditCard className="w-5 h-5 text-indigo-600 dark:text-indigo-400" />;
      case 'apple_pay':
        return <span className="font-black text-base tracking-tighter text-slate-900 dark:text-white px-0.5"></span>;
      case 'google_pay':
        return <span className="font-black text-xs text-blue-600 dark:text-blue-400">G Pay</span>;
      case 'samsung_wallet':
        return <span className="font-extrabold text-[10px] text-blue-800 dark:text-blue-300">Samsung</span>;
      case 'bank_wire':
        return <Landmark className="w-5 h-5 text-emerald-600 dark:text-emerald-400" />;
      case 'qr_code':
        return <QrCode className="w-5 h-5 text-purple-600 dark:text-purple-400" />;
      case 'cash_office':
        return <Banknote className="w-5 h-5 text-amber-600 dark:text-amber-400" />;
      case 'zelle':
      case 'cash_app':
        return <Smartphone className="w-5 h-5 text-cyan-600 dark:text-cyan-400" />;
      case 'wallet':
        return <WalletIcon className="w-5 h-5 text-blue-600 dark:text-blue-400" />;
      default:
        return <CreditCard className="w-5 h-5 text-slate-600" />;
    }
  };

  const getChannelTimingBadge = (mode?: string, key?: string) => {
    if (key === 'apple_pay' || key === 'google_pay' || key === 'samsung_wallet') {
      return <Badge variant="outline" className="bg-purple-500/10 text-purple-600 border-purple-500/30 text-[10px]">1-Tap Biometric</Badge>;
    }
    if (key === 'wallet') {
      return <Badge variant="outline" className="bg-emerald-500/10 text-emerald-600 border-emerald-500/30 text-[10px]">Instant 1-Click</Badge>;
    }
    if (key === 'card' || mode === 'HOSTED_CHECKOUT' || mode === 'API') {
      return <Badge variant="outline" className="bg-indigo-500/10 text-indigo-600 border-indigo-500/30 text-[10px]">Instant Online</Badge>;
    }
    if (key === 'cash_office' || mode === 'MANUAL_VERIFICATION') {
      return <Badge variant="outline" className="bg-amber-500/10 text-amber-600 border-amber-500/30 text-[10px]">Office Receipt</Badge>;
    }
    return <Badge variant="outline" className="bg-blue-500/10 text-blue-600 border-blue-500/30 text-[10px]">Bank Reconciliation</Badge>;
  };

  const getChannelDescription = (key: string) => {
    switch (key) {
      case 'card':
        return 'Pay with Visa, MasterCard, or Keycard via secure PCI-DSS checkout';
      case 'apple_pay':
        return '1-tap biometric checkout using Touch ID or Face ID on Apple devices';
      case 'google_pay':
        return 'Fast, seamless checkout with cards saved in your Google Wallet';
      case 'samsung_wallet':
        return 'Knox hardware-secured contactless payment with Samsung Wallet';
      case 'bank_wire':
        return 'Online bank transfer via National Commercial Bank (NCB) or Scotia';
      case 'qr_code':
        return 'Scan with Lynx, NCB Pay, or banking app for instant phone settlement';
      case 'cash_office':
        return 'Pay in-person at the community management office (official receipt issued)';
      case 'zelle':
        return 'Direct mobile payment with automatic statement reconciliation';
      case 'cash_app':
        return 'Send to community cashtag with transaction memo';
      case 'wallet':
        return `Deduct from your prepaid wallet balance (${targetCurrency} ${walletBalance.toLocaleString(undefined, { minimumFractionDigits: 2 })} available)`;
      default:
        return 'Authorized community payment rail';
    }
  };

  return (
    <>
      <Dialog open={modalOpen && !cardModalOpen} onOpenChange={(v) => !v && handleClose()}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto p-6 sm:p-7">
        {!confirmedSlip ? (
          <>
            <DialogHeader className="space-y-1 text-left pb-2 border-b">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                  <ShieldCheck className="w-4 h-4 text-emerald-600" />
                  Secure Assessment Settlement
                </span>
                <Badge variant="outline" className="font-mono text-xs">
                  {targetReference}
                </Badge>
              </div>
              <DialogTitle className="text-2xl font-bold tracking-tight">
                Select Payment Method
              </DialogTitle>
              <DialogDescription className="text-xs text-muted-foreground">
                Choose how you would like to settle this assessment before proceeding with payment.
              </DialogDescription>
            </DialogHeader>

            {/* Assessment Statement Summary Card */}
            <div className="bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 rounded-xl p-4 flex items-center justify-between">
              <div>
                <span className="text-xs font-medium text-muted-foreground block">
                  Amount to Pay
                </span>
                <div className="text-2xl sm:text-3xl font-black text-foreground tabular-nums">
                  {targetCurrency} {targetAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                </div>
              </div>
              <div className="text-right">
                <span className="text-xs text-muted-foreground block">Dues Reference</span>
                <span className="text-xs font-mono font-bold text-foreground">{targetReference}</span>
              </div>
            </div>

            {/* Payment Method Selection Grid */}
            <div className="space-y-3">
              <label className="text-xs font-bold uppercase tracking-wider text-muted-foreground block">
                Available Payment Options
              </label>

              <div className="grid gap-2.5 sm:grid-cols-2">
                {configuredChannels.map((channel: any) => {
                  const isSelected = selectedChannelKey === channel.key;
                  const isWalletDisabled = channel.key === 'wallet' && walletBalance < targetAmount;

                  return (
                    <div
                      key={channel.key}
                      onClick={() => !isWalletDisabled && handleSelectChannel(channel.key)}
                      className={`relative flex flex-col justify-between p-3.5 rounded-xl border transition-all cursor-pointer select-none ${
                        isWalletDisabled
                          ? 'opacity-50 cursor-not-allowed border-slate-200 dark:border-slate-800 bg-muted/30'
                          : isSelected
                          ? 'border-emerald-600 bg-emerald-50/50 dark:bg-emerald-950/20 shadow-sm ring-1 ring-emerald-600'
                          : 'border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 hover:bg-slate-50/50 dark:hover:bg-slate-900/30'
                      }`}
                    >
                      <div className="space-y-2">
                        <div className="flex items-center justify-between">
                          <div className="p-2 rounded-lg bg-white dark:bg-slate-800 shadow-sm border border-slate-100 dark:border-slate-700">
                            {getChannelIcon(channel.key)}
                          </div>
                          <div className="flex items-center gap-1.5">
                            {getChannelTimingBadge(channel.integration_mode, channel.key)}
                            {isSelected && (
                              <div className="w-5 h-5 rounded-full bg-emerald-600 text-white flex items-center justify-center">
                                <Check className="w-3 h-3 stroke-[3]" />
                              </div>
                            )}
                          </div>
                        </div>

                        <div>
                          <div className="text-sm font-bold text-foreground">
                            {channel.label || channel.display_label}
                          </div>
                          <div className="text-xs text-muted-foreground mt-0.5 line-clamp-2">
                            {getChannelDescription(channel.key)}
                          </div>
                        </div>
                      </div>

                      {/* Fee indicator if any */}
                      <div className="mt-3 pt-2 border-t border-slate-200/60 dark:border-slate-800/60 flex items-center justify-between text-[11px]">
                        <span className="text-muted-foreground">Processing Fee</span>
                        <span className="font-semibold text-foreground">
                          {channel.fee_surcharge_percent > 0 ? `+${channel.fee_surcharge_percent}%` : 'Free (0%)'}
                        </span>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Selected Method Details Panel */}
            {selectedChannel && (
              <div className="p-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-900/70 space-y-3">
                <div className="flex items-center gap-2 text-xs font-bold text-foreground">
                  <Info className="w-4 h-4 text-emerald-600" />
                  <span>Payment Process Overview: {selectedChannel.label}</span>
                </div>

                <div className="text-xs text-muted-foreground leading-relaxed">
                  {selectedChannel.key === 'card' && (
                    <p>
                      You will be securely redirected to our PCI-DSS certified payment processor to enter your card details. Your invoice shows as <strong className="text-foreground">Paid</strong> once the processor confirms the payment to us, usually within moments.
                    </p>
                  )}
                  {selectedChannel.key === 'apple_pay' && (
                    <p>
                      You will authorize this assessment payment instantly using <strong className="text-foreground">Apple Pay (Touch ID / Face ID)</strong> on your Apple device. Payment settles through tokenized card rails with immediate confirmation.
                    </p>
                  )}
                  {selectedChannel.key === 'google_pay' && (
                    <p>
                      You will authenticate using your saved credentials in <strong className="text-foreground">Google Wallet / Google Pay</strong>. Instant payment processing with zero manual card number entry.
                    </p>
                  )}
                  {selectedChannel.key === 'samsung_wallet' && (
                    <p>
                      Authenticate using <strong className="text-foreground">Samsung Wallet</strong> secured by defense-grade Samsung Knox hardware biometrics.
                    </p>
                  )}
                  {selectedChannel.key === 'bank_wire' && (
                    <p>
                      You will receive a payment slip with the estate's bank details and your reference number (<strong className="text-foreground">{targetReference}</strong>). Once you transfer, the office confirms the money has arrived before the invoice shows as paid.
                    </p>
                  )}
                  {selectedChannel.key === 'qr_code' && (
                    <p>
                      A dynamic QR code encoded with your exact invoice amount and reference will be generated. Scan it with your phone to pay; the office confirms the payment once it arrives.
                    </p>
                  )}
                  {selectedChannel.key === 'cash_office' && (
                    <p>
                      Bring your reference number to the community management office and pay the cashier, who gives you a stamped receipt.
                    </p>
                  )}
                  {(selectedChannel.key === 'zelle' || selectedChannel.key === 'cash_app') && (
                    <p>
                      Transfer funds to the verified community account handle. Please include <strong className="text-foreground">{targetReference}</strong> in the transfer note/memo so our end-of-day reconciliation engine matches your payment.
                    </p>
                  )}
                  {selectedChannel.key === 'wallet' && (
                    <p>
                      Your community prepaid wallet balance will be debited by <strong className="text-foreground">{targetCurrency} {finalTotal.toLocaleString(undefined, { minimumFractionDigits: 2 })}</strong>, settling your dues immediately with zero processing fees.
                    </p>
                  )}
                </div>

                {/* Price Breakdown */}
                {surchargeAmount > 0 && (
                  <div className="pt-2 border-t border-slate-200 dark:border-slate-800 flex justify-between text-xs">
                    <span className="text-muted-foreground">Surcharge ({surchargePercent}%):</span>
                    <span className="font-semibold text-foreground">{targetCurrency} {surchargeAmount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                  </div>
                )}
              </div>
            )}

            <DialogFooter className="pt-3 border-t flex flex-col sm:flex-row items-center justify-between gap-3">
              <div className="text-left w-full sm:w-auto">
                <span className="text-xs text-muted-foreground block">Total Payable</span>
                <span className="text-xl font-black text-foreground tabular-nums">
                  {targetCurrency} {finalTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                </span>
              </div>

              <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
                <Button variant="outline" onClick={handleClose} disabled={isSubmitting}>
                  Cancel
                </Button>
                <Button
                  onClick={handleProceed}
                  disabled={!selectedChannelKey || isSubmitting}
                  className="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-5 gap-1.5 shadow-sm"
                >
                  {isSubmitting ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      <span>Processing...</span>
                    </>
                  ) : (
                    <>
                      <span>Proceed to Payment</span>
                      <ArrowRight className="w-4 h-4" />
                    </>
                  )}
                </Button>
              </div>
            </DialogFooter>
          </>
        ) : (
          /* Confirmation Slip & Instructions View */
          <div className="space-y-6 text-left">
            <div className="p-4 bg-emerald-500/10 border border-emerald-500/30 rounded-xl flex items-center gap-3">
              <CheckCircle2 className="w-8 h-8 text-emerald-600 shrink-0" />
              <div>
                <h4 className="font-bold text-emerald-950 dark:text-emerald-100 text-sm">
                  Payment Request Initiated Successfully
                </h4>
                <p className="text-xs text-emerald-800 dark:text-emerald-300 mt-0.5">
                  Your reference slip has been created and logged in CommunityHub.
                </p>
              </div>
            </div>

            {/* Official Slip Details */}
            <div className="border rounded-xl p-5 bg-card space-y-4">
              <div className="flex items-center justify-between border-b pb-3">
                <div>
                  <span className="text-[11px] uppercase tracking-wider text-muted-foreground font-semibold">
                    Transaction ID
                  </span>
                  <div className="font-mono font-bold text-base text-foreground flex items-center gap-2">
                    <span>{confirmedSlip.transaction_id}</span>
                    <button
                      type="button"
                      onClick={() => handleCopy(confirmedSlip.transaction_id)}
                      className="p-1 hover:bg-muted rounded text-muted-foreground hover:text-foreground"
                      title="Copy Transaction ID"
                    >
                      {copiedRef ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                    </button>
                  </div>
                </div>
                <Badge variant="outline" className="bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/30">
                  {confirmedSlip.status || 'Pending Confirmation'}
                </Badge>
              </div>

              <div className="grid grid-cols-2 gap-4 text-xs">
                <div>
                  <span className="text-muted-foreground block">Method Selected</span>
                  <span className="font-bold text-foreground">{confirmedSlip.payment_method}</span>
                </div>
                <div>
                  <span className="text-muted-foreground block">Amount</span>
                  <span className="font-bold text-foreground">
                    {confirmedSlip.currency} {Number(confirmedSlip.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                  </span>
                </div>
                <div>
                  <span className="text-muted-foreground block">Invoice Reference</span>
                  <span className="font-mono font-semibold text-foreground">{confirmedSlip.invoice || targetReference}</span>
                </div>
                <div>
                  <span className="text-muted-foreground block">Issued At</span>
                  <span className="text-foreground">{new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                </div>
              </div>

              {/* Instructions Specific to Rail */}
              <div className="p-3.5 rounded-lg bg-muted/50 border text-xs space-y-1.5">
                <span className="font-bold text-foreground block">Next Steps:</span>
                {/* The estate's own account details come from its channel settings once
                    the payment is submitted; none are written into this page. */}
                <p className="text-muted-foreground">
                  Submit this payment to get the estate's payment instructions. Use{' '}
                  <strong className="text-foreground">{confirmedSlip.transaction_id}</strong> as your reference. It counts
                  once the office has confirmed the money arrived.
                </p>
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <Button
                variant="outline"
                onClick={() => {
                  setConfirmedSlip(null);
                  handleClose();
                }}
              >
                Close
              </Button>
              <Button
                disabled={isSubmitting}
                onClick={() => {
                  setIsSubmitting(true);
                  router.post(
                    '/dashboard/billing/pay',
                    {
                      channel: selectedChannelKey,
                      amount: finalTotal,
                      currency: targetCurrency,
                      invoice_id: invoice?.id,
                      // Submit the payment on the slip, so its number is the one that settles.
                      transaction_id: confirmedSlip.transaction_id,
                    },
                    {
                      preserveScroll: true,
                      onSuccess: (page) => {
                        setIsSubmitting(false);
                        setConfirmedSlip(null);
                        handleClose();
                        const message = (page.props as { flash?: { success?: string | null } }).flash?.success;
                        if (message) {
                          toast({ title: 'Payment submitted', description: message });
                        }
                      },
                      onError: (errs) => {
                        setIsSubmitting(false);
                        toast({
                          variant: 'destructive',
                          title: 'Payment not submitted',
                          description: (Object.values(errs)[0] as string) || 'Please try again.',
                        });
                      },
                    }
                  );
                }}
                className="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold"
              >
                {isSubmitting ? <Loader2 className="w-4 h-4 animate-spin" /> : 'Submit payment'}
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>

    <CardPaymentModal
      isOpen={cardModalOpen}
      onClose={() => {
        setCardModalOpen(false);
      }}
      amount={finalTotal}
      currency={targetCurrency}
      invoiceId={invoice?.id}
      invoiceReference={targetReference}
    />
    </>
  );
}
