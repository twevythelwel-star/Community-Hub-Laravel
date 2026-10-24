import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
  CheckCircle2,
  CreditCard,
  QrCode,
  Landmark,
  Wallet as WalletIcon,
  Banknote,
  Sparkles,
  ArrowRight,
  ShieldCheck,
  Calendar,
  Split,
  Layers,
  ChevronDown,
  ChevronUp,
  Copy,
  Check,
  Loader2,
} from 'lucide-react';
import { channelSurcharge, type PaymentChannel } from '@/lib/payment-channels';
import { toast } from '@/hooks/use-toast';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import qrcode from 'qrcode-generator';
import { PaymentMethodSelectorModal } from './payment-method-selector-modal';
import { CardPaymentModal } from './CardPaymentModal';

export type TransactionSlipData = {
  transaction_id: string;
  public_transaction_id: string;
  user: string;
  property: string;
  community: string;
  purpose: string;
  invoice: string;
  amount: string | number;
  currency: string;
  status: string;
  payment_method: string;
  method: string;
  provider: string;
  provider_transaction_id?: string | null;
  state_label?: string;
  device?: string | null;
  created: string;
};

export const CURRENCY_CONFIG: Record<string, { rate: number; symbol: string; name: string; flag: string }> = {
  JMD: { rate: 1, symbol: 'J$', name: 'Jamaican Dollar', flag: '🇯🇲' },
  USD: { rate: 0.0064, symbol: '$', name: 'US Dollar', flag: '🇺🇸' },
  CAD: { rate: 0.0088, symbol: 'CA$', name: 'Canadian Dollar', flag: '🇨🇦' },
  GBP: { rate: 0.0051, symbol: '£', name: 'British Pound', flag: '🇬🇧' },
  EUR: { rate: 0.0060, symbol: '€', name: 'Euro', flag: '🇪🇺' },
};

export type ItemizedCharge = {
  id: number;
  category: string;
  title: string;
  amount: number;
  status: string;
};

export type PaymentCenterProps = {
  amountDue: number;
  currency: string;
  itemizedCharges: ItemizedCharge[];
  walletBalance: number;
  onNavigateTab?: (tab: string) => void;
  availableChannels?: any[];
  autoPay?: any;
};

/** Device wallets: offered only through a card processor that supports them. */
const WALLET_CHANNELS = ['apple_pay', 'google_pay', 'samsung_wallet'];

/** Card and device wallets are taken on the card processor's own page. */
const isProcessorChannel = (channel: string): boolean => channel === 'card' || WALLET_CHANNELS.includes(channel);

/** Who handles the money, in words; see Transaction::resolveDefaultProvider(). */
function providerLabel(provider: string): string {
  switch (provider) {
    case 'stripe':
      return 'Stripe';
    case 'wipay':
      return 'WiPay';
    case 'internal':
      return 'Community Wallet';
    case 'office':
      return 'Confirmed by the community office';
    default:
      return provider;
  }
}

export function PaymentCenterHero({
  amountDue,
  currency,
  itemizedCharges,
  walletBalance,
  onNavigateTab,
  availableChannels,
}: PaymentCenterProps) {
  /*
   * This component took `availableChannels` and then rendered every payment
   * button unconditionally, so `payment_channel_settings` had no effect here:
   * a disabled channel was still offered, a custom label never showed, and the
   * per-channel surcharge was never put in front of the payer.
   *
   * `channelEnabled` now gates each button. When the server sends no list at
   * all every button shows, which keeps older callers working.
   */
  const configuredChannels: PaymentChannel[] = availableChannels ?? [];

  // Shared by HandleInertiaRequests from ProviderRegistry: whether the estate
  // has a card processor configured, and which (Stripe, WiPay, ...).
  const paymentsProps = (usePage().props as { payments?: { cardCheckout?: boolean; cardProvider?: string | null } | null }).payments;
  const cardCheckout = Boolean(paymentsProps?.cardCheckout);
  const cardProvider = paymentsProps?.cardProvider ?? 'the card processor';

  const channelEnabled = (key: string): boolean =>
    configuredChannels.length === 0 || configuredChannels.some((c) => c.key === key);

  /**
   * The method the hero "Pay Now" button uses.
   *
   * It was hardcoded to `card`, so disabling card left the primary action
   * posting a channel the estate does not accept.
   */
  const primaryChannelKey: string =
    configuredChannels.find((c) => c.key === 'card')?.key ?? configuredChannels[0]?.key ?? 'card';

  const surchargeFor = (key: string): number => {
    const channel = configuredChannels.find((c) => c.key === key);

    return channel ? channelSurcharge(channel) : 0;
  };

  /** Rendered on a channel button when that method adds a fee. */
  const SurchargeNote = ({ channelKey }: { channelKey: string }) => {
    const surcharge = surchargeFor(channelKey);

    if (surcharge <= 0) {
      return null;
    }

    return (
      <span className="mt-1 block text-[10px] font-semibold text-amber-600 dark:text-amber-400">
        +{surcharge}% fee
      </span>
    );
  };

  const [selectedCurrency, setSelectedCurrency] = useState<string>(currency || 'JMD');
  const [showItemized, setShowItemized] = useState(false);
  const [selectedChargeIds, setSelectedChargeIds] = useState<number[]>(
    itemizedCharges.map((c) => c.id)
  );
  const [settlementMode, setSettlementMode] = useState<'full' | 'partial' | 'split' | 'plan'>('full');
  
  const toTarget = (amountInJmd: number, targetCur: string): number => {
    if (targetCur === 'JMD') return amountInJmd;
    const rate = CURRENCY_CONFIG[targetCur]?.rate ?? 1;
    return Math.round(amountInJmd * rate * 100) / 100;
  };

  const curConfig = CURRENCY_CONFIG[selectedCurrency] ?? CURRENCY_CONFIG.JMD;

  const [partialAmount, setPartialAmount] = useState<string>(
    String(toTarget(25000, selectedCurrency))
  );
  const [splitWalletAmount, setSplitWalletAmount] = useState<string>(
    walletBalance > 0 ? String(Math.min(walletBalance, 25000)) : '0'
  );
  const [activeModal, setActiveModal] = useState<string | null>(null);
  const [modalMethod, setModalMethod] = useState<string>('');
  const [isProcessing, setIsProcessing] = useState(false);
  const [pendingSlip, setPendingSlip] = useState<TransactionSlipData | null>(null);
  const [isInitiating, setIsInitiating] = useState(false);
  const [slipError, setSlipError] = useState<string | null>(null);
  const [copiedId, setCopiedId] = useState(false);
  const [isSelectorModalOpen, setIsSelectorModalOpen] = useState(false);
  const [isCardModalOpen, setIsCardModalOpen] = useState(false);

  /*
   * The scan-to-pay QR carries this payment's transaction ID, the reference
   * the office confirms it by. It was an api.qrserver.com image of a made-up
   * communityhub.org demo URL: a third party drew it, and scanning it opened
   * a site this app does not own.
   */
  const qrSlipImage = useMemo(() => {
    if (!pendingSlip?.transaction_id) {
      return null;
    }
    const qr = qrcode(0, 'M');
    qr.addData(pendingSlip.transaction_id);
    qr.make();
    return qr.createDataURL(5, 4);
  }, [pendingSlip?.transaction_id]);

  // Compute selected total
  const baseSelectedTotal = itemizedCharges.length > 0 && showItemized
    ? itemizedCharges
        .filter((c) => selectedChargeIds.includes(c.id))
        .reduce((sum, c) => sum + c.amount, 0)
    : amountDue;

  const currentPayTotal = settlementMode === 'partial'
    ? Number(partialAmount) || 0
    : toTarget(baseSelectedTotal, selectedCurrency);

  const toggleCharge = (id: number) => {
    setSelectedChargeIds((prev) =>
      prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
    );
  };

  const handleTriggerPay = async (channelKey: string, methodLabel: string) => {
    if (channelKey === 'card') {
      setIsCardModalOpen(true);
      return;
    }

    setModalMethod(methodLabel);
    setActiveModal(channelKey);
    setIsInitiating(true);
    setPendingSlip(null);
    setSlipError(null);

    try {
      // window.axios carries the CSRF token (resources/js/bootstrap.ts).
      const res = await window.axios.post('/dashboard/billing/transactions/initiate', {
        channel: channelKey,
        amount: currentPayTotal,
        currency: selectedCurrency,
        purpose: 'HOA Assessment',
      });
      setPendingSlip(res.data?.slip ?? res.data?.transaction ?? null);
    } catch (e: any) {
      /*
       * This used to show a made-up slip — CH-YYYY-0000012847, USR-000284,
       * PROP-00481 — numbers that exist nowhere. Say what went wrong instead;
       * the payment can still be submitted, and gets its number then.
       */
      setSlipError(
        e?.response?.data?.message ?? 'Could not start this payment. You can still submit it below.',
      );
    } finally {
      setIsInitiating(false);
    }
  };

  const handleConfirmPayment = (channelKey: string) => {
    setIsProcessing(true);

    router.post(
      '/dashboard/billing/pay',
      {
        channel: channelKey,
        amount: currentPayTotal,
        currency: selectedCurrency,
        // Submit the payment on the slip, so its number is the one that settles.
        transaction_id: pendingSlip?.transaction_id,
        item_ids: showItemized ? selectedChargeIds : undefined,
        split_wallet_amount: settlementMode === 'split' ? Number(splitWalletAmount) : undefined,
      },
      {
        onSuccess: (page) => {
          setIsProcessing(false);
          setActiveModal(null);

          // Say what happened: an office channel's payment is only submitted
          // until the office confirms it, and the modal closing said nothing.
          const message = (page.props as { flash?: { success?: string | null } }).flash?.success;
          if (message) {
            toast({ title: 'Payment submitted', description: message });
          }
        },
        onError: () => {
          setIsProcessing(false);
        },
      }
    );
  };

  return (
    <div className="bg-card border border-border/80 rounded-2xl shadow-xl overflow-hidden">
      {/* Top Header Gradient */}
      <div className="bg-gradient-to-r from-primary via-primary/95 to-slate-900 text-primary-foreground p-6 sm:p-8">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="text-xs uppercase tracking-widest font-extrabold text-primary-foreground/80">
                Resident Payment Center
              </span>
              {/*
                This read "Zero Surcharges", which the data contradicts:
                `payment_channel_settings.fee_surcharge_percent` is a per-channel
                surcharge that an administrator can set, and it is passed to this
                component in `availableChannels` — but never shown to the payer.
                A fee that exists in the schema, is not displayed, and is denied
                in a badge is the definition of a hidden fee. Removed rather than
                restated, because until each channel renders its own surcharge
                next to its button, no claim either way is safe to make.
              */}
            </div>
            <p className="text-xs text-primary-foreground/70 mt-1">
              Official community assessment & dues settlement portal
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            {/* Currency Switcher Pills */}
            <div className="flex items-center gap-1 bg-black/35 backdrop-blur-md p-1 rounded-xl border border-white/10">
              <span className="text-[10px] uppercase font-bold text-white/70 px-1.5 hidden sm:inline">
                Currency:
              </span>
              {Object.entries(CURRENCY_CONFIG).map(([code, conf]) => (
                <button
                  key={code}
                  type="button"
                  onClick={() => {
                    setSelectedCurrency(code);
                    if (settlementMode === 'partial') {
                      setPartialAmount(String(toTarget(25000, code)));
                    }
                  }}
                  className={`px-2 py-1 rounded-lg text-xs font-bold transition-all flex items-center gap-1 ${
                    selectedCurrency === code
                      ? 'bg-white text-slate-950 shadow-sm scale-105'
                      : 'text-white/80 hover:text-white hover:bg-white/15'
                  }`}
                  title={conf.name}
                >
                  <span className="text-xs">{conf.flag}</span>
                  <span>{code}</span>
                </button>
              ))}
            </div>

            <div className="flex items-center gap-2 text-xs bg-black/20 backdrop-blur-md px-3 py-1.5 rounded-full self-start">
              <Calendar className="w-3.5 h-3.5 text-primary-foreground/80" />
              <span>Due: 1st of month</span>
            </div>
          </div>
        </div>

        {/* Hero Amount Box */}
        <div className="mt-6 flex flex-col sm:flex-row sm:items-end justify-between gap-6 border-t border-white/10 pt-6">
          <div>
            <div className="flex items-center gap-2">
              <span className="text-xs uppercase tracking-wider font-semibold text-primary-foreground/75">
                Total Amount Due ({curConfig.name})
              </span>
              {selectedCurrency !== 'JMD' && (
                <Badge variant="outline" className="text-[10px] text-white/90 border-white/30 bg-white/10">
                  Converted from J$ {amountDue.toLocaleString()} JMD
                </Badge>
              )}
            </div>
            <div className="text-4xl sm:text-5xl font-black tracking-tight mt-1">
              {curConfig.symbol}{Number(currentPayTotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              <span className="text-lg font-bold opacity-80 ml-2">{selectedCurrency}</span>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <Button
              size="lg"
              className="bg-white text-slate-950 hover:bg-white/90 font-bold px-6 shadow-md transition-all group"
              onClick={() => setIsSelectorModalOpen(true)}
            >
              <span>Pay Now ({curConfig.symbol}{Number(currentPayTotal).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })})</span>
              <ArrowRight className="w-4 h-4 ml-1.5 group-hover:translate-x-1 transition-transform" />
            </Button>
            <Button
              variant="outline"
              size="lg"
              className="border-white/30 bg-white/10 hover:bg-white/20 text-white font-semibold"
              onClick={() => setShowItemized(!showItemized)}
            >
              <span>{showItemized ? 'Hide Breakdown' : 'Select Charges'}</span>
              {showItemized ? <ChevronUp className="w-4 h-4 ml-1" /> : <ChevronDown className="w-4 h-4 ml-1" />}
            </Button>
          </div>
        </div>
      </div>

      <div className="p-6 sm:p-8 space-y-8">
        {/* Itemized Charges Breakdown (Expandable) */}
        {showItemized && (
          <div className="bg-muted/40 border border-border rounded-xl p-5 space-y-3 transition-all">
            <div className="flex justify-between items-center">
              <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                <Layers className="w-4 h-4 text-primary" />
                Select Individual Charges to Settle
              </span>
              <span className="text-xs font-semibold text-primary">
                {selectedChargeIds.length} of {itemizedCharges.length} selected
              </span>
            </div>

            <div className="divide-y divide-border/60">
              {itemizedCharges.map((charge) => {
                const isSelected = selectedChargeIds.includes(charge.id);
                return (
                  <label
                    key={charge.id}
                    className="flex items-center justify-between py-3 cursor-pointer hover:bg-muted/60 px-2 rounded-lg transition-colors"
                  >
                    <div className="flex items-center gap-3">
                      <input
                        type="checkbox"
                        checked={isSelected}
                        onChange={() => toggleCharge(charge.id)}
                        className="w-4 h-4 rounded text-primary focus:ring-primary border-muted-foreground/30"
                      />
                      <div>
                        <span className="text-sm font-semibold text-foreground block">
                          {charge.title}
                        </span>
                        <span className="text-xs text-muted-foreground capitalize">
                          {charge.category.replace('_', ' ')} • Status: {charge.status}
                        </span>
                      </div>
                    </div>
                    <span className="text-sm font-bold text-foreground">
                      ${charge.amount.toLocaleString()} {currency}
                    </span>
                  </label>
                );
              })}
            </div>
          </div>
        )}

        {/* Settlement Mode Selection */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
          <button
            type="button"
            onClick={() => setSettlementMode('full')}
            className={`p-3 rounded-xl border text-left transition-all ${
              settlementMode === 'full'
                ? 'border-primary bg-primary/5 text-primary font-bold shadow-sm'
                : 'border-border bg-card text-muted-foreground hover:bg-muted/50'
            }`}
          >
            <span className="text-xs block font-bold">Full Settlement</span>
            <span className="text-[11px] opacity-80">Pay 100% of charges</span>
          </button>

          <button
            type="button"
            onClick={() => setSettlementMode('partial')}
            className={`p-3 rounded-xl border text-left transition-all ${
              settlementMode === 'partial'
                ? 'border-primary bg-primary/5 text-primary font-bold shadow-sm'
                : 'border-border bg-card text-muted-foreground hover:bg-muted/50'
            }`}
          >
            <span className="text-xs block font-bold">Partial Payment</span>
            <span className="text-[11px] opacity-80">Custom amount today</span>
          </button>

          <button
            type="button"
            onClick={() => setSettlementMode('split')}
            className={`p-3 rounded-xl border text-left transition-all ${
              settlementMode === 'split'
                ? 'border-primary bg-primary/5 text-primary font-bold shadow-sm'
                : 'border-border bg-card text-muted-foreground hover:bg-muted/50'
            }`}
          >
            <span className="text-xs block font-bold flex items-center gap-1">
              <Split className="w-3 h-3" /> Split Payment
            </span>
            <span className="text-[11px] opacity-80">Wallet + Card/Bank</span>
          </button>

          <button
            type="button"
            onClick={() => setSettlementMode('plan')}
            className={`p-3 rounded-xl border text-left transition-all ${
              settlementMode === 'plan'
                ? 'border-primary bg-primary/5 text-primary font-bold shadow-sm'
                : 'border-border bg-card text-muted-foreground hover:bg-muted/50'
            }`}
          >
            <span className="text-xs block font-bold">Installment Plan</span>
            <span className="text-[11px] opacity-80">3 monthly payments</span>
          </button>
        </div>

        {/* Dynamic Controls for Partial / Split / Plan */}
        {settlementMode === 'partial' && (
          <div className="p-4 bg-muted/40 rounded-xl border border-border space-y-2">
            <label className="text-xs font-bold text-foreground">Enter Partial Amount to Pay Today</label>
            <div className="flex gap-2 max-w-xs">
              <Input
                type="number"
                value={partialAmount}
                onChange={(e) => setPartialAmount(e.target.value)}
                placeholder="25000"
                className="font-bold text-sm"
              />
              <span className="self-center text-xs font-bold text-muted-foreground">{currency}</span>
            </div>
            <p className="text-xs text-muted-foreground">
              Remaining balance (${(baseSelectedTotal - (Number(partialAmount) || 0)).toLocaleString()}) will be marked Partially Paid on your ledger.
            </p>
          </div>
        )}

        {settlementMode === 'split' && (
          <div className="p-4 bg-muted/40 rounded-xl border border-border space-y-2">
            <div className="flex justify-between items-center text-xs">
              <span className="font-bold text-foreground">Community Wallet Portion:</span>
              <span className="text-muted-foreground font-semibold">Usable: ${walletBalance.toLocaleString()} {currency}</span>
            </div>
            <div className="flex gap-2 max-w-xs">
              <Input
                type="number"
                value={splitWalletAmount}
                onChange={(e) => setSplitWalletAmount(e.target.value)}
                max={walletBalance}
                className="font-bold text-sm"
              />
              <span className="self-center text-xs font-bold text-muted-foreground">{currency}</span>
            </div>
            <p className="text-xs text-muted-foreground">
              Remaining ${(Math.max(0, currentPayTotal - (Number(splitWalletAmount) || 0))).toLocaleString()} will be charged to your selected card or bank transfer below.
            </p>
          </div>
        )}

        {settlementMode === 'plan' && (
          <div className="p-4 bg-blue-50/60 dark:bg-blue-950/30 rounded-xl border border-blue-200 dark:border-blue-900 space-y-2">
            <div className="text-xs font-bold text-blue-900 dark:text-blue-300">
              3-Month Community Installment Plan
            </div>
            <p className="text-xs text-blue-800 dark:text-blue-400">
              Spread your dues into 3 equal monthly payments of ${(currentPayTotal / 3).toLocaleString('en-US', { minimumFractionDigits: 2 })} {currency}. 0% interest, automated schedule.
            </p>
            <Button
              size="sm"
              className="mt-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs"
              onClick={() => handleConfirmPayment('plan')}
            >
              Enroll in 3-Month Plan ($ {(currentPayTotal / 3).toLocaleString()}/mo)
            </Button>
          </div>
        )}

        {/* Fast 1-Tap Payment Grid */}
        <div>
          <span className="block text-xs font-extrabold uppercase tracking-wider text-muted-foreground mb-3.5">
            Fast 1-Tap Checkout
          </span>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            {/* Apple Pay */}
            {channelEnabled('apple_pay') && (
              <button
                type="button"
                onClick={() => handleTriggerPay('apple_pay', 'Apple Pay')}
                className="flex flex-col items-center justify-center p-4 rounded-xl border border-border bg-slate-950 text-white hover:bg-black transition-all shadow hover:shadow-md group"
              >
                <span className="text-sm font-bold tracking-tight"> Apple Pay</span>
                <span className="text-[10px] text-slate-400 mt-1">1-Tap Touch ID</span>
                <SurchargeNote channelKey="apple_pay" />
              </button>
            )}

            {/* Google Pay */}
            {channelEnabled('google_pay') && (
              <button
                type="button"
                onClick={() => handleTriggerPay('google_pay', 'Google Pay')}
                className="flex flex-col items-center justify-center p-4 rounded-xl border border-border bg-card text-foreground hover:bg-muted/70 transition-all shadow hover:shadow-md"
              >
                <span className="text-sm font-black text-blue-600 dark:text-blue-400">G Pay</span>
                <span className="text-[10px] text-muted-foreground mt-1">Android / Web</span>
                <SurchargeNote channelKey="google_pay" />
              </button>
            )}

            {/* Samsung Wallet */}
            {channelEnabled('samsung_wallet') && (
              <button
                type="button"
                onClick={() => handleTriggerPay('samsung_wallet', 'Samsung Wallet')}
                className="flex flex-col items-center justify-center p-4 rounded-xl border border-border bg-blue-900 text-white hover:bg-blue-950 transition-all shadow hover:shadow-md"
              >
                <span className="text-sm font-bold">Samsung</span>
                <span className="text-[10px] text-blue-200 mt-1">Wallet</span>
                <SurchargeNote channelKey="samsung_wallet" />
              </button>
            )}

            {/* Credit / Debit Card */}
            {channelEnabled('card') && (
              <button
                type="button"
                onClick={() => handleTriggerPay('card', 'Debit / Credit Card')}
                className="flex flex-col items-center justify-center p-4 rounded-xl border border-border bg-card hover:bg-muted/70 text-foreground transition-all shadow hover:shadow-md"
              >
                <CreditCard className="w-5 h-5 text-primary mb-1" />
                <span className="text-xs font-bold">Card</span>
                <span className="text-[10px] text-muted-foreground">Visa / MC</span>
                <SurchargeNote channelKey="card" />
              </button>
            )}

            {/* Direct Bank Wire */}
            {channelEnabled('bank_wire') && (
              <button
                type="button"
                onClick={() => handleTriggerPay('bank_wire', 'Direct Bank Transfer')}
                className="flex flex-col items-center justify-center p-4 rounded-xl border border-border bg-card hover:bg-muted/70 text-foreground transition-all shadow hover:shadow-md"
              >
                <Landmark className="w-5 h-5 text-emerald-600 mb-1" />
                <span className="text-xs font-bold">Bank Wire</span>
                <span className="text-[10px] text-muted-foreground">NCB Account</span>
                <SurchargeNote channelKey="bank_wire" />
              </button>
            )}

            {/*
              No NFC option here. A tap is taken in person, on a card reader the
              processor has confirmed, and started by staff (Billing → In-person
              card payments) — never chosen and reported by a payer online.
            */}
          </div>
        </div>

        {/* Secondary Rails Tray */}
        <div className="pt-4 border-t border-border/70">
          <span className="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-3">
            Alternative Rails & Credits
          </span>
          <div className="flex flex-wrap items-center gap-2">
            {channelEnabled('cash_app') && (
              <Button
                variant="outline"
                size="sm"
                className="text-xs font-semibold text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
                onClick={() => handleTriggerPay('cash_app', 'Cash App')}
              >
                <Sparkles className="w-3.5 h-3.5 mr-1" /> Cash App ($CypressBayHOA)
              </Button>
            )}

            {channelEnabled('zelle') && (
              <Button
                variant="outline"
                size="sm"
                className="text-xs font-semibold text-purple-600 hover:text-purple-700 hover:bg-purple-50 dark:hover:bg-purple-950/40"
                onClick={() => handleTriggerPay('zelle', 'Zelle')}
              >
                <ShieldCheck className="w-3.5 h-3.5 mr-1" /> Zelle (payments@cypressbay.org)
              </Button>
            )}

            {channelEnabled('cash_office') && (
              <Button
                variant="outline"
                size="sm"
                className="text-xs font-semibold text-slate-700 dark:text-slate-300"
                onClick={() => handleTriggerPay('cash_office', 'Cash at Office')}
              >
                <Banknote className="w-3.5 h-3.5 mr-1" /> Cash at Office
              </Button>
            )}

            {channelEnabled('qr_code') && (
              <Button
                variant="outline"
                size="sm"
                className="text-xs font-semibold text-blue-600"
                onClick={() => handleTriggerPay('qr_code', 'QR Code Scan')}
              >
                <QrCode className="w-3.5 h-3.5 mr-1" /> QR Scan
              </Button>
            )}

            {channelEnabled('wallet') && (
              <Button
                variant="outline"
                size="sm"
                className="text-xs font-semibold text-amber-600 dark:text-amber-400"
                onClick={() => handleTriggerPay('wallet', 'Community Wallet')}
              >
                <WalletIcon className="w-3.5 h-3.5 mr-1" /> Wallet Balance (${walletBalance.toLocaleString()})
              </Button>
            )}
          </div>
        </div>
      </div>

      {/* Interactive Payment Execution Dialog */}
      <Dialog open={activeModal !== null} onOpenChange={(open) => !open && setActiveModal(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <CheckCircle2 className="w-5 h-5 text-primary" />
              <span>Complete Payment via {modalMethod}</span>
            </DialogTitle>
            <DialogDescription>
              Authorizing payment of <strong>${Number(currentPayTotal).toLocaleString()} {currency}</strong> for Cypress Bay Community Dues.
            </DialogDescription>
          </DialogHeader>

          <div className="py-2 space-y-4 text-sm">
            {/* Canonical CommunityHub Transaction Record Card */}
            <div className="rounded-xl border border-primary/20 bg-slate-50 dark:bg-slate-900/80 p-4 space-y-3">
              <div className="flex items-center justify-between pb-2 border-b border-border/60">
                <div className="flex items-center gap-2">
                  <span className="text-[11px] uppercase font-bold text-muted-foreground tracking-wider">
                    Official Transaction Slip
                  </span>
                  <Badge className="bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border-amber-300 dark:border-amber-800 text-[10px] py-0 px-1.5 font-bold uppercase">
                    {pendingSlip?.state_label || pendingSlip?.status || (slipError ? 'NOT STARTED' : '…')}
                  </Badge>
                </div>
                {pendingSlip?.transaction_id && (
                  <button
                    type="button"
                    onClick={() => {
                      navigator.clipboard.writeText(pendingSlip.transaction_id);
                      setCopiedId(true);
                      setTimeout(() => setCopiedId(false), 2000);
                    }}
                    className="flex items-center gap-1 text-[11px] font-mono font-bold text-primary hover:text-primary/80 transition-colors"
                    title="Copy Transaction ID"
                  >
                    <span>{pendingSlip.transaction_id}</span>
                    {copiedId ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5 opacity-60" />}
                  </button>
                )}
              </div>

              {slipError && (
                <p role="alert" className="text-xs text-amber-700 dark:text-amber-300">{slipError}</p>
              )}

              {isInitiating && !pendingSlip ? (
                <div className="flex items-center justify-center py-4 text-xs text-muted-foreground gap-2">
                  <Loader2 className="w-4 h-4 animate-spin text-primary" />
                  <span>Registering canonical CommunityHub transaction record...</span>
                </div>
              ) : (
                <div className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                  <div>
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">User</span>
                    <span className="font-mono font-bold text-foreground">{pendingSlip?.user || '—'}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Property</span>
                    <span className="font-mono font-bold text-foreground">{pendingSlip?.property || '—'}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Community</span>
                    <span className="font-mono font-bold text-foreground">{pendingSlip?.community || '—'}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Purpose</span>
                    <span className="font-semibold text-foreground">{pendingSlip?.purpose || '—'}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Invoice</span>
                    <span className="font-mono text-foreground font-semibold">{pendingSlip?.invoice || '—'}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Amount & Currency</span>
                    <span className="font-bold text-foreground text-xs">
                      ${pendingSlip?.amount || Number(currentPayTotal).toFixed(2)} {pendingSlip?.currency || selectedCurrency}
                    </span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Payment Method</span>
                    <span className="font-semibold text-foreground">{pendingSlip?.payment_method || modalMethod}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Payment Processor</span>
                    <span className="font-semibold text-foreground capitalize">
                      {pendingSlip?.provider
                        ? providerLabel(pendingSlip.provider)
                        : isProcessorChannel(activeModal || 'card')
                          ? cardProvider
                          : providerLabel(activeModal === 'wallet' ? 'internal' : 'office')}
                    </span>
                  </div>
                  {pendingSlip?.device && (
                    <div className="col-span-2 pt-1 border-t border-border/40">
                      <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Terminal / Device</span>
                      <span className="font-mono font-semibold text-purple-700 dark:text-purple-300">{pendingSlip.device}</span>
                    </div>
                  )}
                  <div className="col-span-2 pt-1 border-t border-border/40 flex justify-between text-[10px] text-muted-foreground">
                    <span>Created: {pendingSlip?.created || '—'}</span>
                    {pendingSlip && (
                      <span className="font-medium text-emerald-600 dark:text-emerald-400">Pre-Created in CommunityHub</span>
                    )}
                  </div>
                </div>
              )}
            </div>

            {/*
              Device wallets. This showed Apple/Google/Samsung-styled panels —
              "Double-Click Side Button", "Swipe up from the bottom" — while
              nothing was being authenticated, and the payment then went to the
              office to confirm on the payer's word. A wallet payment is taken
              by the card processor: its page shows the wallet, the wallet
              authenticates the payer and hands the processor a token, and the
              processor reports the result. These options appear only when the
              estate's card processor offers them (ProviderRegistry).
            */}
            {WALLET_CHANNELS.includes(activeModal ?? '') && (
              <div className="p-3 bg-muted/40 rounded-lg text-xs space-y-2">
                <p className="font-semibold">{modalMethod}</p>
                <p className="text-muted-foreground">
                  You&apos;ll continue to {cardProvider}&apos;s secure payment page for{' '}
                  <strong>{pendingSlip?.transaction_id || 'your transaction'}</strong>. Choose {modalMethod} there on a
                  device that supports it: your wallet confirms it&apos;s you, {cardProvider} charges your card, and your
                  statement updates once {cardProvider} confirms the payment.
                </p>
              </div>
            )}

            {activeModal === 'card' && (
              <div className="space-y-3">
                <div className="p-3 bg-muted/40 rounded-lg text-xs space-y-2">
                  <p className="font-semibold">Card checkout</p>
                  {cardCheckout ? (
                    <p className="text-muted-foreground">
                      You&apos;ll be taken to {cardProvider}&apos;s secure payment page to complete payment for <strong>{pendingSlip?.transaction_id || 'your transaction'}</strong>. Card details are entered there, never stored in Community Hub. Your statement updates once {cardProvider} confirms the payment.
                    </p>
                  ) : (
                    <p className="text-muted-foreground">
                      Card payment is not yet in service. When it is, you will be taken to the card processor's own secure page to enter your card details.
                    </p>
                  )}
                </div>
              </div>
            )}

            {activeModal === 'bank_wire' && (
              <div className="p-4 bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-xl space-y-2 text-xs">
                <div className="flex items-center justify-between">
                  <span className="font-bold text-emerald-900 dark:text-emerald-300">National Commercial Bank (NCB)</span>
                  <Badge className="bg-sky-100 text-sky-800 dark:bg-sky-950/80 dark:text-sky-300 border-sky-300 text-[10px]">
                    AWAITING BANK TRANSFER
                  </Badge>
                </div>
                <div>Account Name: <strong>Cypress Bay Community HOA Ltd.</strong></div>
                <div>Account Number: <strong>102938475</strong> (Checking)</div>
                <div>Branch: <strong>Kingston 001</strong></div>
                <div>Wire Reference: <strong className="font-mono bg-white dark:bg-black px-1 py-0.5 rounded text-primary">{pendingSlip?.transaction_id || 'shown once the payment starts'}</strong></div>
                <p className="text-[11px] text-muted-foreground pt-1 border-t border-emerald-200 dark:border-emerald-800">
                  ⚠️ Bank transfers require asynchronous reconciliation. CommunityHub matches the deposit from the bank feed and verifies with the ledger before marking your statement Paid.
                </p>
              </div>
            )}

            {activeModal === 'zelle' && (
              <div className="p-4 bg-purple-50/70 dark:bg-purple-950/30 border border-purple-200 dark:border-purple-800 rounded-xl space-y-2 text-xs">
                <div className="font-bold text-purple-900 dark:text-purple-300">Zelle External Transfer</div>
                <div>Recipient Email: <strong>payments@cypressbay.org</strong></div>
                <div>Memo / Reference: <strong className="font-mono bg-white dark:bg-black px-1 py-0.5 rounded text-primary">{pendingSlip?.transaction_id || 'shown once the payment starts'}</strong></div>
                <p className="text-[11px] text-muted-foreground pt-1 border-t border-purple-200 dark:border-purple-800">
                  ℹ️ External consumer app: After sending via your banking app, CommunityHub flags this transaction for office reconciliation before updating the ledger.
                </p>
              </div>
            )}

            {activeModal === 'cash_app' && (
              <div className="p-4 bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-xl space-y-2 text-xs">
                <div className="font-bold text-emerald-900 dark:text-emerald-300">Cash App External Transfer</div>
                <div>Cashtag: <strong>$CypressBayHOA</strong></div>
                <div>Note: <strong className="font-mono bg-white dark:bg-black px-1 py-0.5 rounded text-primary">{pendingSlip?.transaction_id || 'shown once the payment starts'}</strong></div>
                <p className="text-[11px] text-muted-foreground pt-1 border-t border-emerald-200 dark:border-emerald-800">
                  ℹ️ External consumer app: Send payment with your transaction ID in the note. Office will verify receipt before settling your statement.
                </p>
              </div>
            )}

            {activeModal === 'qr_code' && (
              <div className="text-center space-y-3">
                {qrSlipImage ? (
                  <img
                    src={qrSlipImage}
                    alt={`QR code for payment ${pendingSlip?.transaction_id}`}
                    className="mx-auto rounded-lg border p-2 bg-white"
                    width={180}
                    height={180}
                  />
                ) : (
                  <div className="mx-auto flex h-[180px] w-[180px] items-center justify-center rounded-lg border p-2 text-xs text-muted-foreground">
                    {isInitiating ? <Loader2 className="h-5 w-5 animate-spin" aria-label="Starting payment" /> : 'Shown once the payment starts'}
                  </div>
                )}
                <p className="text-xs text-muted-foreground">
                  Scan with your banking app, or quote <strong className="font-mono">{pendingSlip?.transaction_id || 'your transaction ID'}</strong> as the reference. The office confirms the payment before your statement updates.
                </p>
              </div>
            )}

            {activeModal === 'wallet' && (
              <div className="p-4 bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-xl space-y-2 text-xs">
                <div className="font-bold text-amber-900 dark:text-amber-300">Community Digital Wallet Deduction</div>
                <div>Available Credit Balance: <strong>${walletBalance.toLocaleString()} {currency}</strong></div>
                <div>Amount to Deduct: <strong>${Number(currentPayTotal).toLocaleString()} {currency}</strong></div>
                <p className="text-muted-foreground mt-1">Funds will be instantly debited from your closed-loop community ledger.</p>
              </div>
            )}

            <Button
              className="w-full font-bold py-3 mt-4"
              disabled={isProcessing}
              onClick={() => {
                if (activeModal === 'card') {
                  setActiveModal(null);
                  setIsCardModalOpen(true);
                  return;
                }
                handleConfirmPayment(activeModal || 'card');
              }}
            >
              {isProcessing
                ? 'Processing Settlement...'
                : activeModal === 'card'
                ? `Enter Card Details (${curConfig.symbol}${Number(currentPayTotal).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${selectedCurrency})`
                : isProcessorChannel(activeModal || 'card')
                ? `Continue to secure checkout (${curConfig.symbol}${Number(currentPayTotal).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${selectedCurrency})`
                : `Confirm & Authorize Payment (${curConfig.symbol}${Number(currentPayTotal).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${selectedCurrency})`}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <PaymentMethodSelectorModal
        isOpen={isSelectorModalOpen}
        onClose={() => setIsSelectorModalOpen(false)}
        amountDue={currentPayTotal}
        currency={selectedCurrency}
        availableChannels={availableChannels}
        walletBalance={walletBalance}
      />

      <CardPaymentModal
        isOpen={isCardModalOpen}
        onClose={() => setIsCardModalOpen(false)}
        amount={Number(currentPayTotal)}
        currency={selectedCurrency}
      />
    </div>
  );
}
