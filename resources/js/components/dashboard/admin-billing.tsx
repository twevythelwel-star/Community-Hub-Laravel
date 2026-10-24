import { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DollarSign, Users, CalendarClock, CheckCircle2 } from 'lucide-react';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ResponsiveContainer,
  XAxis,
  YAxis,
} from 'recharts';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { useToast } from '@/hooks/use-toast';
import { ClientFormattedDate } from '@/components/client-formatted-date';
import {
  money,
  statusVariant,
  type InvoiceRow,
  type Paginated,
} from '@/components/dashboard/billing-summary';

/**
 * Estate-wide billing.
 *
 * Every number on this component used to be invented: four `mockTransactions`,
 * a twelve-month `monthlyCollectionsData` chart, and the "Total Collected" and
 * "Outstanding Dues" tiles derived from those four rows. The fee form displayed
 * four indicative currency conversions from a hardcoded rate table.
 *
 * Two things the server supported and this component could not do:
 *
 *   - **Mark an invoice paid.** `markPaid` has always existed. The only action
 *     in the transactions table was "Send Reminder", which had no handler and no
 *     endpoint — and still has none, because there is no mail transport
 *     configured, so it is gone rather than left as a button that lies.
 *   - **Change the currency and the due day.** `updateSettings` validates all
 *     three fields; the form only ever offered the fee.
 */

type AdminInvoiceRow = InvoiceRow & {
  homeowner: string | null;
  lot: string | null;
};

type Props = {
  settings: { monthlyFee: number; currency: string; dueDayOfMonth: number };
  adminSummary: {
    totalOutstanding: number;
    overdueCount: number;
    collectedThisMonth: number;
    householdsPaidThisMonth: number;
    householdsOutstanding: number;
  };
  monthlyCollections: { month: string; total: number }[];
  invoices: Paginated<AdminInvoiceRow>;
};

const chartConfig = {
  total: { label: 'Total Collected' },
};

export function AdminBilling({ settings, adminSummary, monthlyCollections, invoices }: Props) {
  const { toast } = useToast();

  const [fee, setFee] = useState(String(settings.monthlyFee));
  const [currency, setCurrency] = useState(settings.currency);
  const [dueDay, setDueDay] = useState(String(settings.dueDayOfMonth));
  const [saving, setSaving] = useState(false);
  const [payingId, setPayingId] = useState<number | null>(null);

  const currentMonthIndex = new Date().getMonth();
  const year = new Date().getFullYear();

  const saveSettings = () => {
    setSaving(true);

    router.patch(
      '/dashboard/billing/settings',
      {
        monthly_fee: Number(fee),
        currency: currency.toUpperCase(),
        due_day_of_month: Number(dueDay),
      },
      {
        preserveScroll: true,
        // The original toasted success the moment the button was clicked,
        // before anything had been persisted or could fail.
        onSuccess: () =>
          toast({
            title: 'Billing settings updated',
            description: `Dues are now ${money(Number(fee), currency.toUpperCase())}, due on day ${dueDay}.`,
          }),
        onError: (errors) =>
          toast({
            variant: 'destructive',
            title: 'Could not save settings',
            description: Object.values(errors)[0] ?? 'Please check the values and try again.',
          }),
        onFinish: () => setSaving(false),
      },
    );
  };

  const markPaid = (invoice: AdminInvoiceRow) => {
    setPayingId(invoice.id);

    router.post(
      `/dashboard/billing/invoices/${invoice.id}/pay`,
      {},
      {
        preserveScroll: true,
        onSuccess: () =>
          toast({
            title: 'Payment recorded',
            description: `${invoice.reference} is now marked paid.`,
          }),
        onFinish: () => setPayingId(null),
      },
    );
  };

  return (
    <div className="flex flex-1 flex-col gap-8">
      <div>
        <h1 className="font-headline text-3xl font-bold">Billing Overview</h1>
        <p className="text-muted-foreground">Monitor community payments and dues.</p>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Collected this month</CardTitle>
            <DollarSign className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {money(adminSummary.collectedThisMonth, settings.currency)}
            </div>
            <p className="text-xs text-muted-foreground">
              from {adminSummary.householdsPaidThisMonth}{' '}
              {adminSummary.householdsPaidThisMonth === 1 ? 'household' : 'households'}
            </p>
          </CardContent>
        </Card>

        <Card className={adminSummary.totalOutstanding > 0 ? 'border-destructive/40' : undefined}>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Outstanding dues</CardTitle>
            <Users className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {money(adminSummary.totalOutstanding, settings.currency)}
            </div>
            <p className="text-xs text-muted-foreground">
              from {adminSummary.householdsOutstanding}{' '}
              {adminSummary.householdsOutstanding === 1 ? 'household' : 'households'}
            </p>
          </CardContent>
        </Card>

        <Card className={adminSummary.overdueCount > 0 ? 'border-destructive/40' : undefined}>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Overdue invoices</CardTitle>
            <CalendarClock className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div
              className={`text-2xl font-bold ${adminSummary.overdueCount > 0 ? 'text-destructive' : ''}`}
            >
              {adminSummary.overdueCount}
            </div>
            <p className="text-xs text-muted-foreground">past their due date</p>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Community Dues</CardTitle>
          <CardDescription>
            The fee, currency and due day applied to every resident. All three are stored on the
            server and shared with every device.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-3 max-w-2xl">
          <div className="grid gap-2">
            <Label htmlFor="monthly-fee">Monthly fee</Label>
            <Input
              id="monthly-fee"
              type="number"
              min="0"
              step="0.01"
              value={fee}
              onChange={(e) => setFee(e.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="currency">Currency</Label>
            <Input
              id="currency"
              maxLength={3}
              value={currency}
              onChange={(e) => setCurrency(e.target.value.toUpperCase())}
              placeholder="JMD"
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="due-day">Due day</Label>
            {/* 1–28, so the date exists in February too. */}
            <Input
              id="due-day"
              type="number"
              min="1"
              max="28"
              value={dueDay}
              onChange={(e) => setDueDay(e.target.value)}
            />
          </div>
        </CardContent>
        <CardFooter>
          <Button onClick={saveSettings} disabled={saving}>
            {saving ? 'Saving…' : 'Update Dues'}
          </Button>
        </CardFooter>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Collections in {year}</CardTitle>
          <CardDescription>
            Dues recorded as paid, per calendar month. Built from the invoice table.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ChartContainer config={chartConfig} className="min-h-[200px] w-full">
            <ResponsiveContainer width="100%" height={300}>
              <BarChart data={monthlyCollections} margin={{ top: 20, right: 20, bottom: 20, left: 20 }}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis
                  dataKey="month"
                  stroke="hsl(var(--muted-foreground))"
                  fontSize={12}
                  tickLine={false}
                  axisLine={false}
                />
                <YAxis
                  stroke="hsl(var(--muted-foreground))"
                  fontSize={12}
                  tickLine={false}
                  axisLine={false}
                  tickFormatter={(value) => `${Math.round(Number(value) / 1000)}k`}
                />
                <ChartTooltip
                  cursor={false}
                  content={
                    <ChartTooltipContent
                      indicator="dot"
                      formatter={(value) => money(Number(value), settings.currency)}
                    />
                  }
                />
                <Bar dataKey="total" radius={[4, 4, 0, 0]}>
                  {monthlyCollections.map((entry, index) => (
                    <Cell
                      key={entry.month}
                      fill={
                        index === currentMonthIndex
                          ? 'hsl(var(--primary))'
                          : 'hsl(var(--primary) / 0.3)'
                      }
                    />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </ChartContainer>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Invoices</CardTitle>
          <CardDescription>
            Every invoice on the estate, newest due date first.
            {invoices.total > 0 && ` Showing ${invoices.from}–${invoices.to} of ${invoices.total}.`}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Homeowner</TableHead>
                <TableHead>Reference</TableHead>
                <TableHead>Due</TableHead>
                <TableHead className="text-right">Amount</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>
                  <span className="sr-only">Actions</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {invoices.data.length === 0 && (
                <TableRow>
                  <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                    No invoices have been raised yet.
                  </TableCell>
                </TableRow>
              )}

              {invoices.data.map((invoice) => (
                <TableRow
                  key={invoice.id}
                  className={invoice.status === 'Overdue' ? 'bg-destructive/5' : undefined}
                >
                  <TableCell className="font-medium">
                    {invoice.homeowner ?? '—'}
                    {/* The invoice's own property: an owner of two lots has two
                        invoices, and "Lot {lot}" printed "Lot Lot 42". */}
                    {(invoice.property || invoice.lot) && (
                      <span className="block text-[11px] text-muted-foreground font-normal">
                        {invoice.property ?? (/^\d/.test(invoice.lot ?? '') ? `Lot ${invoice.lot}` : invoice.lot)}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="text-muted-foreground">{invoice.reference}</TableCell>
                  <TableCell>
                    <ClientFormattedDate date={invoice.dueOn} formatString="d MMM yyyy" />
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {money(invoice.amount, invoice.currency)}
                  </TableCell>
                  <TableCell>
                    <Badge variant={statusVariant(invoice.status)}>{invoice.status}</Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    {invoice.status === 'Paid' ? (
                      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                        <CheckCircle2 className="h-3.5 w-3.5" />
                        {invoice.paidAt && (
                          <ClientFormattedDate date={invoice.paidAt} formatString="d MMM" />
                        )}
                      </span>
                    ) : (
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={payingId === invoice.id}
                        onClick={() => markPaid(invoice)}
                      >
                        {payingId === invoice.id ? 'Recording…' : 'Mark Paid'}
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {invoices.links.length > 3 && (
            <nav
              className="flex flex-wrap items-center justify-center gap-1 pt-4"
              aria-label="Pagination"
            >
              {invoices.links.map((link, index) => (
                <Button
                  key={index}
                  size="sm"
                  variant={link.active ? 'default' : 'outline'}
                  disabled={!link.url}
                  onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })}
                  className="h-8 min-w-8 px-2 text-xs"
                  dangerouslySetInnerHTML={{ __html: link.label }}
                />
              ))}
            </nav>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
