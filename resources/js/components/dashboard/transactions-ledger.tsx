import { useState } from 'react';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Receipt,
  Download,
  Search,
  Filter,
  CreditCard,
  Building,
  Smartphone,
  CheckCircle2,
  Clock,
  RotateCcw,
  AlertCircle,
  FileSpreadsheet,
  XCircle,
  ShieldCheck,
} from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/hooks/use-toast';
import { submit } from '@/lib/submit';
import { money, type Paginated } from '@/components/dashboard/billing-summary';

export type MasterTransaction = {
  id: number;
  transactionId?: string;
  transaction_id?: string;
  reference: string;
  receiptNumber: string;
  homeowner: string;
  lot: string;
  amount: number;
  currency: string;
  channel: string;
  paymentMethod?: string;
  userCode?: string;
  propertyCode?: string;
  communityCode?: string;
  purpose?: string;
  provider?: string;
  device?: string | null;
  status: 'completed' | 'pending' | 'paid' | 'refunded' | 'failed' | string;
  notes?: string | null;
  date: string;
  /** Present only for an administrator, on a Stripe payment with money left to return. */
  refundableAmount?: number | null;
  refundUrl?: string | null;
  slip?: {
    transaction_id?: string;
    public_transaction_id?: string;
    user?: string;
    property?: string;
    community?: string;
    purpose?: string;
    invoice?: string;
    amount?: string | number;
    currency?: string;
    status?: string;
    payment_method?: string;
    method?: string;
    provider?: string;
    provider_transaction_id?: string | null;
    device?: string | null;
    created?: string;
  } | null;
  ledgerEntries?: Array<{
    entryId: string;
    accountCode?: string;
    accountName?: string;
    accountType?: string;
    type: string;
    amount: number;
    currency: string;
    description: string;
  }>;
};

/**
 * A payment by a channel the app cannot see, on its way through the office:
 * awaiting transfer, then received (logged by one administrator), then
 * verified in a bank reconciliation by another. See App\\Enums\\PaymentState.
 */
export type OfficePayment = {
  id: number;
  transactionId: string;
  state: 'awaiting_transfer' | 'received' | string;
  stateLabel: string;
  channel: string;
  paymentMethod: string;
  amount: number;
  currency: string;
  purpose: string;
  homeowner?: string | null;
  lot?: string | null;
  invoiceReference?: string | null;
  isDonation: boolean;
  payerReference?: string | null;
  bankReference?: string | null;
  receivedBy?: string | null;
  receivedAt?: string | null;
  submittedAt: string;
  /** Administrators only, and only in the states that allow them. */
  receiveUrl?: string | null;
  rejectUrl?: string | null;
  canVerify: boolean;
};

type Props = {
  transactions: Paginated<MasterTransaction>;
  /** Administrators only: every payment awaiting confirmation, oldest first. */
  pendingPayments?: OfficePayment[];
  currency?: string;
};

/** A receipt exists only for money that has actually arrived. */
const hasReceipt = (tx: MasterTransaction) => !['pending', 'rejected'].includes(tx.status.toLowerCase());

export function TransactionsLedger({ transactions, pendingPayments = [], currency = 'JMD' }: Props) {
  const [searchTerm, setSearchTerm] = useState('');
  const [channelFilter, setChannelFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');
  const [currencyFilter, setCurrencyFilter] = useState('all');
  const { toast } = useToast();
  const [refunding, setRefunding] = useState<MasterTransaction | null>(null);
  const [refundAmount, setRefundAmount] = useState('');
  const [refundNote, setRefundNote] = useState('');
  const [refundError, setRefundError] = useState<string | null>(null);
  const [refundSaving, setRefundSaving] = useState(false);
  const [receiving, setReceiving] = useState<OfficePayment | null>(null);
  const [bankReference, setBankReference] = useState('');
  const [receiveSaving, setReceiveSaving] = useState(false);
  const [rejecting, setRejecting] = useState<OfficePayment | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [rejectSaving, setRejectSaving] = useState(false);
  const [viewingSlip, setViewingSlip] = useState<MasterTransaction | null>(null);

  const receivePayment = async () => {
    if (!receiving?.receiveUrl) return;
    setReceiveSaving(true);
    try {
      await submit('post', receiving.receiveUrl, { bank_reference: bankReference.trim() === '' ? null : bankReference });
      toast({
        title: 'Logged as received',
        description: `${receiving.transactionId} counts once another administrator verifies it in a bank reconciliation.`,
      });
      setReceiving(null);
    } catch (message) {
      toast({
        variant: 'destructive',
        title: 'Could not log the payment',
        description: typeof message === 'string' ? message : 'Please try again.',
      });
    } finally {
      setReceiveSaving(false);
    }
  };

  const rejectPayment = async () => {
    if (!rejecting?.rejectUrl) return;
    setRejectSaving(true);
    try {
      await submit('post', rejecting.rejectUrl, { reason: rejectReason.trim() === '' ? null : rejectReason });
      toast({ title: 'Payment marked as not received', description: `${rejecting.transactionId} will not count.` });
      setRejecting(null);
    } catch (message) {
      toast({
        variant: 'destructive',
        title: 'Could not reject the payment',
        description: typeof message === 'string' ? message : 'Please try again.',
      });
    } finally {
      setRejectSaving(false);
    }
  };

  /** Log as received / Not received, where the payment's state allows them. */
  const OfficeActions = ({ payment }: { payment: OfficePayment }) => (
    <>
      {payment.receiveUrl && (
        <Button
          variant="ghost"
          size="sm"
          className="h-8 px-2 text-xs gap-1 text-emerald-700 dark:text-emerald-400"
          onClick={() => {
            setReceiving(payment);
            setBankReference(payment.payerReference ?? '');
          }}
        >
          <CheckCircle2 className="h-3.5 w-3.5" />
          Log as received
        </Button>
      )}
      {payment.rejectUrl && (
        <Button
          variant="ghost"
          size="sm"
          className="h-8 px-2 text-xs gap-1 text-destructive"
          onClick={() => {
            setRejecting(payment);
            setRejectReason('');
          }}
        >
          <XCircle className="h-3.5 w-3.5" />
          Not received
        </Button>
      )}
    </>
  );

  const isAdminView = pendingPayments.some((p) => p.receiveUrl || p.rejectUrl);

  const openRefund = (tx: MasterTransaction) => {
    setRefunding(tx);
    setRefundAmount(String(tx.refundableAmount ?? ''));
    setRefundNote('');
    setRefundError(null);
  };

  const issueRefund = async () => {
    if (!refunding?.refundUrl) return;
    setRefundSaving(true);
    setRefundError(null);
    try {
      await submit('post', refunding.refundUrl, {
        amount: Number(refundAmount),
        note: refundNote.trim() === '' ? null : refundNote,
      });
      toast({
        title: 'Refund issued',
        description: `${money(Number(refundAmount), refunding.currency || currency)} is on its way back through Stripe.`,
      });
      setRefunding(null);
    } catch (message) {
      setRefundError(typeof message === 'string' ? message : 'The refund could not be issued. Please try again.');
    } finally {
      setRefundSaving(false);
    }
  };

  const filteredData = transactions.data.filter((tx) => {
    const matchesSearch =
      tx.reference.toLowerCase().includes(searchTerm.toLowerCase()) ||
      (tx.transactionId && tx.transactionId.toLowerCase().includes(searchTerm.toLowerCase())) ||
      (tx.userCode && tx.userCode.toLowerCase().includes(searchTerm.toLowerCase())) ||
      (tx.propertyCode && tx.propertyCode.toLowerCase().includes(searchTerm.toLowerCase())) ||
      (tx.purpose && tx.purpose.toLowerCase().includes(searchTerm.toLowerCase())) ||
      tx.receiptNumber.toLowerCase().includes(searchTerm.toLowerCase()) ||
      tx.homeowner.toLowerCase().includes(searchTerm.toLowerCase()) ||
      tx.lot.toLowerCase().includes(searchTerm.toLowerCase());

    const matchesChannel =
      channelFilter === 'all' || tx.channel.toLowerCase() === channelFilter.toLowerCase();
    const matchesStatus =
      statusFilter === 'all' || tx.status.toLowerCase() === statusFilter.toLowerCase();
    const matchesCurrency =
      currencyFilter === 'all' || (tx.currency || 'JMD').toUpperCase() === currencyFilter.toUpperCase();

    return matchesSearch && matchesChannel && matchesStatus && matchesCurrency;
  });

  const getChannelIcon = (channel: string) => {
    switch (channel.toLowerCase()) {
      case 'apple_pay':
      case 'google_pay':
      case 'samsung_wallet':
      case 'nfc':
      case 'nfc_pos':
        return <Smartphone className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />;
      case 'bank_transfer':
      case 'bank_wire':
        return <Building className="h-4 w-4 text-blue-600 dark:text-blue-400" />;
      default:
        return <CreditCard className="h-4 w-4 text-amber-600 dark:text-amber-400" />;
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status.toLowerCase()) {
      case 'paid':
      case 'completed':
        return (
          <Badge className="bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800 gap-1 font-semibold">
            <CheckCircle2 className="h-3 w-3" /> {status.toLowerCase() === 'paid' ? 'Paid' : 'Completed'}
          </Badge>
        );
      case 'awaiting_bank_transfer':
        return (
          <Badge className="bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300 border-sky-300 dark:border-sky-800 gap-1 font-semibold">
            <Clock className="h-3 w-3" /> Awaiting Bank Feed
          </Badge>
        );
      case 'received':
      case 'verifying':
        return (
          <Badge className="bg-indigo-100 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300 border-indigo-300 dark:border-indigo-800 gap-1 font-semibold">
            <Clock className="h-3 w-3" /> Verifying Transfer
          </Badge>
        );
      case 'pending':
      case 'payment_started':
      case 'requires_action':
        return (
          <Badge className="bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border-amber-300 dark:border-amber-800 gap-1 font-semibold">
            <Clock className="h-3 w-3" /> Pending
          </Badge>
        );
      case 'authorized':
      case 'captured':
        return (
          <Badge className="bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border-blue-300 dark:border-blue-800 gap-1 font-semibold">
            <CheckCircle2 className="h-3 w-3" /> {status.toUpperCase()}
          </Badge>
        );
      case 'refunded':
        return (
          <Badge className="bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 border-purple-300 dark:border-purple-800 gap-1 font-semibold">
            <RotateCcw className="h-3 w-3" /> Refunded
          </Badge>
        );
      case 'rejected':
        return (
          <Badge variant="outline" className="text-muted-foreground gap-1">
            <XCircle className="h-3 w-3" /> Not received
          </Badge>
        );
      case 'reinstated':
        return (
          <Badge className="bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300 border-sky-300 dark:border-sky-800 gap-1 font-semibold">
            <ShieldCheck className="h-3 w-3" /> Reinstated
          </Badge>
        );
      default:
        return (
          <Badge variant="destructive" className="gap-1 font-semibold">
            <AlertCircle className="h-3 w-3" /> {status}
          </Badge>
        );
    }
  };

  return (
    <Card className="shadow-sm border-slate-200 dark:border-slate-800">
      <CardHeader className="pb-4">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <CardTitle className="text-xl font-bold flex items-center gap-2">
              <Receipt className="h-5 w-5 text-primary" />
              Master Transactions Ledger
            </CardTitle>
            <CardDescription>
              A record of dues, donations and credits entered against this community.
            </CardDescription>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              className="gap-2 text-xs font-semibold"
              onClick={() => {
                window.location.href = '/dashboard/billing/export-transactions';
              }}
            >
              <FileSpreadsheet className="h-4 w-4 text-emerald-600" />
              Export CSV
            </Button>
          </div>
        </div>

        {/* Search & Filters */}
        <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search reference, receipt, resident..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="pl-9 text-sm"
            />
          </div>

          <Select value={currencyFilter} onValueChange={setCurrencyFilter}>
            <SelectTrigger className="text-sm">
              <SelectValue placeholder="All Currencies" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Currencies</SelectItem>
              <SelectItem value="JMD">🇯🇲 JMD (J$)</SelectItem>
              <SelectItem value="USD">🇺🇸 USD ($)</SelectItem>
              <SelectItem value="CAD">🇨🇦 CAD (CA$)</SelectItem>
              <SelectItem value="GBP">🇬🇧 GBP (£)</SelectItem>
              <SelectItem value="EUR">🇪🇺 EUR (€)</SelectItem>
            </SelectContent>
          </Select>

          <Select value={channelFilter} onValueChange={setChannelFilter}>
            <SelectTrigger className="text-sm">
              <SelectValue placeholder="All Channels" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Channels</SelectItem>
              <SelectItem value="card">Credit/Debit Card</SelectItem>
              <SelectItem value="apple_pay">Apple Pay</SelectItem>
              <SelectItem value="google_pay">Google Pay</SelectItem>
              <SelectItem value="bank_transfer">Bank Transfer</SelectItem>
              <SelectItem value="nfc">NFC Contactless</SelectItem>
              <SelectItem value="wallet">Community Wallet</SelectItem>
              <SelectItem value="cash">Cash Desk</SelectItem>
            </SelectContent>
          </Select>

          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="text-sm">
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Statuses</SelectItem>
              <SelectItem value="completed">Completed</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="rejected">Not received</SelectItem>
              <SelectItem value="refunded">Refunded</SelectItem>
              <SelectItem value="failed">Failed</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </CardHeader>

      <CardContent className="space-y-6">
        {pendingPayments.length > 0 && (
          <section
            aria-labelledby="pending-payments-heading"
            className="rounded-xl border border-amber-300 bg-amber-50/60 p-4 dark:border-amber-800 dark:bg-amber-950/20"
          >
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h3 id="pending-payments-heading" className="flex items-center gap-2 text-sm font-bold text-amber-900 dark:text-amber-200">
                <Clock className="h-4 w-4" />
                {isAdminView ? 'Office payments in progress' : 'Your payments in progress'} ({pendingPayments.length})
              </h3>
              <p className="text-xs text-amber-900/80 dark:text-amber-200/80">
                {isAdminView
                  ? 'Log each as received when the money shows up. A different administrator then verifies it in a bank reconciliation — only then does it count.'
                  : 'These count once the community office has received and verified the money.'}
              </p>
            </div>
            <ul className="divide-y divide-amber-200 dark:divide-amber-900">
              {pendingPayments.map((payment) => (
                <li key={payment.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                  <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                      {money(payment.amount, payment.currency || currency)}
                      <span className="text-xs font-normal text-muted-foreground">
                        via {payment.paymentMethod}
                        {payment.isDonation ? ' · donation' : ''}
                      </span>
                      <Badge variant="outline" className="text-[10px] font-semibold">
                        {payment.stateLabel}
                      </Badge>
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                      <span className="font-mono">{payment.transactionId}</span>
                      {payment.homeowner ? ` · ${payment.homeowner}` : ''}
                      {payment.lot ? ` · ${payment.lot}` : ''}
                      {payment.invoiceReference ? ` · ${payment.invoiceReference}` : ''} · {payment.submittedAt}
                    </p>
                    {payment.state === 'received' && (
                      <p className="text-[11px] text-muted-foreground">
                        Received by {payment.receivedBy ?? 'an administrator'} on {payment.receivedAt ?? '—'}
                        {payment.bankReference ? ` · bank ref ${payment.bankReference}` : ''}
                        {isAdminView &&
                          (payment.canVerify
                            ? ' — verify it in the next bank reconciliation.'
                            : ' — you logged this; another administrator must verify it.')}
                      </p>
                    )}
                  </div>
                  <div className="flex items-center gap-1">
                    <OfficeActions payment={payment} />
                  </div>
                </li>
              ))}
            </ul>
          </section>
        )}

        {filteredData.length === 0 ? (
          <div className="py-12 text-center text-muted-foreground">
            <Receipt className="h-10 w-10 mx-auto mb-3 opacity-30" />
            <p className="font-medium text-sm">No transactions match the selected criteria.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="bg-slate-50 dark:bg-slate-900/50">
                  <TableHead className="w-[170px]">Date & Time</TableHead>
                  <TableHead>CommunityHub Transaction</TableHead>
                  <TableHead>Member & Property</TableHead>
                  <TableHead>Method & Provider</TableHead>
                  <TableHead className="text-right">Amount & Currency</TableHead>
                  <TableHead className="text-center">Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredData.map((tx) => (
                  <TableRow key={tx.id} className="hover:bg-slate-50/70 dark:hover:bg-slate-900/30">
                    <TableCell className="text-xs text-muted-foreground font-mono">
                      {tx.date}
                    </TableCell>
                    <TableCell>
                      <button
                        type="button"
                        onClick={() => setViewingSlip(tx)}
                        className="text-left font-semibold text-xs font-mono text-primary hover:underline flex items-center gap-1 group"
                        title="View Official CommunityHub Transaction Slip"
                      >
                        <span>{tx.transactionId || tx.reference}</span>
                      </button>
                      <div className="flex items-center gap-1.5 mt-0.5">
                        <span className="text-[10px] uppercase font-bold text-muted-foreground/80">{tx.purpose || 'HOA Assessment'}</span>
                        {tx.reference && tx.reference !== (tx.transactionId || '') && (
                          <span className="text-[10px] font-mono text-muted-foreground truncate max-w-[120px]">
                            • {tx.reference}
                          </span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="text-xs font-medium text-foreground">{tx.homeowner}</div>
                      <div className="flex items-center gap-1.5 text-[11px] text-muted-foreground font-mono">
                        <span>{tx.userCode || 'USR-RESIDENT'}</span>
                        <span>•</span>
                        <span>{tx.propertyCode || tx.lot}</span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-1.5 text-xs capitalize text-muted-foreground">
                        {getChannelIcon(tx.channel)}
                        <span>{tx.paymentMethod || tx.channel.replace(/_/g, ' ')}</span>
                      </div>
                      <div className="text-[10px] text-muted-foreground">
                        Route: <span className="font-semibold capitalize">{tx.provider || 'Stripe'}</span>
                        {tx.device ? ` (${tx.device})` : ''}
                      </div>
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="font-bold text-sm text-foreground">
                        {money(tx.amount, tx.currency || currency)}
                      </div>
                      <span className="inline-block text-[10px] font-mono font-bold text-indigo-600 dark:text-indigo-400 uppercase">
                        {tx.currency || 'JMD'}
                      </span>
                    </TableCell>
                    <TableCell className="text-center">{getStatusBadge(tx.status)}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex items-center justify-end gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-8 px-2 text-xs gap-1 text-slate-700 dark:text-slate-300 hover:text-primary"
                          onClick={() => setViewingSlip(tx)}
                          title="View CommunityHub Transaction Card"
                        >
                          <Receipt className="h-3.5 w-3.5" />
                          Slip
                        </Button>
                        {hasReceipt(tx) && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 px-2 text-xs gap-1 text-primary"
                            onClick={() => {
                              window.open(`/dashboard/billing/transactions/${tx.id}/receipt`, '_blank');
                            }}
                          >
                            <Download className="h-3.5 w-3.5" />
                            PDF
                          </Button>
                        )}
                        {tx.refundUrl && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 px-2 text-xs gap-1 text-destructive"
                            onClick={() => openRefund(tx)}
                          >
                            <RotateCcw className="h-3.5 w-3.5" />
                            Refund
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>

      <Dialog open={refunding !== null} onOpenChange={(open) => !open && setRefunding(null)}>
        <DialogContent className="sm:max-w-md">
          {refunding && (
            <>
              <DialogHeader>
                <DialogTitle>Refund Stripe payment</DialogTitle>
                <DialogDescription>
                  {refunding.reference} from {refunding.homeowner}. Up to{' '}
                  {money(refunding.refundableAmount ?? 0, refunding.currency || currency)} can be returned to the card.
                  If this payment settled the invoice, the invoice goes back to Unpaid or Partially Paid.
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                <div className="grid gap-2">
                  <Label htmlFor="refund-amount">Amount ({refunding.currency || currency})</Label>
                  <Input
                    id="refund-amount"
                    type="number"
                    inputMode="decimal"
                    min={0.01}
                    step={0.01}
                    max={refunding.refundableAmount ?? undefined}
                    value={refundAmount}
                    onChange={(e) => setRefundAmount(e.target.value)}
                    aria-invalid={refundError !== null}
                  />
                  {refundError && <p className="text-sm text-destructive">{refundError}</p>}
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="refund-note">Note (optional)</Label>
                  <Textarea
                    id="refund-note"
                    maxLength={500}
                    placeholder="e.g., Duplicate payment; already paid at the office"
                    value={refundNote}
                    onChange={(e) => setRefundNote(e.target.value)}
                  />
                </div>
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setRefunding(null)}>Cancel</Button>
                <Button
                  type="button"
                  variant="destructive"
                  disabled={refundSaving || !(Number(refundAmount) > 0)}
                  onClick={issueRefund}
                >
                  {refundSaving ? 'Refunding…' : 'Issue Refund'}
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={receiving !== null} onOpenChange={(open) => !open && setReceiving(null)}>
        <DialogContent className="sm:max-w-md">
          {receiving && (
            <>
              <DialogHeader>
                <DialogTitle>Log payment as received</DialogTitle>
                <DialogDescription>
                  {money(receiving.amount, receiving.currency || currency)} via {receiving.paymentMethod} from{' '}
                  {receiving.homeowner || 'an unknown payer'} ({receiving.transactionId}). This does not settle anything yet:
                  a different administrator verifies it in a bank reconciliation.
                </DialogDescription>
              </DialogHeader>
              <div className="grid gap-2">
                <Label htmlFor="receive-bank-reference">Bank or till reference (optional)</Label>
                <Input
                  id="receive-bank-reference"
                  maxLength={120}
                  placeholder="e.g., NCB deposit 004421"
                  value={bankReference}
                  onChange={(e) => setBankReference(e.target.value)}
                />
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setReceiving(null)}>Cancel</Button>
                <Button type="button" disabled={receiveSaving} onClick={receivePayment}>
                  {receiveSaving ? 'Saving…' : 'Log as received'}
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={rejecting !== null} onOpenChange={(open) => !open && setRejecting(null)}>
        <DialogContent className="sm:max-w-md">
          {rejecting && (
            <>
              <DialogHeader>
                <DialogTitle>Mark payment as not received</DialogTitle>
                <DialogDescription>
                  {money(rejecting.amount, rejecting.currency || currency)} via {rejecting.paymentMethod} from{' '}
                  {rejecting.homeowner || 'an unknown payer'} ({rejecting.transactionId}). It will never count towards
                  {rejecting.isDonation ? ' the campaign' : ' their statement'}.
                </DialogDescription>
              </DialogHeader>
              <div className="grid gap-2">
                <Label htmlFor="reject-reason">Reason (optional)</Label>
                <Textarea
                  id="reject-reason"
                  maxLength={500}
                  placeholder="e.g., No matching deposit on the bank statement"
                  value={rejectReason}
                  onChange={(e) => setRejectReason(e.target.value)}
                />
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setRejecting(null)}>Cancel</Button>
                <Button type="button" variant="destructive" disabled={rejectSaving} onClick={rejectPayment}>
                  {rejectSaving ? 'Saving…' : 'Mark not received'}
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      {/* Official CommunityHub Transaction Slip Dialog */}
      <Dialog open={viewingSlip !== null} onOpenChange={(open) => !open && setViewingSlip(null)}>
        <DialogContent className="sm:max-w-md">
          {viewingSlip && (
            <>
              <DialogHeader>
                <div className="flex items-center justify-between">
                  <DialogTitle className="text-base font-bold flex items-center gap-2">
                    <Receipt className="h-4 w-4 text-primary" />
                    <span>Transaction Slip</span>
                  </DialogTitle>
                  {getStatusBadge(viewingSlip.status)}
                </div>
                <DialogDescription className="text-xs">
                  Official CommunityHub ledger transaction record
                </DialogDescription>
              </DialogHeader>

              <div className="p-4 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 space-y-3 text-xs">
                <div className="flex justify-between items-center pb-2 border-b border-border/60">
                  <span className="text-muted-foreground font-semibold">Transaction ID</span>
                  <span className="font-mono font-bold text-primary text-sm">{viewingSlip.transactionId || viewingSlip.reference}</span>
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div>
                    <span className="text-muted-foreground block text-[11px]">User</span>
                    <span className="font-mono font-semibold">{viewingSlip.userCode || 'USR-RESIDENT'}</span>
                    <span className="block text-[11px] text-muted-foreground">{viewingSlip.homeowner}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[11px]">Property</span>
                    <span className="font-mono font-semibold">{viewingSlip.propertyCode || viewingSlip.lot}</span>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs pt-2 border-t border-border/40">
                  <div>
                    <span className="text-muted-foreground block text-[11px]">Community</span>
                    <span className="font-semibold">{viewingSlip.communityCode || 'COMM-001'}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[11px]">Purpose</span>
                    <span className="font-semibold">{viewingSlip.purpose || 'HOA Assessment'}</span>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs pt-2 border-t border-border/40">
                  <div>
                    <span className="text-muted-foreground block text-[11px]">Amount</span>
                    <span className="font-bold text-sm text-foreground">
                      {money(viewingSlip.amount, viewingSlip.currency || currency)} {viewingSlip.currency || 'USD'}
                    </span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[11px]">Status</span>
                    <span className="font-mono font-bold uppercase">{viewingSlip.status}</span>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs pt-2 border-t border-border/40">
                  <div>
                    <span className="text-muted-foreground block text-[11px]">Payment Method</span>
                    <span className="font-semibold">{viewingSlip.paymentMethod || viewingSlip.channel.replace(/_/g, ' ')}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground block text-[11px]">Payment Processor</span>
                    <span className="font-semibold capitalize">{viewingSlip.provider || 'Stripe'}</span>
                  </div>
                </div>

                {viewingSlip.device && (
                  <div className="pt-2 border-t border-border/40">
                    <span className="text-muted-foreground block text-[11px]">Device Identifier</span>
                    <span className="font-mono font-semibold">{viewingSlip.device}</span>
                  </div>
                )}

                {viewingSlip.slip?.provider_transaction_id && (
                  <div className="pt-2 border-t border-border/40">
                    <span className="text-muted-foreground block text-[11px]">Provider Reference</span>
                    <span className="font-mono text-[11px] text-muted-foreground break-all">{viewingSlip.slip.provider_transaction_id}</span>
                  </div>
                )}

                {viewingSlip.ledgerEntries && viewingSlip.ledgerEntries.length > 0 && (
                  <div className="pt-2 border-t border-border/40 space-y-1.5">
                    <div className="flex items-center justify-between">
                      <span className="text-muted-foreground block text-[10px] font-bold uppercase tracking-wider">
                        General Ledger Double-Entry Impact
                      </span>
                      <Badge variant="outline" className="text-[9px] font-mono border-emerald-500/30 text-emerald-600 bg-emerald-500/5">
                        Balanced (ΣD = ΣC)
                      </Badge>
                    </div>
                    <div className="space-y-1.5">
                      {viewingSlip.ledgerEntries.map((entry, idx) => (
                        <div key={idx} className="flex items-center justify-between text-[11px] p-2 rounded-lg bg-background/80 border border-border/60">
                          <div>
                            <span className="font-semibold text-foreground block">{entry.accountName}</span>
                            <span className="text-[10px] font-mono text-muted-foreground">Code: {entry.accountCode} &bull; {entry.description}</span>
                          </div>
                          <div className="flex items-center gap-1.5 text-right shrink-0">
                            <Badge variant={entry.type === 'debit' ? 'default' : 'secondary'} className={`text-[9px] font-mono uppercase px-1 py-0 ${entry.type === 'debit' ? 'bg-indigo-600 text-white' : 'bg-slate-200 dark:bg-slate-700 text-foreground'}`}>
                              {entry.type}
                            </Badge>
                            <span className={`font-mono font-bold text-xs ${entry.type === 'debit' ? 'text-emerald-600 dark:text-emerald-400' : 'text-blue-600 dark:text-blue-400'}`}>
                              {entry.type === 'debit' ? '+' : '-'}{money(entry.amount, entry.currency)}
                            </span>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                <div className="pt-2 border-t border-border/40 flex justify-between text-[11px] text-muted-foreground">
                  <span>Date: {viewingSlip.date}</span>
                  <span>Invoice: {viewingSlip.slip?.invoice || 'INV-CURRENT'}</span>
                </div>
              </div>

              <DialogFooter className="flex sm:justify-between items-center gap-2">
                {hasReceipt(viewingSlip) && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="gap-1 text-xs"
                    onClick={() => window.open(`/dashboard/billing/transactions/${viewingSlip.id}/receipt`, '_blank')}
                  >
                    <Download className="h-3.5 w-3.5" />
                    Download PDF Receipt
                  </Button>
                )}
                <Button type="button" size="sm" onClick={() => setViewingSlip(null)}>Close</Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>
    </Card>
  );
}
