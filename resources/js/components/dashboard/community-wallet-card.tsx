import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
  Wallet as WalletIcon,
  Clock,
  Gift,
  ArrowUpRight,
  PlusCircle,
  ShieldCheck,
  CheckCircle2,
  RefreshCw,
} from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

export type WalletData = {
  available: number;
  pending: number;
  rewards: number;
  totalUsable: number;
  currency: string;
  autoReload: {
    enabled: boolean;
    threshold: number;
    amount: number;
  };
  recentTransactions: Array<{
    id: number;
    amount: number;
    currency: string;
    type: string;
    balanceType: string;
    reference: string;
    description: string;
    date: string;
  }>;
};

export function CommunityWalletCard({
  wallet,
  onApplyDues,
}: {
  wallet: WalletData;
  onApplyDues?: () => void;
}) {
  const [isTopUpOpen, setTopUpOpen] = useState(false);
  const [topUpAmount, setTopUpAmount] = useState<string>('5000');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleTopUp = () => {
    setIsSubmitting(true);
    router.post(
      '/dashboard/billing/wallet/topup',
      { amount: Number(topUpAmount) },
      {
        onSuccess: () => {
          setIsSubmitting(false);
          setTopUpOpen(false);
        },
        onError: () => setIsSubmitting(false),
      }
    );
  };

  return (
    <div className="bg-card border border-border rounded-2xl shadow-lg p-6 sm:p-8 space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border pb-5">
        <div className="flex items-center gap-3">
          <div className="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center">
            <WalletIcon className="w-6 h-6" />
          </div>
          <div>
            <h2 className="text-xl font-bold text-foreground">My Community Wallet</h2>
            <p className="text-xs text-muted-foreground">
              Closed-loop internal estate balance for dues, gate tokens, and amenity bookings.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button
            size="sm"
            className="bg-amber-600 hover:bg-amber-700 text-white font-semibold text-xs"
            onClick={() => setTopUpOpen(true)}
          >
            <PlusCircle className="w-3.5 h-3.5 mr-1.5" /> Top Up Balance
          </Button>
          {onApplyDues && (
            <Button
              variant="outline"
              size="sm"
              className="text-xs font-semibold"
              onClick={onApplyDues}
            >
              Apply to Dues
            </Button>
          )}
        </div>
      </div>

      {/* Tripartite Balance Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        {/* Available */}
        <div className="p-4 rounded-xl border border-border bg-muted/40 relative overflow-hidden">
          <div className="flex items-center justify-between text-xs text-muted-foreground font-semibold">
            <span>AVAILABLE</span>
            <CheckCircle2 className="w-4 h-4 text-emerald-600" />
          </div>
          <div className="text-2xl sm:text-3xl font-black text-foreground mt-2">
            ${wallet.available.toLocaleString('en-US', { minimumFractionDigits: 2 })}
          </div>
          <p className="text-[11px] text-muted-foreground mt-1">Ready for 1-tap dues & POS fees</p>
        </div>

        {/* Pending */}
        <div className="p-4 rounded-xl border border-border bg-muted/40 relative overflow-hidden">
          <div className="flex items-center justify-between text-xs text-muted-foreground font-semibold">
            <span>PENDING</span>
            <Clock className="w-4 h-4 text-amber-600" />
          </div>
          <div className="text-2xl sm:text-3xl font-black text-foreground mt-2">
            ${wallet.pending.toLocaleString('en-US', { minimumFractionDigits: 2 })}
          </div>
          <p className="text-[11px] text-muted-foreground mt-1">Awaiting bank wire verification</p>
        </div>

        {/* Rewards / Credits */}
        <div className="p-4 rounded-xl border border-border bg-muted/40 relative overflow-hidden">
          <div className="flex items-center justify-between text-xs text-muted-foreground font-semibold">
            <span>REWARDS / CREDITS</span>
            <Gift className="w-4 h-4 text-purple-600" />
          </div>
          <div className="text-2xl sm:text-3xl font-black text-foreground mt-2">
            ${wallet.rewards.toLocaleString('en-US', { minimumFractionDigits: 2 })}
          </div>
          <p className="text-[11px] text-muted-foreground mt-1">Early-pay discounts & volunteer credits</p>
        </div>
      </div>

      {/* Regulatory Badge */}
      <div className="p-3 bg-muted/30 border border-border/70 rounded-xl flex items-center justify-between text-xs text-muted-foreground">
        <div className="flex items-center gap-2">
          <ShieldCheck className="w-4 h-4 text-emerald-600" />
          <span>Internal community ledger — balances are records, not held funds</span>
        </div>
        <Badge variant="outline" className="text-[10px]">
          Total Usable: ${wallet.totalUsable.toLocaleString()} {wallet.currency}
        </Badge>
      </div>

      {/* Recent Transactions List */}
      <div>
        <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground block mb-3">
          Recent Wallet Ledger Activity
        </span>
        <div className="divide-y divide-border/60 border border-border/70 rounded-xl overflow-hidden bg-card">
          {wallet.recentTransactions.length > 0 ? (
            wallet.recentTransactions.map((tx) => (
              <div key={tx.id} className="p-3.5 flex items-center justify-between text-xs">
                <div className="flex items-center gap-3">
                  <div
                    className={`w-7 h-7 rounded-full flex items-center justify-center font-bold ${
                      tx.type === 'credit'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                        : 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300'
                    }`}
                  >
                    {tx.type === 'credit' ? '+' : '-'}
                  </div>
                  <div>
                    <span className="font-semibold text-foreground block">{tx.description}</span>
                    <span className="text-[11px] text-muted-foreground">
                      Ref: {tx.reference} • {tx.date}
                    </span>
                  </div>
                </div>

                <div className="text-right">
                  <span
                    className={`font-bold ${
                      tx.type === 'credit' ? 'text-emerald-600' : 'text-foreground'
                    }`}
                  >
                    {tx.type === 'credit' ? '+' : '-'}${tx.amount.toLocaleString()} {tx.currency}
                  </span>
                  <span className="text-[10px] text-muted-foreground block capitalize">
                    {tx.balanceType}
                  </span>
                </div>
              </div>
            ))
          ) : (
            <p className="p-4 text-center text-xs text-muted-foreground">No recent wallet transactions recorded.</p>
          )}
        </div>
      </div>

      {/* Top Up Modal */}
      <Dialog open={isTopUpOpen} onOpenChange={setTopUpOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <PlusCircle className="w-5 h-5 text-amber-600" />
              <span>Top Up Community Wallet</span>
            </DialogTitle>
            <DialogDescription>
              Deposit credits to your internal community balance for instant dues and fee settlement.
            </DialogDescription>
          </DialogHeader>

          <div className="py-4 space-y-4">
            <div>
              <label className="text-xs font-bold text-muted-foreground block mb-2">
                Select Quick Amount
              </label>
              <div className="grid grid-cols-3 gap-2">
                {['2500', '5000', '10000'].map((amt) => (
                  <Button
                    key={amt}
                    type="button"
                    variant={topUpAmount === amt ? 'default' : 'outline'}
                    size="sm"
                    className="font-bold text-xs"
                    onClick={() => setTopUpAmount(amt)}
                  >
                    ${Number(amt).toLocaleString()} JMD
                  </Button>
                ))}
              </div>
            </div>

            <div>
              <label className="text-xs font-bold text-muted-foreground block mb-1.5">
                Or Enter Custom Amount ($ JMD)
              </label>
              <Input
                type="number"
                value={topUpAmount}
                onChange={(e) => setTopUpAmount(e.target.value)}
                min="100"
                className="font-bold text-sm"
              />
            </div>

            <Button
              className="w-full bg-amber-600 hover:bg-amber-700 text-white font-bold py-3 mt-4"
              disabled={isSubmitting}
              onClick={handleTopUp}
            >
              {isSubmitting ? 'Processing Deposit...' : `Add $${Number(topUpAmount).toLocaleString()} to Wallet`}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
