import { useState } from 'react';
import { Nfc, Plus, RotateCcw, XCircle, CreditCard } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useToast } from '@/hooks/use-toast';
import { submit } from '@/lib/submit';
import { money } from '@/components/dashboard/billing-summary';

export type InPersonTerminal = {
  id: number;
  label: string;
  terminalId: string;
  deviceId: string | null;
  deviceType: string | null;
  locationId: string;
  country: string;
  provider: string;
  usable: boolean;
  retireUrl: string;
};

export type PaymentOnReader = {
  id: number;
  transactionId: string;
  state: string;
  stateLabel: string;
  amount: number;
  currency: string;
  homeowner: string | null;
  invoiceReference: string | null;
  terminal: string | null;
  failureReason: string | null;
  retryUrl: string | null;
  cancelUrl: string;
};

export type InPersonProps = {
  available: boolean;
  provider: string | null;
  registerUrl: string;
  chargeUrl: string;
  terminals: InPersonTerminal[];
  onReaders: PaymentOnReader[];
};

type OpenInvoice = { id: number; reference: string; amount: number; currency: string; homeowner: string | null };

/**
 * Card-present (tap / insert) payments taken by staff on a registered reader.
 *
 * An NFC-capable phone is not a reader here: only a reader the provider has
 * confirmed can take a payment, and the provider still decides each one. The
 * result arrives by the provider's webhook, so this panel never marks
 * anything paid; a payment leaves "On readers" once it settles.
 */
export function InPersonPayments({ inPerson, openInvoices }: { inPerson: InPersonProps; openInvoices: OpenInvoice[] }) {
  const { toast } = useToast();
  const [busy, setBusy] = useState(false);
  const [registration, setRegistration] = useState({ registration_code: '', location_id: '', label: '' });
  const [charge, setCharge] = useState({ invoice_id: '', amount: '', terminal_id: '' });

  const usableTerminals = inPerson.terminals.filter((t) => t.usable);

  const run = async (url: string, data: Record<string, string>, onDone?: () => void) => {
    setBusy(true);
    try {
      await submit('post', url, data);
      onDone?.();
    } catch (message) {
      toast({
        variant: 'destructive',
        title: 'Card reader',
        description: typeof message === 'string' ? message : 'That did not go through. Please try again.',
      });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card className="shadow-sm">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-lg">
          <Nfc className="h-5 w-5 text-primary" />
          In-person card payments
        </CardTitle>
        <CardDescription>
          {inPerson.available
            ? `Tap or insert a card on a reader registered with ${inPerson.provider}. The payment counts once ${inPerson.provider} confirms it.`
            : 'Not set up for this estate. In-person card payments need a provider whose card readers work in the estate’s country; no provider available here supports Jamaica yet.'}
        </CardDescription>
      </CardHeader>

      {inPerson.available && (
        <CardContent className="space-y-6">
          {/* Charge */}
          <section aria-labelledby="charge-heading" className="space-y-3">
            <h3 id="charge-heading" className="text-sm font-semibold">Take a payment</h3>
            {usableTerminals.length === 0 ? (
              <p className="text-xs text-muted-foreground">Register a reader below before taking a payment.</p>
            ) : (
              <form
                className="grid gap-3 sm:grid-cols-4 items-end"
                onSubmit={(e) => {
                  e.preventDefault();
                  run(inPerson.chargeUrl, charge, () => setCharge({ invoice_id: '', amount: '', terminal_id: '' }));
                }}
              >
                <div className="grid gap-1 sm:col-span-2">
                  <Label htmlFor="pos-invoice">Statement</Label>
                  <select
                    id="pos-invoice"
                    required
                    className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                    value={charge.invoice_id}
                    onChange={(e) => {
                      const invoice = openInvoices.find((i) => String(i.id) === e.target.value);
                      setCharge((c) => ({ ...c, invoice_id: e.target.value, amount: invoice ? String(invoice.amount) : c.amount }));
                    }}
                  >
                    <option value="">Choose a statement…</option>
                    {openInvoices.map((i) => (
                      <option key={i.id} value={i.id}>
                        {i.reference} · {i.homeowner ?? 'Household'} · {money(i.amount, i.currency)}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="grid gap-1">
                  <Label htmlFor="pos-amount">Amount</Label>
                  <Input id="pos-amount" type="number" min={0.01} step={0.01} required value={charge.amount} onChange={(e) => setCharge((c) => ({ ...c, amount: e.target.value }))} />
                </div>
                <div className="grid gap-1">
                  <Label htmlFor="pos-terminal">Reader</Label>
                  <select
                    id="pos-terminal"
                    required
                    className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                    value={charge.terminal_id}
                    onChange={(e) => setCharge((c) => ({ ...c, terminal_id: e.target.value }))}
                  >
                    <option value="">Choose…</option>
                    {usableTerminals.map((t) => (
                      <option key={t.id} value={t.id}>{t.label}</option>
                    ))}
                  </select>
                </div>
                <Button type="submit" disabled={busy} className="gap-1.5 sm:col-span-4 sm:justify-self-start">
                  <CreditCard className="h-4 w-4" />
                  Send to reader
                </Button>
              </form>
            )}
          </section>

          {/* On readers */}
          {inPerson.onReaders.length > 0 && (
            <section aria-labelledby="on-readers-heading" className="space-y-2">
              <h3 id="on-readers-heading" className="text-sm font-semibold">On readers</h3>
              <ul className="divide-y rounded-lg border">
                {inPerson.onReaders.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-xs">
                    <div className="min-w-0">
                      <p className="font-semibold">
                        {money(p.amount, p.currency)} · {p.homeowner ?? 'Household'}{' '}
                        <Badge variant="outline" className="ml-1 text-[10px]">{p.stateLabel}</Badge>
                      </p>
                      <p className="text-muted-foreground">
                        <span className="font-mono">{p.transactionId}</span>
                        {p.invoiceReference ? ` · ${p.invoiceReference}` : ''}
                        {p.terminal ? ` · ${p.terminal}` : ''}
                      </p>
                      {p.failureReason && <p className="text-destructive">{p.failureReason}</p>}
                    </div>
                    <div className="flex gap-1">
                      {p.retryUrl && (
                        <Button size="sm" variant="ghost" className="h-8 gap-1 text-xs" disabled={busy} onClick={() => run(p.retryUrl!, {})}>
                          <RotateCcw className="h-3.5 w-3.5" /> Try again
                        </Button>
                      )}
                      <Button size="sm" variant="ghost" className="h-8 gap-1 text-xs text-destructive" disabled={busy} onClick={() => run(p.cancelUrl, {})}>
                        <XCircle className="h-3.5 w-3.5" /> Clear
                      </Button>
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {/* Readers */}
          <section aria-labelledby="readers-heading" className="space-y-3">
            <h3 id="readers-heading" className="text-sm font-semibold">Readers</h3>
            {inPerson.terminals.length > 0 && (
              <ul className="divide-y rounded-lg border">
                {inPerson.terminals.map((t) => (
                  <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-xs">
                    <div>
                      <p className="font-semibold">
                        {t.label}{' '}
                        <Badge variant="outline" className="ml-1 text-[10px]">{t.usable ? 'Confirmed' : 'Not usable'}</Badge>
                      </p>
                      <p className="text-muted-foreground font-mono">
                        {t.terminalId} · device {t.deviceId ?? '—'} · location {t.locationId} · {t.country}
                      </p>
                    </div>
                    <Button size="sm" variant="ghost" className="h-8 text-xs" disabled={busy} onClick={() => run(t.retireUrl, {})}>
                      Retire
                    </Button>
                  </li>
                ))}
              </ul>
            )}
            <form
              className="grid gap-3 sm:grid-cols-4 items-end"
              onSubmit={(e) => {
                e.preventDefault();
                run(inPerson.registerUrl, registration, () => setRegistration({ registration_code: '', location_id: '', label: '' }));
              }}
            >
              <div className="grid gap-1">
                <Label htmlFor="reader-code">Pairing code</Label>
                <Input id="reader-code" required placeholder="Shown on the reader" value={registration.registration_code} onChange={(e) => setRegistration((r) => ({ ...r, registration_code: e.target.value }))} />
              </div>
              <div className="grid gap-1">
                <Label htmlFor="reader-location">{inPerson.provider} location ID</Label>
                <Input id="reader-location" required placeholder="tml_…" value={registration.location_id} onChange={(e) => setRegistration((r) => ({ ...r, location_id: e.target.value }))} />
              </div>
              <div className="grid gap-1">
                <Label htmlFor="reader-label">Name</Label>
                <Input id="reader-label" required placeholder="e.g. Office reader" value={registration.label} onChange={(e) => setRegistration((r) => ({ ...r, label: e.target.value }))} />
              </div>
              <Button type="submit" variant="outline" disabled={busy} className="gap-1.5">
                <Plus className="h-4 w-4" /> Register reader
              </Button>
            </form>
          </section>
        </CardContent>
      )}
    </Card>
  );
}
