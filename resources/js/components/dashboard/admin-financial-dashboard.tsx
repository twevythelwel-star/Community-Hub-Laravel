import { useState } from 'react';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import {
  DollarSign,
  TrendingUp,
  AlertTriangle,
  HeartHandshake,
  Clock,
  RotateCcw,
  CheckCircle2,
  PieChart,
  ShieldCheck,
  Building,
  CreditCard,
  Smartphone,
  FileSpreadsheet,
  Calendar,
  Globe,
} from 'lucide-react';
import { money } from '@/components/dashboard/billing-summary';

export type FinancialDashboardProps = {
  financialDashboard: {
    collectedToday: number;
    outstanding: number;
    fundraising: number;
    pending: number;
    refunds: number;
    collectionRate: number;
    collectionsByCurrency?: {
      currency: string;
      symbol: string;
      name: string;
      total: number;
      count: number;
    }[];
    paymentMethodsBreakdown: {
      name: string;
      percent: number;
      amount: number;
    }[];
    delinquencyAging: {
      period: string;
      amount: number;
      count: number;
    }[];
  };
  payouts?: {
    id: number;
    vendor: string;
    category: string;
    amount: number;
    currency: string;
    status: string;
    scheduledFor: string;
    reference: string;
  }[];
  reconciliations?: {
    id: number;
    statementDate: string;
    statementBalance: number;
    ledgerBalance: number;
    difference: number;
    status: string;
    notes?: string | null;
  }[];
  currency?: string;
};

export function AdminFinancialDashboard({
  financialDashboard,
  payouts = [],
  reconciliations = [],
  currency = 'JMD',
}: FinancialDashboardProps) {
  const {
    collectedToday,
    outstanding,
    fundraising,
    pending,
    refunds,
    collectionRate,
    collectionsByCurrency = [],
    paymentMethodsBreakdown,
    delinquencyAging,
  } = financialDashboard;

  const getMethodIcon = (name: string) => {
    const n = name.toLowerCase();
    if (n.includes('card')) return <CreditCard className="h-4 w-4 text-amber-500" />;
    if (n.includes('apple') || n.includes('google') || n.includes('nfc'))
      return <Smartphone className="h-4 w-4 text-emerald-500" />;
    return <Building className="h-4 w-4 text-blue-500" />;
  };

  return (
    <div className="space-y-8">
      {/* Top Banner & Export Action */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-gradient-to-r from-slate-900 to-indigo-950 p-6 rounded-2xl text-white shadow-xl">
        <div>
          <div className="flex items-center gap-2 text-indigo-300 text-xs uppercase tracking-wider font-semibold">
            <ShieldCheck className="h-4 w-4" /> Real-Time Treasury Intelligence
          </div>
          <h2 className="text-2xl font-bold tracking-tight mt-1">
            Executive Financial Command Center
          </h2>
          <p className="text-slate-300 text-sm mt-1">
            Liquidity tracking, collection efficiency and reconciliation against recorded transactions.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Button
            variant="secondary"
            className="bg-white/10 hover:bg-white/20 text-white border-white/20 text-xs font-semibold gap-2"
            onClick={() => {
              window.location.href = '/dashboard/billing/export-transactions';
            }}
          >
            <FileSpreadsheet className="h-4 w-4 text-emerald-400" />
            Settlement Audit CSV
          </Button>
        </div>
      </div>

      {/* 5 Primary KPI Cards */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        <Card className="border-emerald-200 dark:border-emerald-950/60 bg-emerald-50/40 dark:bg-emerald-950/20 shadow-sm">
          <CardHeader className="p-4 pb-1">
            <CardDescription className="text-emerald-700 dark:text-emerald-400 font-semibold text-xs flex items-center gap-1.5">
              <DollarSign className="h-3.5 w-3.5" /> Collected Today
            </CardDescription>
          </CardHeader>
          <CardContent className="p-4 pt-1">
            <div className="text-2xl font-bold text-emerald-950 dark:text-emerald-100">
              {money(collectedToday, currency)}
            </div>
            <div className="text-[11px] text-emerald-600 dark:text-emerald-400 mt-1 font-medium">
              +14% vs. yesterday
            </div>
          </CardContent>
        </Card>

        <Card className="border-rose-200 dark:border-rose-950/60 bg-rose-50/40 dark:bg-rose-950/20 shadow-sm">
          <CardHeader className="p-4 pb-1">
            <CardDescription className="text-rose-700 dark:text-rose-400 font-semibold text-xs flex items-center gap-1.5">
              <AlertTriangle className="h-3.5 w-3.5" /> Outstanding
            </CardDescription>
          </CardHeader>
          <CardContent className="p-4 pt-1">
            <div className="text-2xl font-bold text-rose-950 dark:text-rose-100">
              {money(outstanding, currency)}
            </div>
            <div className="text-[11px] text-rose-600 dark:text-rose-400 mt-1 font-medium">
              Estate-wide receivables
            </div>
          </CardContent>
        </Card>

        <Card className="border-purple-200 dark:border-purple-950/60 bg-purple-50/40 dark:bg-purple-950/20 shadow-sm">
          <CardHeader className="p-4 pb-1">
            <CardDescription className="text-purple-700 dark:text-purple-400 font-semibold text-xs flex items-center gap-1.5">
              <HeartHandshake className="h-3.5 w-3.5" /> Fundraising
            </CardDescription>
          </CardHeader>
          <CardContent className="p-4 pt-1">
            <div className="text-2xl font-bold text-purple-950 dark:text-purple-100">
              {money(fundraising, currency)}
            </div>
            <div className="text-[11px] text-purple-600 dark:text-purple-400 mt-1 font-medium">
              Community campaigns
            </div>
          </CardContent>
        </Card>

        <Card className="border-amber-200 dark:border-amber-950/60 bg-amber-50/40 dark:bg-amber-950/20 shadow-sm">
          <CardHeader className="p-4 pb-1">
            <CardDescription className="text-amber-700 dark:text-amber-400 font-semibold text-xs flex items-center gap-1.5">
              <Clock className="h-3.5 w-3.5" /> Pending Clearance
            </CardDescription>
          </CardHeader>
          <CardContent className="p-4 pt-1">
            <div className="text-2xl font-bold text-amber-950 dark:text-amber-100">
              {money(pending, currency)}
            </div>
            <div className="text-[11px] text-amber-600 dark:text-amber-400 mt-1 font-medium">
              ACH & bank queues
            </div>
          </CardContent>
        </Card>

        <Card className="border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30 shadow-sm">
          <CardHeader className="p-4 pb-1">
            <CardDescription className="text-slate-600 dark:text-slate-400 font-semibold text-xs flex items-center gap-1.5">
              <RotateCcw className="h-3.5 w-3.5" /> Total Refunds
            </CardDescription>
          </CardHeader>
          <CardContent className="p-4 pt-1">
            <div className="text-2xl font-bold text-slate-900 dark:text-slate-100">
              {money(refunds, currency)}
            </div>
            <div className="text-[11px] text-slate-500 mt-1 font-medium">
              0.8% of aggregate volume
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Multi-Currency Treasury Holdings */}
      {collectionsByCurrency && collectionsByCurrency.length > 0 && (
        <Card className="border-indigo-100 dark:border-indigo-950/60 bg-gradient-to-br from-indigo-50/40 via-white to-slate-50/50 dark:from-slate-900/60 dark:to-indigo-950/20 shadow-sm overflow-hidden">
          <CardHeader className="p-4 pb-3 border-b border-slate-100 dark:border-slate-800/80">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <div className="h-8 w-8 rounded-lg bg-indigo-600/10 dark:bg-indigo-400/10 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                  <Globe className="h-4 w-4" />
                </div>
                <div>
                  <CardTitle className="text-sm font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                    Multi-Currency Treasury Holdings
                    <Badge variant="outline" className="text-[10px] font-semibold border-indigo-200 text-indigo-700 dark:border-indigo-800 dark:text-indigo-300">
                      Live Multi-Asset Tracking
                    </Badge>
                  </CardTitle>
                  <CardDescription className="text-xs text-slate-500 dark:text-slate-400">
                    Gross settled revenue collected and segregated directly in respective denomination accounts.
                  </CardDescription>
                </div>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-4">
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
              {collectionsByCurrency.map((item) => (
                <div
                  key={item.currency}
                  className="p-3 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 hover:border-indigo-300 dark:hover:border-indigo-700 transition-all shadow-xs"
                >
                  <div className="flex items-center justify-between">
                    <span className="font-bold text-xs text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                      <span className="w-5 h-5 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-[10px] font-black text-indigo-600 dark:text-indigo-400">
                        {item.symbol}
                      </span>
                      {item.currency}
                    </span>
                    <Badge variant="secondary" className="text-[9px] px-1.5 py-0 h-4 font-semibold text-slate-500">
                      {item.count} txns
                    </Badge>
                  </div>
                  <div className="mt-2.5">
                    <div className="text-lg font-black text-slate-900 dark:text-white tracking-tight">
                      {money(item.total, item.currency)}
                    </div>
                    <div className="text-[10px] text-muted-foreground truncate font-medium mt-0.5">
                      {item.name}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Collection Efficiency & Payment Method Breakdown */}
      <div className="grid grid-cols-1 md:grid-cols-12 gap-6">
        {/* Collection Efficiency Gauge Card */}
        <Card className="md:col-span-5 shadow-sm border-slate-200 dark:border-slate-800 flex flex-col justify-between">
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle className="text-lg font-bold flex items-center gap-2">
                <TrendingUp className="h-5 w-5 text-emerald-600" />
                Collection Efficiency
              </CardTitle>
              <Badge className="bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 font-bold">
                Healthy Target: 90%+
              </Badge>
            </div>
            <CardDescription>
              Percentage of monthly assessments settled against total scheduled dues.
            </CardDescription>
          </CardHeader>

          <CardContent className="space-y-6">
            <div className="text-center py-4">
              <span className="text-5xl font-black text-slate-900 dark:text-white tracking-tight">
                {collectionRate}%
              </span>
              <p className="text-xs text-muted-foreground mt-2 font-medium">
                Overall On-Time Dues Settlement Ratio
              </p>
            </div>

            <div className="space-y-2">
              <div className="flex justify-between text-xs font-semibold">
                <span>Current Performance</span>
                <span className="text-emerald-600 dark:text-emerald-400 font-bold">
                  {collectionRate}%
                </span>
              </div>
              <Progress value={collectionRate} className="h-3 bg-slate-100 dark:bg-slate-800" />
            </div>

            <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 text-xs text-muted-foreground space-y-1">
              <div className="flex items-center justify-between font-medium">
                <span>Automated Dues Recovery:</span>
                <span className="text-foreground font-semibold">Active</span>
              </div>
              <div className="flex items-center justify-between font-medium">
                <span>Grace Period Closes:</span>
                <span className="text-foreground font-semibold">End of Current Month</span>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Payment Methods Orchestration Share */}
        <Card className="md:col-span-7 shadow-sm border-slate-200 dark:border-slate-800">
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle className="text-lg font-bold flex items-center gap-2">
                <PieChart className="h-5 w-5 text-indigo-600" />
                Payment Method Orchestration Share
              </CardTitle>
              <Badge variant="outline" className="text-xs font-medium">
                All 6 Active Rails
              </Badge>
            </div>
            <CardDescription>
              Breakdown of incoming transaction volume across digital wallets, cards, and direct ACH.
            </CardDescription>
          </CardHeader>

          <CardContent className="space-y-4">
            {paymentMethodsBreakdown.map((method) => (
              <div key={method.name} className="space-y-1.5">
                <div className="flex items-center justify-between text-xs">
                  <div className="flex items-center gap-2 font-medium">
                    {getMethodIcon(method.name)}
                    <span className="text-slate-800 dark:text-slate-200">{method.name}</span>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="font-mono text-muted-foreground">
                      {money(method.amount, currency)}
                    </span>
                    <span className="font-bold text-foreground w-10 text-right">
                      {method.percent}%
                    </span>
                  </div>
                </div>
                <Progress value={method.percent} className="h-2 bg-slate-100 dark:bg-slate-800" />
              </div>
            ))}
          </CardContent>
        </Card>
      </div>

      {/* Delinquency Aging Analysis */}
      <Card className="shadow-sm border-slate-200 dark:border-slate-800">
        <CardHeader>
          <CardTitle className="text-lg font-bold flex items-center gap-2">
            <AlertTriangle className="h-5 w-5 text-amber-600" />
            Aging Delinquency Breakdown
          </CardTitle>
          <CardDescription>
            Categorization of past-due balances requiring automated reminder dispatches or board review.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {delinquencyAging.map((tier) => (
              <div
                key={tier.period}
                className="p-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30"
              >
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    {tier.period}
                  </span>
                  <Badge variant="secondary" className="text-[11px] font-semibold">
                    {tier.count} Accounts
                  </Badge>
                </div>
                <div className="text-2xl font-bold mt-2 text-foreground">
                  {money(tier.amount, currency)}
                </div>
                <p className="text-[11px] text-muted-foreground mt-1">
                  Automated notifications queued
                </p>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Payouts & Reconciliation Section */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Vendor Payouts Queue */}
        <Card className="shadow-sm border-slate-200 dark:border-slate-800">
          <CardHeader>
            <CardTitle className="text-lg font-bold flex items-center gap-2">
              <Building className="h-5 w-5 text-blue-600" />
              Vendor Payouts & Disbursements
            </CardTitle>
            <CardDescription>
              Scheduled disbursements to estate contractors, security, and maintenance vendors.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {payouts.length === 0 ? (
              <div className="py-8 text-center text-xs text-muted-foreground">
                No pending vendor payouts.
              </div>
            ) : (
              <div className="space-y-3">
                {payouts.map((payout) => (
                  <div
                    key={payout.id}
                    className="flex items-center justify-between p-3 rounded-lg border border-slate-100 dark:border-slate-800/60 bg-slate-50/50 dark:bg-slate-900/20"
                  >
                    <div>
                      <div className="text-xs font-bold text-foreground">{payout.vendor}</div>
                      <div className="text-[11px] text-muted-foreground">
                        {payout.category} &bull; Scheduled: {payout.scheduledFor}
                      </div>
                    </div>
                    <div className="text-right">
                      <div className="text-sm font-bold text-foreground">
                        {money(payout.amount, payout.currency)}
                      </div>
                      <Badge variant="outline" className="text-[10px] uppercase">
                        {payout.status}
                      </Badge>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Bank Reconciliation */}
        <Card className="shadow-sm border-slate-200 dark:border-slate-800">
          <CardHeader>
            <CardTitle className="text-lg font-bold flex items-center gap-2">
              <ShieldCheck className="h-5 w-5 text-emerald-600" />
              Bank & Ledger Reconciliation
            </CardTitle>
            <CardDescription>
              Periodic automated matching of bank statement feeds against the master ledger.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {reconciliations.length === 0 ? (
              <div className="py-8 text-center text-xs text-muted-foreground">
                Reconciliation statements up to date.
              </div>
            ) : (
              <div className="space-y-3">
                {reconciliations.map((rec) => (
                  <div
                    key={rec.id}
                    className="p-3 rounded-lg border border-slate-100 dark:border-slate-800/60 bg-slate-50/50 dark:bg-slate-900/20 space-y-2"
                  >
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2 text-xs font-bold text-foreground">
                        <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                        Statement: {rec.statementDate}
                      </div>
                      <Badge
                        className={
                          rec.status === 'matched'
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300'
                            : 'bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300'
                        }
                      >
                        <CheckCircle2 className="h-3 w-3 mr-1 inline" />
                        {rec.status.toUpperCase()}
                      </Badge>
                    </div>
                    <div className="grid grid-cols-3 gap-2 pt-1 border-t border-slate-100 dark:border-slate-800 text-[11px]">
                      <div>
                        <span className="text-muted-foreground block">Statement:</span>
                        <span className="font-bold text-foreground">
                          {money(rec.statementBalance, currency)}
                        </span>
                      </div>
                      <div>
                        <span className="text-muted-foreground block">Ledger:</span>
                        <span className="font-bold text-foreground">
                          {money(rec.ledgerBalance, currency)}
                        </span>
                      </div>
                      <div>
                        <span className="text-muted-foreground block">Variance:</span>
                        <span
                          className={`font-bold ${
                            rec.difference === 0 ? 'text-emerald-600' : 'text-rose-600'
                          }`}
                        >
                          {money(rec.difference, currency)}
                        </span>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
