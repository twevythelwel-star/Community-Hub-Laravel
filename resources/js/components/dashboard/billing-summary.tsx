import { useState } from 'react';
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
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Info, Landmark, AlertTriangle, FileDown, CreditCard } from 'lucide-react';
import { router } from '@inertiajs/react';
import { ClientFormattedDate } from '@/components/client-formatted-date';
import { PaymentMethodSelectorModal } from './payment-method-selector-modal';

/**
 * A resident's dues and invoice history.
 *
 * Was: `mockPayments`, four fabricated transactions totalled under a hardcoded
 * "Year-to-Date Payments (2025)" heading, plus four indicative exchange rates
 * presented next to the amount owed.
 *
 * The conversions are gone. They came from a hardcoded table that drifts
 * further from reality every day it is not updated, and a figure next to a sum
 * of money reads as a quote whatever the caption underneath says. Wire a rates
 * provider if the estate wants them back.
 */

export type InvoiceRow = {
  id: number;
  reference: string;
  /** The property an HOA assessment is for; dues are charged per property. */
  property?: string | null;
  amount: number;
  currency: string;
  periodStart: string;
  periodEnd: string;
  dueOn: string;
  status: 'Paid' | 'Unpaid' | 'Overdue' | 'Waived';
  paidAt: string | null;
  pdfUrl?: string;
  checkoutUrl?: string;
};

export type Paginated<T> = {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  total: number;
  from: number | null;
  to: number | null;
};

type Props = {
  settings: { monthlyFee: number; currency: string; dueDayOfMonth: number };
  summary: {
    outstanding: number;
    paidThisYear: number;
    paidCountThisYear: number;
    year: number;
  };
  invoices: Paginated<InvoiceRow>;
  availableChannels?: any[];
  walletBalance?: number;
};

export function money(amount: number, currency: string): string {
  return amount.toLocaleString(undefined, {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

export function statusVariant(status: InvoiceRow['status']) {
  switch (status) {
    case 'Paid':
      return 'secondary' as const;
    case 'Overdue':
      return 'destructive' as const;
    case 'Waived':
      return 'outline' as const;
    default:
      return 'default' as const;
  }
}

function ordinal(day: number): string {
  const suffix =
    day % 10 === 1 && day !== 11
      ? 'st'
      : day % 10 === 2 && day !== 12
        ? 'nd'
        : day % 10 === 3 && day !== 13
          ? 'rd'
          : 'th';
  return `${day}${suffix}`;
}

export function BillingSummary({
  settings,
  summary,
  invoices,
  availableChannels = [],
  walletBalance = 0,
}: Props) {
  const [isPaymentModalOpen, setIsPaymentModalOpen] = useState(false);
  const [selectedInvoiceForPayment, setSelectedInvoiceForPayment] = useState<InvoiceRow | null>(null);

  return (
    <>
      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Monthly dues</CardDescription>
            <CardTitle className="text-3xl">
              {money(settings.monthlyFee, settings.currency)}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">
              Due on the {ordinal(settings.dueDayOfMonth)} of each month.
            </p>
          </CardContent>
        </Card>

        <Card className={summary.outstanding > 0 ? 'border-destructive/40' : undefined}>
          <CardHeader className="pb-2">
            <CardDescription>Outstanding</CardDescription>
            <CardTitle
              className={`text-3xl ${summary.outstanding > 0 ? 'text-destructive' : ''}`}
            >
              {money(summary.outstanding, settings.currency)}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">
              {summary.outstanding > 0
                ? 'Unpaid and overdue invoices combined.'
                : 'Your account is up to date.'}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardDescription className="flex items-center gap-1.5">
              <Landmark className="h-3.5 w-3.5" /> Paid in {summary.year}
            </CardDescription>
            <CardTitle className="text-3xl">
              {money(summary.paidThisYear, settings.currency)}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">
              Across {summary.paidCountThisYear}{' '}
              {summary.paidCountThisYear === 1 ? 'invoice' : 'invoices'}.
            </p>
          </CardContent>
        </Card>
      </div>

      {/*
        This replaced the original card form, which claimed PCI-DSS compliance
        above a Pay button with no handler. It briefly read "this application
        does not take card payments", which was true when written and false as
        soon as the Stripe checkout landed — directly below it. Describing what
        the buttons in the table actually do is the version that stays true.
      */}
      <Alert>
        <Info className="h-4 w-4" />
        <AlertTitle>How to pay</AlertTitle>
        <AlertDescription>
          Settle your dues with the community office and an administrator records the payment
          against your invoice here. The status below updates once they do.
        </AlertDescription>
      </Alert>

      <Card>
        <CardHeader>
          <CardTitle>Your Invoices</CardTitle>
          <CardDescription>
            Every invoice raised against your property.
            {invoices.total > 0 && ` Showing ${invoices.from}–${invoices.to} of ${invoices.total}.`}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Reference</TableHead>
                <TableHead>Period</TableHead>
                <TableHead>Due</TableHead>
                <TableHead className="text-right">Amount</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Actions</TableHead>
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
                    {invoice.reference}
                    {invoice.property && (
                      <span className="block text-[11px] text-muted-foreground font-normal">{invoice.property}</span>
                    )}
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    <ClientFormattedDate date={invoice.periodStart} formatString="MMM yyyy" />
                  </TableCell>
                  <TableCell>
                    <ClientFormattedDate date={invoice.dueOn} formatString="d MMM yyyy" />
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {money(invoice.amount, invoice.currency)}
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1.5">
                      <Badge variant={statusVariant(invoice.status)}>{invoice.status}</Badge>
                      {invoice.status === 'Overdue' && (
                        <AlertTriangle className="h-3.5 w-3.5 text-destructive" />
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex items-center justify-end gap-1.5">
                      {invoice.pdfUrl && (
                        <Button
                          asChild
                          size="sm"
                          variant="outline"
                          className="h-8 px-2 text-xs"
                          title="Download PDF Statement"
                        >
                          <a href={invoice.pdfUrl} target="_blank" rel="noopener noreferrer">
                            <FileDown className="h-3.5 w-3.5 mr-1" />
                            PDF
                          </a>
                        </Button>
                      )}
                      {(invoice.status === 'Unpaid' || invoice.status === 'Overdue') && (
                        <Button
                          size="sm"
                          className="h-8 px-2.5 text-xs bg-emerald-600 hover:bg-emerald-700 text-white font-semibold gap-1"
                          onClick={() => {
                            setSelectedInvoiceForPayment(invoice);
                            setIsPaymentModalOpen(true);
                          }}
                        >
                          <CreditCard className="h-3.5 w-3.5 mr-0.5" />
                          Pay Invoice
                        </Button>
                      )}
                    </div>
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

      <PaymentMethodSelectorModal
        isOpen={isPaymentModalOpen}
        onClose={() => {
          setIsPaymentModalOpen(false);
          setSelectedInvoiceForPayment(null);
        }}
        invoice={selectedInvoiceForPayment}
        amountDue={selectedInvoiceForPayment ? selectedInvoiceForPayment.amount : summary.outstanding}
        currency={selectedInvoiceForPayment ? selectedInvoiceForPayment.currency : settings.currency}
        availableChannels={availableChannels}
        walletBalance={walletBalance}
      />
    </>
  );
}
