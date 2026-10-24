import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Download, Receipt } from 'lucide-react';
import type { ReceiptItem } from './types';

export function ReceiptsTab({ receipts }: { receipts: ReceiptItem[] }) {
  return (
    <Card className="border shadow-sm">
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <div>
            <CardTitle className="text-xl font-bold flex items-center gap-2">
              <Receipt className="h-5 w-5 text-emerald-600" />
              <span>Official Payment Receipts Ledger</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Formal cryptographically signed payment receipts confirming settlements to the Master Ledger.
            </CardDescription>
          </div>
          <Badge variant="outline" className="font-mono text-xs">
            {receipts.length} Official Receipts
          </Badge>
        </div>
      </CardHeader>
      <CardContent>
        {receipts.length === 0 ? (
          <div className="p-12 text-center border border-dashed rounded-xl text-muted-foreground">
            <Receipt className="h-8 w-8 mx-auto mb-2 opacity-50" />
            <p className="font-semibold text-sm">No payment receipts issued yet.</p>
            <p className="text-xs text-muted-foreground mt-1">
              Receipts are issued automatically upon transaction settlement.
            </p>
          </div>
        ) : (
          <div className="rounded-xl border overflow-hidden">
            <table className="w-full text-left text-xs">
              <thead className="bg-muted/60 text-muted-foreground font-semibold border-b">
                <tr>
                  <th className="p-3">Receipt Number</th>
                  <th className="p-3">Reference</th>
                  <th className="p-3">Payee</th>
                  <th className="p-3">Amount</th>
                  <th className="p-3">Channel</th>
                  <th className="p-3">Date</th>
                  <th className="p-3 text-right">Official Document</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {receipts.map((rcpt) => (
                  <tr key={rcpt.id} className="hover:bg-muted/30 transition-colors">
                    <td className="p-3 font-mono font-bold text-foreground">{rcpt.receiptNumber}</td>
                    <td className="p-3 font-mono text-muted-foreground">{rcpt.reference}</td>
                    <td className="p-3 font-medium text-foreground">
                      {rcpt.homeowner} {rcpt.lot ? `(Lot ${rcpt.lot})` : ''}
                    </td>
                    <td className="p-3 font-mono font-bold text-foreground">
                      {rcpt.currency} {rcpt.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </td>
                    <td className="p-3 capitalize">{rcpt.channel}</td>
                    <td className="p-3 font-mono text-muted-foreground">{rcpt.date}</td>
                    <td className="p-3 text-right">
                      <Button
                        size="sm"
                        variant="outline"
                        asChild
                        className="h-7 text-xs font-semibold gap-1 text-emerald-600 border-emerald-500/30 hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
                      >
                        <a href={rcpt.receiptUrl} download>
                          <Download className="h-3 w-3" />
                          <span>Download PDF</span>
                        </a>
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
