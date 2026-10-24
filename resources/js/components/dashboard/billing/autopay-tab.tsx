import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { BarChart3, RefreshCw } from 'lucide-react';
import type { PaymentCenterProps } from '@/components/dashboard/payment-center-hero';
import type { AutoPayPortfolio } from './types';

type Props = {
  autoPay?: PaymentCenterProps['autoPay'];
  portfolio?: AutoPayPortfolio;
  isAdmin: boolean;
};

export function AutoPayTab({ autoPay, portfolio, isAdmin }: Props) {
  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
      <div className="lg:col-span-6 space-y-6">
        <Card className="border shadow-sm">
          <CardHeader>
            <CardTitle className="text-lg font-bold flex items-center gap-2">
              <RefreshCw className="h-5 w-5 text-cyan-500" />
              <span>Resident AutoPay Preferences</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Configure automated assessment deductions to guarantee zero late fees and instant gate pass validity.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {autoPay && (
              <div className="p-4 rounded-xl border bg-muted/20 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="font-semibold text-xs text-foreground">AutoPay Deduction Status</span>
                  <Badge variant={autoPay.isActive ? 'default' : 'secondary'} className="font-mono text-xs">
                    {autoPay.isActive ? 'ACTIVE & ENROLLED' : 'PAUSED / OFF'}
                  </Badge>
                </div>
                <div className="grid grid-cols-2 gap-3 text-xs">
                  <div className="p-2.5 rounded-lg border bg-background">
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Deduction Cadence</span>
                    <strong className="text-foreground capitalize">{autoPay.cadence}</strong>
                  </div>
                  <div className="p-2.5 rounded-lg border bg-background">
                    <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Monthly Charge Day</span>
                    <strong className="text-foreground font-mono">Day {autoPay.chargeDay} of month</strong>
                  </div>
                </div>
                <p className="text-[11px] text-muted-foreground">
                  Deduction is routed securely through non-custodial tokenized channels on file.
                </p>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {isAdmin && portfolio && (
        <div className="lg:col-span-6 space-y-6">
          <Card className="border shadow-sm">
            <CardHeader>
              <CardTitle className="text-lg font-bold flex items-center gap-2">
                <BarChart3 className="h-5 w-5 text-indigo-500" />
                <span>Estate AutoPay Portfolio Metrics</span>
              </CardTitle>
              <CardDescription className="text-xs">
                Automated recurring collection volume across registered community households.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <div className="p-3 rounded-xl border bg-emerald-500/10 border-emerald-500/20 text-center">
                  <span className="text-[10px] text-emerald-800 dark:text-emerald-300 font-semibold uppercase block">
                    Enrolled Households
                  </span>
                  <strong className="text-2xl font-mono font-bold text-emerald-700 dark:text-emerald-200">
                    {portfolio.enrolledCount}
                  </strong>
                  <span className="text-[10px] text-muted-foreground block mt-0.5">Recurring Direct Dues</span>
                </div>
                <div className="p-3 rounded-xl border bg-indigo-500/10 border-indigo-500/20 text-center">
                  <span className="text-[10px] text-indigo-800 dark:text-indigo-300 font-semibold uppercase block">
                    Next Cycle Run
                  </span>
                  <strong className="text-lg font-mono font-bold text-indigo-700 dark:text-indigo-200">
                    {portfolio.nextRunDate}
                  </strong>
                  <span className="text-[10px] text-muted-foreground block mt-0.5">Automated Ledger Settlement</span>
                </div>
              </div>

              <div className="p-3 rounded-xl border bg-background space-y-2 text-xs">
                <span className="text-[10px] font-semibold text-muted-foreground uppercase block">
                  Cadence Distribution
                </span>
                <div className="flex items-center justify-between">
                  <span>Monthly Cycles:</span>
                  <strong className="font-mono">{portfolio.cadenceBreakdown.monthly || 0} accounts</strong>
                </div>
                <div className="flex items-center justify-between">
                  <span>Quarterly Cycles:</span>
                  <strong className="font-mono">{portfolio.cadenceBreakdown.quarterly || 0} accounts</strong>
                </div>
                <div className="flex items-center justify-between">
                  <span>Annual Cycles:</span>
                  <strong className="font-mono">{portfolio.cadenceBreakdown.annual || 0} accounts</strong>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
}
