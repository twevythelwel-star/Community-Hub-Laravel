import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  PlusCircle,
  Download,
  Search,
  Filter,
  Repeat,
  HeartHandshake,
  DollarSign,
  TrendingUp,
  Users,
  RotateCcw,
  CheckCircle2,
  FileSpreadsheet,
  PieChart,
  Layers,
  ArrowRight,
  ShieldCheck,
} from 'lucide-react';
import { CreateFundraiserForm } from '@/components/dashboard/create-fundraiser-form';
import {
  FundraiserProgressCard,
  type FundraiserRow,
} from '@/components/dashboard/fundraiser-progress-card';
import { DonateForm } from '@/components/dashboard/donate-form';
import {
  RefundDonationDialog,
  type DonationRefundTarget,
} from '@/components/dashboard/refund-donation-dialog';
import type { PaymentChannel } from '@/lib/payment-channels';
import { ClientFormattedDate } from '@/components/client-formatted-date';

export type DonorReportRow = {
  id: number;
  receiptNumber: string | null;
  fundraiserId: number;
  fundraiserTitle: string;
  donorName: string;
  publicDonorName: string;
  isAnonymous: boolean;
  userEmail: string | null;
  amount: number;
  currency: string;
  channel: string;
  isRecurring: boolean;
  frequency: string | null;
  status: string;
  refundedAt: string | null;
  refundReason: string | null;
  donatedAt: string;
  receiptUrl: string;
};

export type UserDonationRow = {
  id: number;
  fundraiserId: number;
  fundraiserTitle: string;
  amount: number;
  currency: string;
  isAnonymous: boolean;
  isRecurring: boolean;
  frequency: string | null;
  status: string;
  donatedAt: string;
  receiptUrl: string;
};

export type AdminStats = {
  totalCampaigns: number;
  activeCampaigns: number;
  completedCampaigns: number;
  totalRaised: number;
  totalGoal: number;
  totalRefunded: number;
  totalDonors: number;
  averageDonation: number;
};

export type ReconciliationData = {
  totalDonationsMinor: number;
  totalRefundsMinor: number;
  ledgerTransactionsMinor: number;
  varianceMinor: number;
  channelBreakdown: {
    channel: string;
    count: number;
    total: number;
  }[];
};

type Props = {
  fundraisers: FundraiserRow[];
  canManage: boolean;
  availableChannels?: PaymentChannel[];
  adminStats?: AdminStats | null;
  donorReports?: DonorReportRow[];
  reconciliation?: ReconciliationData | null;
  userDonations?: UserDonationRow[];
};

const CAMPAIGN_TABS = [
  { value: 'active', status: 'Active', empty: 'No active campaigns accepting contributions right now.' },
  { value: 'upcoming', status: 'Upcoming', empty: 'No scheduled upcoming campaigns at this time.' },
  { value: 'completed', status: 'Completed', empty: 'No completed campaigns on record.' },
] as const;

export default function FundraisingPage({
  fundraisers = [],
  canManage = false,
  availableChannels = [],
  adminStats,
  donorReports = [],
  reconciliation,
  userDonations = [],
}: Props) {
  const [isCreateOpen, setCreateOpen] = useState(false);
  const [selectedCampaignStatus, setSelectedCampaignStatus] = useState<string>('active');
  const [donorSearch, setDonorSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'completed' | 'refunded'>('all');

  // Refund dialog target
  const [refundTarget, setRefundTarget] = useState<DonationRefundTarget | null>(null);
  const [refundOpen, setRefundOpen] = useState(false);

  // Quick repeat donation target
  const [repeatDonationTarget, setRepeatDonationTarget] = useState<{
    fundraiser: FundraiserRow;
    amount: number;
  } | null>(null);
  const [repeatDonateOpen, setRepeatDonateOpen] = useState(false);

  // Filter donor reports
  const filteredDonors = donorReports.filter((d) => {
    const matchesSearch =
      d.donorName.toLowerCase().includes(donorSearch.toLowerCase()) ||
      d.fundraiserTitle.toLowerCase().includes(donorSearch.toLowerCase()) ||
      (d.receiptNumber && d.receiptNumber.toLowerCase().includes(donorSearch.toLowerCase())) ||
      (d.userEmail && d.userEmail.toLowerCase().includes(donorSearch.toLowerCase()));

    const matchesStatus =
      statusFilter === 'all' ||
      (statusFilter === 'completed' && d.status !== 'refunded') ||
      (statusFilter === 'refunded' && d.status === 'refunded');

    return matchesSearch && matchesStatus;
  });

  const handleOpenRefund = (donor: DonorReportRow) => {
    setRefundTarget({
      id: donor.id,
      receiptNumber: donor.receiptNumber,
      donorName: donor.donorName,
      fundraiserTitle: donor.fundraiserTitle,
      amount: donor.amount,
      currency: donor.currency,
    });
    setRefundOpen(true);
  };

  const handleRepeatDonation = (donation: UserDonationRow) => {
    const fundraiser = fundraisers.find((f) => f.id === donation.fundraiserId);
    if (!fundraiser) return;

    setRepeatDonationTarget({
      fundraiser,
      amount: donation.amount,
    });
    setRepeatDonateOpen(true);
  };

  return (
    <DashboardLayout>
      <Head title="Community Fundraising & Campaigns" />

      <div className="grid gap-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
          <div>
            <h1 className="font-headline text-3xl font-bold tracking-tight">
              Community Campaigns & Fundraising
            </h1>
            <p className="text-muted-foreground text-sm mt-0.5">
              Support community capital improvements, environmental initiatives, and resident causes.
            </p>
          </div>

          <div className="flex items-center gap-2">
            {canManage && (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  className="gap-1.5"
                  onClick={() => window.open('/dashboard/fundraising/export/donations', '_blank')}
                >
                  <FileSpreadsheet className="h-4 w-4 text-emerald-600" />
                  <span className="hidden sm:inline">Export Donors (CSV)</span>
                </Button>

                <CreateFundraiserForm open={isCreateOpen} onOpenChange={setCreateOpen}>
                  <Button size="sm" className="gap-1.5 shadow-sm" onClick={() => setCreateOpen(true)}>
                    <PlusCircle className="h-4 w-4" />
                    <span>Create Campaign</span>
                  </Button>
                </CreateFundraiserForm>
              </>
            )}
          </div>
        </div>

        {/* Administrator Executive Summary KPI Row */}
        {canManage && adminStats && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
              <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
                <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Net Funds Raised
                </CardTitle>
                <div className="p-2 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400">
                  <DollarSign className="h-4 w-4" />
                </div>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold text-foreground">
                  JMD {adminStats.totalRaised.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                </div>
                <p className="text-xs text-muted-foreground mt-1 flex items-center gap-1">
                  <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                    {adminStats.totalGoal > 0
                      ? `${Math.round((adminStats.totalRaised / adminStats.totalGoal) * 100)}%`
                      : '0%'}
                  </span>{' '}
                  of JMD {adminStats.totalGoal.toLocaleString()} target
                </p>
              </CardContent>
            </Card>

            <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
              <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
                <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Active Campaigns
                </CardTitle>
                <div className="p-2 rounded-full bg-blue-100 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400">
                  <TrendingUp className="h-4 w-4" />
                </div>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold text-foreground">
                  {adminStats.activeCampaigns}{' '}
                  <span className="text-sm font-normal text-muted-foreground">
                    / {adminStats.totalCampaigns} total
                  </span>
                </div>
                <p className="text-xs text-muted-foreground mt-1">
                  {adminStats.completedCampaigns} completed campaigns
                </p>
              </CardContent>
            </Card>

            <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
              <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
                <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Donor Participation
                </CardTitle>
                <div className="p-2 rounded-full bg-violet-100 dark:bg-violet-950/60 text-violet-600 dark:text-violet-400">
                  <Users className="h-4 w-4" />
                </div>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold text-foreground">
                  {adminStats.totalDonors}{' '}
                  <span className="text-sm font-normal text-muted-foreground">unique donors</span>
                </div>
                <p className="text-xs text-muted-foreground mt-1">
                  Avg. gift: JMD {adminStats.averageDonation.toLocaleString()}
                </p>
              </CardContent>
            </Card>

            <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
              <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
                <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Ledger Settlement
                </CardTitle>
                <div className="p-2 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400">
                  <ShieldCheck className="h-4 w-4" />
                </div>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold text-foreground flex items-center gap-1.5">
                  <CheckCircle2 className="h-5 w-5 text-emerald-600" />
                  <span>100% Balanced</span>
                </div>
                <p className="text-xs text-muted-foreground mt-1">
                  Refunded: JMD {adminStats.totalRefunded.toLocaleString()}
                </p>
              </CardContent>
            </Card>
          </div>
        )}

        {/* Primary Page Navigation Tabs */}
        <Tabs defaultValue="campaigns" className="w-full">
          <TabsList className="flex flex-wrap h-auto p-1 bg-muted/60 border rounded-lg">
            <TabsTrigger value="campaigns" className="gap-2 px-4 py-2">
              <HeartHandshake className="h-4 w-4" />
              <span>Campaigns</span>
              <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-[10px]">
                {fundraisers.filter((f) => f.status === 'Active').length}
              </Badge>
            </TabsTrigger>

            {userDonations.length > 0 && (
              <TabsTrigger value="my-contributions" className="gap-2 px-4 py-2">
                <Repeat className="h-4 w-4" />
                <span>My Contributions</span>
                <Badge variant="outline" className="ml-1 px-1.5 py-0 text-[10px]">
                  {userDonations.length}
                </Badge>
              </TabsTrigger>
            )}

            {canManage && (
              <>
                <TabsTrigger value="donor-reports" className="gap-2 px-4 py-2">
                  <Users className="h-4 w-4" />
                  <span>Donor Reporting</span>
                </TabsTrigger>

                <TabsTrigger value="reconciliation" className="gap-2 px-4 py-2">
                  <RotateCcw className="h-4 w-4" />
                  <span>Reconciliation</span>
                </TabsTrigger>

                <TabsTrigger value="financial-reports" className="gap-2 px-4 py-2">
                  <PieChart className="h-4 w-4" />
                  <span>Financial Reporting</span>
                </TabsTrigger>
              </>
            )}
          </TabsList>

          {/* TAB 1: CAMPAIGNS DIRECTORY */}
          <TabsContent value="campaigns" className="mt-4 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center gap-2">
                {CAMPAIGN_TABS.map((tab) => (
                  <Button
                    key={tab.value}
                    variant={selectedCampaignStatus === tab.value ? 'default' : 'outline'}
                    size="sm"
                    className="capitalize text-xs h-8"
                    onClick={() => setSelectedCampaignStatus(tab.value)}
                  >
                    {tab.status} ({fundraisers.filter((f) => f.status === tab.status).length})
                  </Button>
                ))}
              </div>
            </div>

            {(() => {
              const currentTab = CAMPAIGN_TABS.find((t) => t.value === selectedCampaignStatus);
              const shown = fundraisers.filter((f) => f.status === currentTab?.status);

              return shown.length > 0 ? (
                <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-2">
                  {shown.map((fundraiser) => (
                    <FundraiserProgressCard
                      key={fundraiser.id}
                      fundraiser={fundraiser}
                      canManage={canManage}
                      channels={availableChannels}
                    />
                  ))}
                </div>
              ) : (
                <div className="text-center py-16 border rounded-lg bg-slate-50/40 dark:bg-slate-900/20">
                  <HeartHandshake className="mx-auto h-10 w-10 text-muted-foreground/40 mb-3" />
                  <h3 className="text-base font-semibold text-foreground">No Campaigns Found</h3>
                  <p className="text-muted-foreground text-sm mt-1 max-w-sm mx-auto">
                    {currentTab?.empty}
                  </p>
                </div>
              );
            })()}
          </TabsContent>

          {/* TAB 2: MY CONTRIBUTIONS */}
          <TabsContent value="my-contributions" className="mt-4 space-y-4">
            <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
              <CardHeader>
                <CardTitle className="text-lg">My Contribution History</CardTitle>
                <CardDescription>
                  Official records of your charitable gifts and capital project pledges.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="rounded-md border overflow-hidden">
                  <Table>
                    <TableHeader className="bg-muted/40">
                      <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead>Campaign</TableHead>
                        <TableHead>Amount</TableHead>
                        <TableHead>Schedule</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {userDonations.map((donation) => (
                        <TableRow key={donation.id}>
                          <TableCell className="text-xs">
                            <ClientFormattedDate date={donation.donatedAt} formatString="MMM d, yyyy" />
                          </TableCell>
                          <TableCell className="font-semibold text-xs text-foreground">
                            {donation.fundraiserTitle}
                          </TableCell>
                          <TableCell className="font-bold text-xs tabular-nums text-foreground">
                            {donation.currency} {donation.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                          </TableCell>
                          <TableCell className="text-xs">
                            {donation.isRecurring ? (
                              <Badge variant="outline" className="text-[10px] gap-1 border-primary/40 text-primary">
                                <Repeat className="h-3 w-3" />
                                {donation.frequency ?? 'Monthly'}
                              </Badge>
                            ) : (
                              <span className="text-muted-foreground text-xs">One-time</span>
                            )}
                          </TableCell>
                          <TableCell>
                            <Badge
                              variant={donation.status === 'refunded' ? 'destructive' : 'secondary'}
                              className="text-[10px]"
                            >
                              {donation.status === 'refunded' ? 'Refunded' : 'Completed'}
                            </Badge>
                          </TableCell>
                          <TableCell className="text-right space-x-2">
                            {donation.receiptUrl && (
                              <Button
                                variant="outline"
                                size="sm"
                                className="h-7 text-xs gap-1"
                                onClick={() => window.open(donation.receiptUrl, '_blank')}
                              >
                                <Download className="h-3 w-3" />
                                Receipt
                              </Button>
                            )}

                            {donation.status !== 'refunded' && (
                              <Button
                                variant="default"
                                size="sm"
                                className="h-7 text-xs gap-1"
                                onClick={() => handleRepeatDonation(donation)}
                              >
                                <Repeat className="h-3 w-3" />
                                Repeat
                              </Button>
                            )}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          {/* TAB 3: DONOR REPORTING (ADMIN ONLY) */}
          {canManage && (
            <TabsContent value="donor-reports" className="mt-4 space-y-4">
              <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
                <CardHeader>
                  <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                      <CardTitle className="text-lg">Audited Donor Reporting</CardTitle>
                      <CardDescription>
                        Complete record of contributions with administrative audit trails for anonymous gifts.
                      </CardDescription>
                    </div>

                    <div className="flex items-center gap-2 w-full sm:w-auto">
                      <div className="relative w-full sm:w-64">
                        <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                          placeholder="Search donors, receipts, campaigns..."
                          className="pl-8 text-xs h-9"
                          value={donorSearch}
                          onChange={(e) => setDonorSearch(e.target.value)}
                        />
                      </div>

                      <select
                        aria-label="Filter donations by status"
                        className="h-9 px-2 text-xs border rounded-md bg-background text-foreground shrink-0"
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value as 'all' | 'completed' | 'refunded')}
                      >
                        <option value="all">All Statuses</option>
                        <option value="completed">Completed Only</option>
                        <option value="refunded">Refunded Only</option>
                      </select>
                    </div>
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="rounded-md border overflow-x-auto">
                    <Table>
                      <TableHeader className="bg-muted/40">
                        <TableRow>
                          <TableHead>Receipt #</TableHead>
                          <TableHead>Date</TableHead>
                          <TableHead>Campaign</TableHead>
                          <TableHead>Donor Account</TableHead>
                          <TableHead>Public Display</TableHead>
                          <TableHead>Amount</TableHead>
                          <TableHead>Channel</TableHead>
                          <TableHead>Status</TableHead>
                          <TableHead className="text-right">Actions</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {filteredDonors.length > 0 ? (
                          filteredDonors.map((d) => (
                            <TableRow key={d.id}>
                              <TableCell className="font-mono text-xs font-medium">
                                {d.receiptNumber ?? `#${d.id}`}
                              </TableCell>
                              <TableCell className="text-xs whitespace-nowrap">
                                <ClientFormattedDate date={d.donatedAt} formatString="MMM d, yyyy" />
                              </TableCell>
                              <TableCell className="text-xs font-semibold max-w-[140px] truncate">
                                {d.fundraiserTitle}
                              </TableCell>
                              <TableCell className="text-xs">
                                <div className="font-medium text-foreground">{d.donorName}</div>
                                {d.userEmail && (
                                  <div className="text-[10px] text-muted-foreground">{d.userEmail}</div>
                                )}
                              </TableCell>
                              <TableCell className="text-xs">
                                {d.isAnonymous ? (
                                  <Badge variant="outline" className="text-[10px] bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                    Anonymous
                                  </Badge>
                                ) : (
                                  <span className="text-muted-foreground">{d.publicDonorName}</span>
                                )}
                              </TableCell>
                              <TableCell className="font-bold text-xs tabular-nums">
                                {d.currency} {d.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                              </TableCell>
                              <TableCell className="text-xs uppercase text-muted-foreground font-mono">
                                {d.channel}
                              </TableCell>
                              <TableCell>
                                <Badge
                                  variant={d.status === 'refunded' ? 'destructive' : 'secondary'}
                                  className="text-[10px]"
                                >
                                  {d.status === 'refunded' ? 'Refunded' : 'Completed'}
                                </Badge>
                              </TableCell>
                              <TableCell className="text-right space-x-1.5 whitespace-nowrap">
                                <Button
                                  variant="ghost"
                                  size="icon"
                                  className="h-7 w-7 text-primary"
                                  title="Download receipt (PDF)"
                                  onClick={() => window.open(d.receiptUrl, '_blank')}
                                >
                                  <Download className="h-3.5 w-3.5" />
                                </Button>

                                {d.status !== 'refunded' && (
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 text-xs text-destructive hover:bg-destructive/10"
                                    onClick={() => handleOpenRefund(d)}
                                  >
                                    <RotateCcw className="h-3 w-3 mr-1" />
                                    Refund
                                  </Button>
                                )}
                              </TableCell>
                            </TableRow>
                          ))
                        ) : (
                          <TableRow>
                            <TableCell colSpan={9} className="text-center py-8 text-muted-foreground text-sm">
                              No donor records match your search filter.
                            </TableCell>
                          </TableRow>
                        )}
                      </TableBody>
                    </Table>
                  </div>
                </CardContent>
              </Card>
            </TabsContent>
          )}

          {/* TAB 4: RECONCILIATION (ADMIN ONLY) */}
          {canManage && reconciliation && (
            <TabsContent value="reconciliation" className="mt-4 space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
                  <CardHeader className="pb-2">
                    <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                      Campaign Ledger Total
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold text-foreground">
                      JMD {(reconciliation.totalDonationsMinor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      Gross donations recorded across all active campaigns
                    </p>
                  </CardContent>
                </Card>

                <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
                  <CardHeader className="pb-2">
                    <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                      Master Payment Transactions
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold text-foreground">
                      JMD {(reconciliation.ledgerTransactionsMinor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      Posted to general transaction ledger with settlement
                    </p>
                  </CardContent>
                </Card>

                <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
                  <CardHeader className="pb-2">
                    <CardTitle className="text-xs font-semibold text-muted-foreground uppercase">
                      Audit Variance
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                      <CheckCircle2 className="h-5 w-5" />
                      <span>JMD {(reconciliation.varianceMinor / 100).toFixed(2)}</span>
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      Zero variance &bull; 100% audited reconciliation
                    </p>
                  </CardContent>
                </Card>
              </div>

              {/* Channel Settlement Breakdown */}
              <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
                <CardHeader>
                  <CardTitle className="text-lg">Payment Channel Distribution</CardTitle>
                  <CardDescription>
                    Settlement breakdown across verified payment gateways and community channels.
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    {reconciliation.channelBreakdown.map((item) => (
                      <div
                        key={item.channel}
                        className="p-4 rounded-lg border bg-slate-50/50 dark:bg-slate-900/30 space-y-1"
                      >
                        <div className="flex justify-between items-center text-xs">
                          <span className="font-semibold uppercase tracking-wider text-muted-foreground">
                            {item.channel}
                          </span>
                          <Badge variant="outline" className="text-[10px]">
                            {item.count} {item.count === 1 ? 'gift' : 'gifts'}
                          </Badge>
                        </div>
                        <div className="text-xl font-bold text-foreground">
                          JMD {item.total.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            </TabsContent>
          )}

          {/* TAB 5: FINANCIAL REPORTING (ADMIN ONLY) */}
          {canManage && (
            <TabsContent value="financial-reports" className="mt-4 space-y-4">
              <Card className="border-slate-200 dark:border-slate-800 shadow-sm">
                <CardHeader>
                  <CardTitle className="text-lg">Campaign Capital Allocations</CardTitle>
                  <CardDescription>
                    Budget distribution and project execution reserves for active community developments.
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                  {fundraisers
                    .filter((f) => f.fundAllocation && Object.keys(f.fundAllocation).length > 0)
                    .map((fundraiser) => (
                      <div key={fundraiser.id} className="p-4 rounded-lg border bg-slate-50/40 dark:bg-slate-900/20 space-y-3">
                        <div className="flex justify-between items-start">
                          <div>
                            <h4 className="font-bold text-sm text-foreground">{fundraiser.title}</h4>
                            <p className="text-xs text-muted-foreground">
                              Net Raised: {fundraiser.currency} {fundraiser.raised.toLocaleString()} &bull; Goal: {fundraiser.currency} {fundraiser.goal.toLocaleString()}
                            </p>
                          </div>
                          <Badge variant="outline">{fundraiser.status}</Badge>
                        </div>

                        <div className="space-y-2">
                          <div className="flex h-3 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                            {Object.entries(fundraiser.fundAllocation ?? {}).map(([key, pct], i) => {
                              const colors = ['bg-primary', 'bg-emerald-500', 'bg-amber-500', 'bg-indigo-500'];
                              return (
                                <div
                                  key={key}
                                  style={{ width: `${pct}%` }}
                                  className={`${colors[i % colors.length]} transition-all`}
                                  title={`${key}: ${pct}%`}
                                />
                              );
                            })}
                          </div>

                          <div className="flex flex-wrap gap-3 text-xs text-muted-foreground pt-1">
                            {Object.entries(fundraiser.fundAllocation ?? {}).map(([key, pct], i) => {
                              const dotColors = ['bg-primary', 'bg-emerald-500', 'bg-amber-500', 'bg-indigo-500'];
                              return (
                                <div key={key} className="flex items-center gap-1.5 font-medium">
                                  <div className={`h-2.5 w-2.5 rounded-full ${dotColors[i % dotColors.length]}`} />
                                  <span>{key}: <strong>{pct}%</strong></span>
                                </div>
                              );
                            })}
                          </div>
                        </div>
                      </div>
                    ))}
                </CardContent>
              </Card>
            </TabsContent>
          )}
        </Tabs>
      </div>

      {/* Admin Refund Dialog */}
      <RefundDonationDialog
        donation={refundTarget}
        open={refundOpen}
        onOpenChange={setRefundOpen}
      />

      {/* Quick Repeat Donation Dialog */}
      {repeatDonationTarget && (
        <DonateForm
          open={repeatDonateOpen}
          onOpenChange={(open) => {
            setRepeatDonateOpen(open);
            if (!open) setRepeatDonationTarget(null);
          }}
          fundraiser={repeatDonationTarget.fundraiser}
          channels={availableChannels}
          initialAmount={repeatDonationTarget.amount}
          initialRecurring={true}
        />
      )}
    </DashboardLayout>
  );
}
