import React from 'react';
import { 
  Sparkles, 
  RefreshCw, 
  ExternalLink, 
  CheckCircle2 
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface TestTransactionResult {
  id: string;
  amount: string;
  status: string;
  date: string;
  dashboardUrl: string;
}

interface StripeTestBannerProps {
  isProcessingTest: boolean;
  testTransactionResult: TestTransactionResult | null;
  onRunTestTransaction: () => void;
}

export function StripeTestBanner({
  isProcessingTest,
  testTransactionResult,
  onRunTestTransaction,
}: StripeTestBannerProps) {
  return (
    <div className="space-y-3">
      {/* ── STRIPE TEST MODE BANNER ───────────────────────────────────── */}
      <div className="rounded-xl border border-sky-500/20 bg-gradient-to-r from-sky-500/10 via-primary/5 to-transparent p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs shadow-sm">
        <div className="flex items-start gap-3">
          <div className="p-2 rounded-lg bg-sky-500/15 text-sky-600 dark:text-sky-400 shrink-0">
            <Sparkles className="w-4 h-4" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="font-bold text-sm text-foreground">Stripe Test-Mode Environment</span>
              <Badge variant="outline" className="text-[10px] uppercase font-bold text-sky-600 dark:text-sky-400 border-sky-500/30">
                Non-Real Money
              </Badge>
            </div>
            <p className="text-muted-foreground text-[11px] mt-0.5 leading-relaxed">
              Test transactions verify the complete payment workflow (hosted checkout, device wallets, webhooks, and double-entry ledger) and appear in the Stripe Dashboard without moving real funds.
            </p>
          </div>
        </div>
        <Button
          onClick={onRunTestTransaction}
          disabled={isProcessingTest}
          size="sm"
          variant="outline"
          className="shrink-0 text-xs gap-1.5 border-sky-500/30 hover:bg-sky-500/10 font-medium"
        >
          {isProcessingTest ? (
            <>
              <RefreshCw className="w-3.5 h-3.5 animate-spin" /> Verifying...
            </>
          ) : (
            <>
              <ExternalLink className="w-3.5 h-3.5" /> Run Test Transaction
            </>
          )}
        </Button>
      </div>

      {testTransactionResult && (
        <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs flex items-center justify-between animate-in fade-in slide-in-from-top-2">
          <div className="space-y-0.5">
            <span className="font-semibold text-emerald-700 dark:text-emerald-300 flex items-center gap-1.5">
              <CheckCircle2 className="w-3.5 h-3.5" /> Verified Stripe Test Charge: {testTransactionResult.id}
            </span>
            <p className="text-muted-foreground text-[11px]">
              Amount: <strong className="text-foreground">{testTransactionResult.amount}</strong> • Status: {testTransactionResult.status} • Recorded at {testTransactionResult.date}
            </p>
          </div>
          <Button
            asChild
            variant="ghost"
            size="sm"
            className="text-xs text-emerald-600 dark:text-emerald-400 hover:text-emerald-700"
          >
            <a href={testTransactionResult.dashboardUrl} target="_blank" rel="noreferrer">
              Stripe Dashboard <ExternalLink className="w-3 h-3 ml-1" />
            </a>
          </Button>
        </div>
      )}
    </div>
  );
}
