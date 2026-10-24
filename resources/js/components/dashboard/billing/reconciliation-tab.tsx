import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, Landmark, Layers, Plus, Scale, ShieldCheck } from 'lucide-react';
import { formatMoney, type LedgerAccount, type PaymentEventItem, type TriPartyReconciliation } from './types';

type Props = {
  triParty?: TriPartyReconciliation | null;
  reconciliations: any[];
  payouts: any[];
  paymentEvents: PaymentEventItem[];
  chartOfAccounts: LedgerAccount[];
  currency: string;
  onNewReconciliation: () => void;
};

/** Ledger vs provider vs bank, statement reconciliations, payouts, webhook events and the chart of accounts. */
export function ReconciliationTab({
  triParty,
  reconciliations,
  payouts,
  paymentEvents,
  chartOfAccounts,
  currency,
  onNewReconciliation,
}: Props) {
  return (
    <>
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2">
        <div>
          <h3 className="text-base font-semibold">Ledger &amp; Bank Settlement Engine</h3>
          <p className="text-xs text-muted-foreground">Automated variance verification, Stripe event audit logs, and operational disbursements.</p>
        </div>
        <Button
          size="sm"
          onClick={onNewReconciliation}
          className="gap-2 bg-violet-600 hover:bg-violet-700 text-white font-medium text-xs shadow-sm self-start sm:self-auto"
        >
          <Plus className="h-4 w-4" />
          <span>New Reconciliation</span>
        </Button>
      </div>

      {/* Tri-Party Continuous Reconciliation Matrix: CommunityHub Ledger vs Provider vs Bank */}
      {triParty && (
        <div className="p-5 rounded-2xl border bg-gradient-to-br from-card via-card to-violet-500/5 shadow-sm space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <div className="flex items-center gap-2">
                <h4 className="font-bold text-sm tracking-tight text-foreground flex items-center gap-2">
                  <Scale className="h-4 w-4 text-violet-500" />
                  <span>Tri-Party Continuous Reconciliation Matrix</span>
                </h4>
                <Badge variant="outline" className="font-mono text-[10px] border-violet-500/40 text-violet-600 bg-violet-500/10">
                  {triParty.currency}
                </Badge>
              </div>
              <p className="text-xs text-muted-foreground mt-0.5">
                Triangulated verification auditing CommunityHub Master Ledger entries against Payment Gateway feeds and Bank Clearing deposits.
              </p>
            </div>
            <Badge
              variant="outline"
              className={`font-mono text-xs px-2.5 py-1 ${
                triParty.variance.is_balanced
                  ? 'border-emerald-500/40 text-emerald-600 bg-emerald-500/10'
                  : 'border-amber-500/40 text-amber-600 bg-amber-500/10'
              }`}
            >
              {triParty.variance.is_balanced ? '✓ TRI-PARTY BALANCED' : 'VARIANCE DETECTED'}
            </Badge>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {/* Card 1: CommunityHub Ledger */}
            <div className="p-4 rounded-xl border bg-background/60 shadow-xs space-y-2">
              <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span className="font-medium">1. CommunityHub Ledger</span>
                <Badge variant="secondary" className="text-[9px] font-mono">INTERNAL</Badge>
              </div>
              <div className="font-mono font-bold text-xl text-foreground">
                {triParty.currency} {triParty.communityHubLedger.total.toLocaleString(undefined, { minimumFractionDigits: 2 })}
              </div>
              <div className="text-[11px] text-muted-foreground flex items-center gap-1">
                <CheckCircle2 className="h-3 w-3 text-emerald-500" />
                <span>{triParty.communityHubLedger.settled_transactions_count} settled transactions audited</span>
              </div>
            </div>

            {/* Card 2: Payment Provider (Stripe) */}
            <div className="p-4 rounded-xl border bg-background/60 shadow-xs space-y-2">
              <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span className="font-medium">2. Gateway ({triParty.paymentProvider.name})</span>
                <Badge variant="secondary" className="text-[9px] font-mono">PROCESSOR</Badge>
              </div>
              <div className="font-mono font-bold text-xl text-foreground">
                {triParty.currency} {triParty.paymentProvider.total.toLocaleString(undefined, { minimumFractionDigits: 2 })}
              </div>
              <div className="text-[11px] text-muted-foreground flex items-center gap-1">
                <ShieldCheck className="h-3 w-3 text-indigo-500" />
                <span>HMAC-SHA256 verified webhooks</span>
              </div>
            </div>

            {/* Card 3: Bank Operating Feed */}
            <div className="p-4 rounded-xl border bg-background/60 shadow-xs space-y-2">
              <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span className="font-medium">3. Bank Feed (NCB)</span>
                <Badge variant="secondary" className="text-[9px] font-mono">EXTERNAL FEED</Badge>
              </div>
              <div className="font-mono font-bold text-xl text-foreground">
                {triParty.currency} {triParty.bank.total.toLocaleString(undefined, { minimumFractionDigits: 2 })}
              </div>
              <div className="text-[11px] text-muted-foreground flex items-center gap-1">
                <Landmark className="h-3 w-3 text-emerald-500" />
                <span>Statement as of {triParty.bank.statement_date}</span>
              </div>
            </div>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <div className="lg:col-span-7 space-y-6">
          <Card className="border shadow-sm">
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="text-lg font-bold flex items-center gap-2">
                    <Scale className="h-5 w-5 text-violet-500" />
                    <span>Bank Statement vs. Master Ledger Reconciliation</span>
                  </CardTitle>
                  <CardDescription className="text-xs">
                    Continuous automated variance verification matching external bank feeds against internal ledger entries.
                  </CardDescription>
                </div>
                <Badge variant="outline" className="font-mono text-xs">
                  {reconciliations.length} Audits
                </Badge>
              </div>
            </CardHeader>
            <CardContent>
              {reconciliations.length === 0 ? (
                <div className="p-8 text-center border border-dashed rounded-xl text-muted-foreground">
                  <Scale className="h-7 w-7 mx-auto mb-2 opacity-50" />
                  <p className="font-semibold text-xs">Bank statement feeds fully balanced.</p>
                </div>
              ) : (
                <div className="rounded-xl border overflow-hidden">
                  <table className="w-full text-left text-xs">
                    <thead className="bg-muted/60 text-muted-foreground font-semibold border-b">
                      <tr>
                        <th className="p-3">Statement Date</th>
                        <th className="p-3">Bank Balance</th>
                        <th className="p-3">Ledger Balance</th>
                        <th className="p-3">Variance</th>
                        <th className="p-3 text-right">Status</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {reconciliations.map((rec) => (
                        <tr key={rec.id} className="hover:bg-muted/30">
                          <td className="p-3 font-mono font-medium">{rec.statementDate}</td>
                          <td className="p-3 font-mono">{currency} {rec.statementBalance.toLocaleString()}</td>
                          <td className="p-3 font-mono">{currency} {rec.ledgerBalance.toLocaleString()}</td>
                          <td className="p-3 font-mono font-bold">
                            {rec.difference === 0 ? (
                              <span className="text-emerald-600">J$0.00 (Balanced)</span>
                            ) : (
                              <span className="text-amber-600">J${rec.difference.toFixed(2)}</span>
                            )}
                          </td>
                          <td className="p-3 text-right">
                            <Badge variant="outline" className="font-mono text-[10px] border-emerald-500/40 text-emerald-600 bg-emerald-500/10">
                              {rec.status}
                            </Badge>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        <div className="lg:col-span-5 space-y-6">
          <Card className="border shadow-sm">
            <CardHeader>
              <CardTitle className="text-lg font-bold flex items-center gap-2">
                <Landmark className="h-5 w-5 text-indigo-500" />
                <span>Vendor &amp; Operating Payouts</span>
              </CardTitle>
              <CardDescription className="text-xs">
                Approved scheduled disbursements for community utilities, landscaping, and gate security.
              </CardDescription>
            </CardHeader>
            <CardContent>
              {payouts.length === 0 ? (
                <div className="p-8 text-center border border-dashed rounded-xl text-muted-foreground">
                  <Landmark className="h-7 w-7 mx-auto mb-2 opacity-50" />
                  <p className="font-semibold text-xs">No pending vendor payouts.</p>
                </div>
              ) : (
                <div className="space-y-2">
                  {payouts.map((p) => (
                    <div key={p.id} className="p-3 rounded-xl border bg-muted/20 flex items-center justify-between text-xs">
                      <div>
                        <strong className="text-foreground block">{p.vendor}</strong>
                        <span className="text-muted-foreground text-[11px] block">{p.category} &bull; Scheduled {p.scheduledFor}</span>
                      </div>
                      <div className="text-right">
                        <span className="font-mono font-bold text-foreground block">
                          {p.currency} {p.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                        </span>
                        <Badge variant="outline" className="text-[9px] font-mono border-emerald-500/40 text-emerald-600 bg-emerald-500/10">
                          {p.status}
                        </Badge>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>

      {/* Verified Stripe Payment Events & Webhook Audit */}
      <Card className="border shadow-sm">
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="text-lg font-bold flex items-center gap-2">
                <ShieldCheck className="h-5 w-5 text-emerald-500" />
                <span>Verified Payment Webhook Events &amp; Idempotency Ledger</span>
              </CardTitle>
              <CardDescription className="text-xs">
                Cryptographically verified Stripe webhook events (HMAC-SHA256), idempotency tracking, settlement logs, and dispute lifecycle audits.
              </CardDescription>
            </div>
            <Badge variant="outline" className="font-mono text-xs">
              {paymentEvents.length} Events Logged
            </Badge>
          </div>
        </CardHeader>
        <CardContent>
          {paymentEvents.length === 0 ? (
            <div className="p-8 text-center border border-dashed rounded-xl text-muted-foreground">
              <ShieldCheck className="h-7 w-7 mx-auto mb-2 opacity-50" />
              <p className="font-semibold text-xs">No Stripe webhook events recorded yet.</p>
              <p className="text-[11px] text-muted-foreground mt-1">Live webhook events will be audited here with verified signatures and idempotency status.</p>
            </div>
          ) : (
            <div className="rounded-xl border overflow-hidden">
              <table className="w-full text-left text-xs">
                <thead className="bg-muted/60 text-muted-foreground font-semibold border-b">
                  <tr>
                    <th className="p-3">Stripe Event ID</th>
                    <th className="p-3">Event Type</th>
                    <th className="p-3">Audit Status</th>
                    <th className="p-3">Details / Errors</th>
                    <th className="p-3 text-right">Received At</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {paymentEvents.map((evt) => (
                    <tr key={evt.id} className="hover:bg-muted/30">
                      <td className="p-3 font-mono font-medium text-foreground">{evt.eventId}</td>
                      <td className="p-3">
                        <span className="font-mono font-semibold px-2 py-0.5 rounded bg-muted text-[11px]">
                          {evt.type}
                        </span>
                      </td>
                      <td className="p-3">
                        <Badge
                          variant="outline"
                          className={`font-mono text-[10px] ${
                            evt.status === 'processed'
                              ? 'border-emerald-500/40 text-emerald-600 bg-emerald-500/10'
                              : evt.status === 'duplicate'
                              ? 'border-amber-500/40 text-amber-600 bg-amber-500/10'
                              : evt.status === 'failed'
                              ? 'border-red-500/40 text-red-600 bg-red-500/10'
                              : 'border-blue-500/40 text-blue-600 bg-blue-500/10'
                          }`}
                        >
                          {evt.status}
                        </Badge>
                      </td>
                      <td className="p-3 text-muted-foreground text-[11px] max-w-xs truncate">
                        {evt.errorMessage || '—'}
                      </td>
                      <td className="p-3 text-right font-mono text-muted-foreground">{evt.date}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Chart of Accounts (Double-Entry General Ledger) */}
      <Card className="border shadow-sm">
        <CardHeader>
          <div className="flex items-center justify-between flex-wrap gap-2">
            <div>
              <CardTitle className="text-lg font-bold flex items-center gap-2">
                <Layers className="h-5 w-5 text-indigo-500" />
                <span>Chart of Accounts &amp; General Ledger Sub-Accounts</span>
              </CardTitle>
              <CardDescription className="text-xs">
                Double-entry accounting structure with real-time balance calculations for assets, liabilities, revenues, and campaign funds.
              </CardDescription>
            </div>
            <Badge variant="outline" className="font-mono text-xs">
              {chartOfAccounts.length} Active Accounts
            </Badge>
          </div>
        </CardHeader>
        <CardContent>
          {chartOfAccounts.length === 0 ? (
            <div className="p-8 text-center border border-dashed rounded-xl text-muted-foreground">
              <p className="font-semibold text-xs">No ledger accounts registered.</p>
            </div>
          ) : (
            <div className="rounded-xl border overflow-hidden">
              <table className="w-full text-left text-xs">
                <thead className="bg-muted/60 text-muted-foreground font-semibold border-b">
                  <tr>
                    <th className="p-3">Code</th>
                    <th className="p-3">Account Name</th>
                    <th className="p-3">Classification</th>
                    <th className="p-3">Currency</th>
                    <th className="p-3 text-right">Journal Entries</th>
                    <th className="p-3 text-right">Account Balance</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {chartOfAccounts.map((acc) => (
                    <tr key={acc.id} className="hover:bg-muted/30 transition-colors">
                      <td className="p-3 font-mono font-bold text-foreground">{acc.code}</td>
                      <td className="p-3 font-medium text-foreground">
                        {acc.name}
                        {acc.description && (
                          <span className="block text-[10px] text-muted-foreground">{acc.description}</span>
                        )}
                      </td>
                      <td className="p-3">
                        <Badge
                          variant="outline"
                          className={`text-[10px] font-mono uppercase ${
                            acc.type === 'asset'
                              ? 'border-emerald-500/40 text-emerald-600 bg-emerald-500/10'
                              : acc.type === 'liability'
                              ? 'border-amber-500/40 text-amber-600 bg-amber-500/10'
                              : acc.type === 'revenue'
                              ? 'border-blue-500/40 text-blue-600 bg-blue-500/10'
                              : 'border-slate-500/40 text-slate-600'
                          }`}
                        >
                          {acc.type}
                        </Badge>
                      </td>
                      <td className="p-3 font-mono text-muted-foreground">{acc.currency}</td>
                      <td className="p-3 text-right font-mono text-muted-foreground">{acc.entriesCount} entries</td>
                      <td className="p-3 text-right font-mono font-bold text-foreground">
                        {formatMoney(acc.balance, acc.currency)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </>
  );
}
