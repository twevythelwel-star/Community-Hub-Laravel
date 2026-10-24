import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import dynamic from '@/lib/dynamic';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import {
  BillingSummary,
  type InvoiceRow,
  type Paginated,
} from '@/components/dashboard/billing-summary';
import {
  PaymentCenterHero,
  type PaymentCenterProps,
} from '@/components/dashboard/payment-center-hero';
import { type WalletData } from '@/components/dashboard/community-wallet-card';
import {
  PaymentLinksManager,
  type PaymentLinkItem,
} from '@/components/dashboard/payment-links-manager';
import {
  TransactionsLedger,
  type MasterTransaction,
  type OfficePayment,
} from '@/components/dashboard/transactions-ledger';
import { InPersonPayments, type InPersonProps } from '@/components/dashboard/in-person-payments';
import { OutstandingTab } from '@/components/dashboard/billing/outstanding-tab';
import { PaymentPlansTab } from '@/components/dashboard/billing/payment-plans-tab';
import { AutoPayTab } from '@/components/dashboard/billing/autopay-tab';
import { ReceiptsTab } from '@/components/dashboard/billing/receipts-tab';
import { RefundsTab } from '@/components/dashboard/billing/refunds-tab';
import { ReconciliationTab } from '@/components/dashboard/billing/reconciliation-tab';
import { PaymentChannelsTab } from '@/components/dashboard/billing/payment-channels-tab';
import { PaymentPlanDialog } from '@/components/dashboard/billing/payment-plan-dialog';
import { ReconciliationDialog } from '@/components/dashboard/billing/reconciliation-dialog';
import type {
  AutoPayPortfolio,
  ChannelReport,
  LedgerAccount,
  OutstandingItem,
  PaymentEventItem,
  PaymentPlanItem,
  ReceiptItem,
  RefundItem,
  TriPartyReconciliation,
} from '@/components/dashboard/billing/types';
import {
  CreditCard,
  Receipt,
  Link2,
  FileText,
  BarChart3,
  AlertCircle,
  Calendar,
  RefreshCw,
  Download,
  RotateCcw,
  Scale,
  Cpu,
  CheckCircle2,
} from 'lucide-react';

type AdminBillingProps = {
  settings: { monthlyFee: number; currency: string; dueDayOfMonth: number };
  adminSummary: {
    totalOutstanding: number;
    overdueCount: number;
    collectedThisMonth: number;
    householdsPaidThisMonth: number;
    householdsOutstanding: number;
  };
  monthlyCollections: { month: string; total: number }[];
  invoices: Paginated<InvoiceRow & { homeowner: string | null; lot: string | null }>;
};

const AdminBilling = dynamic<AdminBillingProps>(
  () =>
    import('@/components/dashboard/admin-billing').then((module) => ({
      default: module.AdminBilling,
    })),
  { loading: () => <Skeleton className="h-96 w-full" /> },
);

const AdminFinancialDashboard = dynamic<any>(
  () =>
    import('@/components/dashboard/admin-financial-dashboard').then((module) => ({
      default: module.AdminFinancialDashboard,
    })),
  { loading: () => <Skeleton className="h-96 w-full" /> },
);

type Props = {
  settings: { monthlyFee: number; currency: string; dueDayOfMonth: number };
  myInvoices: Paginated<InvoiceRow>;
  summary: {
    outstanding: number;
    paidThisYear: number;
    paidCountThisYear: number;
    year: number;
  };
  adminSummary: {
    totalOutstanding: number;
    overdueCount: number;
    collectedThisMonth: number;
    householdsPaidThisMonth: number;
    householdsOutstanding: number;
  } | null;
  monthlyCollections: { month: string; total: number }[] | null;
  invoices: Paginated<InvoiceRow & { homeowner: string | null; lot: string | null }> | null;
  canManage: boolean;

  // New Revenue Engine props
  financialDashboard?: any;
  paymentCenter?: PaymentCenterProps;
  wallet?: WalletData;
  paymentLinks?: PaymentLinkItem[];
  transactions?: Paginated<MasterTransaction>;
  /** Office payments in flight: every one for an administrator, a resident's own otherwise. */
  pendingPayments?: OfficePayment[];
  /** Administrators only: in-person card readers and what is on them. */
  inPerson?: InPersonProps | null;
  payouts?: any[];
  reconciliations?: any[];
  paymentEvents?: PaymentEventItem[];

  // 10 Views & Payment Orchestration Engine props
  outstanding?: OutstandingItem[];
  paymentPlans?: PaymentPlanItem[];
  receipts?: ReceiptItem[];
  refundsList?: RefundItem[];
  autoPayPortfolio?: AutoPayPortfolio;
  paymentChannels?: ChannelReport[];
  triPartyReconciliation?: TriPartyReconciliation | null;
  chartOfAccounts?: LedgerAccount[];
};

export default function BillingPage({
  settings,
  myInvoices,
  summary,
  adminSummary,
  monthlyCollections,
  invoices,
  canManage,
  financialDashboard,
  paymentCenter,
  wallet,
  paymentLinks = [],
  transactions,
  pendingPayments = [],
  inPerson = null,
  payouts = [],
  reconciliations = [],
  paymentEvents = [],
  outstanding = [],
  paymentPlans = [],
  receipts = [],
  refundsList = [],
  autoPayPortfolio,
  paymentChannels = [],
  triPartyReconciliation,
  chartOfAccounts = [],
}: Props) {
  const { toast } = useToast();
  const isAdmin = canManage && !!adminSummary && !!monthlyCollections && !!invoices;
  const [activeTab, setActiveTab] = useState(isAdmin ? 'dashboard' : 'invoices');

  // Which dialog is open, and the statement chosen for a plan (the Outstanding tab can preselect it).
  const [isPlanModalOpen, setIsPlanModalOpen] = useState(false);
  const [selectedInvoiceForPlan, setSelectedInvoiceForPlan] = useState<string>('');
  const [isReconModalOpen, setIsReconModalOpen] = useState(false);

  return (
    <DashboardLayout>
      <Head title="Billing & Revenue Engine — Cypress Bay" />

      <div className="space-y-6">
        {/* Module Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <h1 className="font-headline text-3xl font-bold tracking-tight text-foreground">
                Billing &amp; Treasury Engine
              </h1>
              <Badge variant="outline" className="font-mono text-xs border-emerald-500/40 text-emerald-600 bg-emerald-500/10">
                NON-CUSTODIAL LEDGER
              </Badge>
            </div>
            <p className="text-muted-foreground text-sm mt-1">
              Multi-channel payment orchestration, automated reconciliation, and resident accounts ledger.
            </p>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                router.post('/dashboard/billing/portal', {}, {
                  preserveScroll: true,
                  onError: (err) => {
                    toast({
                      variant: 'destructive',
                      title: 'Customer Portal Unavailable',
                      description: (Object.values(err)[0] as string) || 'Unable to redirect to Stripe Customer Portal.',
                    });
                  },
                });
              }}
              className="gap-2 text-xs font-semibold border-emerald-500/30 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
              title="Manage payment cards, billing address, and download past invoices via Stripe"
            >
              <CreditCard className="h-4 w-4" />
              <span>Stripe Customer Portal</span>
            </Button>

            <Button
              variant="outline"
              size="sm"
              onClick={() => setActiveTab('orchestration')}
              className="gap-2 text-xs font-semibold border-indigo-500/30 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/40"
            >
              <Cpu className="h-4 w-4" />
              <span>Payment Engine (10 Channels)</span>
            </Button>

            {isAdmin && (
              <Button
                variant="outline"
                size="sm"
                asChild
                className="gap-1.5 text-xs font-medium"
              >
                <a href={route('dashboard.billing.transactions.export')}>
                  <Download className="h-3.5 w-3.5" />
                  <span>Export Master Ledger</span>
                </a>
              </Button>
            )}
          </div>
        </div>

        {paymentCenter && (
          <PaymentCenterHero
            amountDue={paymentCenter.amountDue}
            currency={settings.currency}
            itemizedCharges={paymentCenter.itemizedCharges || []}
            walletBalance={wallet?.totalUsable || wallet?.available || 0}
            onNavigateTab={setActiveTab}
            availableChannels={paymentCenter.availableChannels}
            autoPay={paymentCenter.autoPay}
          />
        )}

        {/* 10 Views Tabbed Navigation */}
        <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
          <div className="overflow-x-auto pb-1">
            <TabsList className="h-11 bg-slate-100/90 dark:bg-slate-900/90 p-1 border border-slate-200 dark:border-slate-800 rounded-xl flex items-center w-max gap-1">
              {isAdmin && (
                <TabsTrigger value="dashboard" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                  <BarChart3 className="h-3.5 w-3.5 text-indigo-500" />
                  Dashboard
                </TabsTrigger>
              )}

              <TabsTrigger value="invoices" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                <FileText className="h-3.5 w-3.5 text-blue-500" />
                Invoices
              </TabsTrigger>

              <TabsTrigger value="outstanding" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                <AlertCircle className="h-3.5 w-3.5 text-amber-500" />
                Outstanding
              </TabsTrigger>

              <TabsTrigger value="payment-plans" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                <Calendar className="h-3.5 w-3.5 text-emerald-500" />
                Payment Plans
              </TabsTrigger>

              <TabsTrigger value="autopay" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                <RefreshCw className="h-3.5 w-3.5 text-cyan-500" />
                AutoPay
              </TabsTrigger>

              <TabsTrigger value="payment-links" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                <Link2 className="h-3.5 w-3.5 text-purple-500" />
                Payment Links
              </TabsTrigger>

              <TabsTrigger value="receipts" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                Receipts
              </TabsTrigger>

              <TabsTrigger value="transactions" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                <Receipt className="h-3.5 w-3.5 text-slate-500" />
                Transactions
              </TabsTrigger>

              <TabsTrigger value="refunds" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                <RotateCcw className="h-3.5 w-3.5 text-rose-500" />
                Refunds
              </TabsTrigger>

              <TabsTrigger value="reconciliation" className="gap-1.5 text-xs font-semibold px-3 py-1.5">
                <Scale className="h-3.5 w-3.5 text-violet-500" />
                Reconciliation
              </TabsTrigger>

              <TabsTrigger value="orchestration" className="gap-1.5 text-xs font-semibold px-3 py-1.5 text-indigo-600 dark:text-indigo-400">
                <Cpu className="h-3.5 w-3.5" />
                Payment Engine
              </TabsTrigger>
            </TabsList>
          </div>

          {/* 1. Dashboard View */}
          {isAdmin && (
            <TabsContent value="dashboard" className="space-y-6 m-0">
              {financialDashboard ? (
                <AdminFinancialDashboard
                  financialDashboard={financialDashboard}
                  payouts={payouts}
                  reconciliations={reconciliations}
                  currency={settings.currency}
                />
              ) : (
                <div className="p-8 text-center border rounded-xl bg-card text-muted-foreground">
                  Financial Analytics Initializing...
                </div>
              )}
            </TabsContent>
          )}

          {/* 2. Invoices View */}
          <TabsContent value="invoices" className="space-y-6 m-0">
            {isAdmin && adminSummary && monthlyCollections && invoices ? (
              <AdminBilling
                settings={settings}
                adminSummary={adminSummary}
                monthlyCollections={monthlyCollections}
                invoices={invoices}
              />
            ) : (
              <BillingSummary
                settings={settings}
                summary={summary}
                invoices={myInvoices}
                availableChannels={paymentCenter?.availableChannels || []}
                walletBalance={wallet?.totalUsable || wallet?.available || 0}
              />
            )}
          </TabsContent>

          {/* 3. Outstanding View */}
          <TabsContent value="outstanding" className="space-y-6 m-0">
            <OutstandingTab
              outstanding={outstanding}
              isAdmin={isAdmin}
              onCreatePlan={(invoiceId) => {
                setSelectedInvoiceForPlan(invoiceId.toString());
                setIsPlanModalOpen(true);
              }}
            />
          </TabsContent>

          {/* 4. Payment Plans View */}
          <TabsContent value="payment-plans" className="space-y-6 m-0">
            <PaymentPlansTab paymentPlans={paymentPlans} currency={settings.currency} isAdmin={isAdmin} onCreatePlan={() => setIsPlanModalOpen(true)} />
          </TabsContent>

          {/* 5. AutoPay View */}
          <TabsContent value="autopay" className="space-y-6 m-0">
            <AutoPayTab autoPay={paymentCenter?.autoPay} portfolio={autoPayPortfolio} isAdmin={isAdmin} />
          </TabsContent>

          {/* 6. Payment Links View */}
          <TabsContent value="payment-links" className="space-y-6 m-0">
            <PaymentLinksManager
              links={paymentLinks}
              canManage={isAdmin}
              currency={settings.currency}
            />
          </TabsContent>

          {/* 7. Receipts View */}
          <TabsContent value="receipts" className="space-y-6 m-0">
            <ReceiptsTab receipts={receipts} />
          </TabsContent>

          {/* 8. Transactions View */}
          <TabsContent value="transactions" className="space-y-6 m-0">
            {transactions ? (
              <TransactionsLedger
                transactions={transactions}
                pendingPayments={pendingPayments}
                currency={settings.currency}
              />
            ) : (
              <div className="py-12 text-center text-muted-foreground">
                No ledger records available.
              </div>
            )}

            {isAdmin && inPerson && (
              <InPersonPayments
                inPerson={inPerson}
                openInvoices={(invoices?.data ?? [])
                  .filter((i) => i.status === 'Unpaid' || i.status === 'Overdue')
                  .map((i) => ({ id: i.id, reference: i.reference, amount: i.amount, currency: i.currency, homeowner: i.homeowner }))}
              />
            )}
          </TabsContent>

          {/* 9. Refunds View */}
          <TabsContent value="refunds" className="space-y-6 m-0">
            <RefundsTab refunds={refundsList} />
          </TabsContent>

          {/* 10. Reconciliation View */}
          <TabsContent value="reconciliation" className="space-y-6 m-0">
            <ReconciliationTab
              triParty={triPartyReconciliation}
              reconciliations={reconciliations}
              payouts={payouts}
              paymentEvents={paymentEvents}
              chartOfAccounts={chartOfAccounts}
              currency={settings.currency}
              onNewReconciliation={() => setIsReconModalOpen(true)}
            />
          </TabsContent>

          {/* 11. Payment Orchestration Engine (The 10 Channels) */}
          <TabsContent value="orchestration" className="space-y-6 m-0">
            <PaymentChannelsTab channels={paymentChannels} isAdmin={isAdmin} />
          </TabsContent>
        </Tabs>
      </div>

      <PaymentPlanDialog
        open={isPlanModalOpen}
        onOpenChange={setIsPlanModalOpen}
        outstanding={outstanding}
        invoiceId={selectedInvoiceForPlan}
        onInvoiceIdChange={setSelectedInvoiceForPlan}
      />

      <ReconciliationDialog
        open={isReconModalOpen}
        onOpenChange={setIsReconModalOpen}
        currency={settings.currency}
        pendingPayments={pendingPayments}
      />
    </DashboardLayout>
  );
}
