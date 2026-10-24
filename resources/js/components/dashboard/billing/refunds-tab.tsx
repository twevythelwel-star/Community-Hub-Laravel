import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { RotateCcw } from 'lucide-react';
import type { RefundItem } from './types';

export function RefundsTab({ refunds }: { refunds: RefundItem[] }) {
  return (
    <Card className="border shadow-sm">
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <div>
            <CardTitle className="text-xl font-bold flex items-center gap-2">
              <RotateCcw className="h-5 w-5 text-rose-500" />
              <span>Refunds &amp; Disputed Adjustments Ledger</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Complete audit log of returned funds and reversal transactions.
            </CardDescription>
          </div>
          <Badge variant="outline" className="font-mono text-xs text-rose-600 border-rose-500/40 bg-rose-500/10">
            {refunds.length} Refund Events
          </Badge>
        </div>
      </CardHeader>
      <CardContent>
        {refunds.length === 0 ? (
          <div className="p-12 text-center border border-dashed rounded-xl text-muted-foreground">
            <RotateCcw className="h-8 w-8 mx-auto mb-2 opacity-50" />
            <p className="font-semibold text-sm">No refunded transactions on record.</p>
            <p className="text-xs text-muted-foreground mt-1">
              Refunds are processed in compliance with Community HOA Financial Bylaws.
            </p>
          </div>
        ) : (
          <div className="rounded-xl border overflow-hidden">
            <table className="w-full text-left text-xs">
              <thead className="bg-muted/60 text-muted-foreground font-semibold border-b">
                <tr>
                  <th className="p-3">Reference</th>
                  <th className="p-3">Resident</th>
                  <th className="p-3">Amount Returned</th>
                  <th className="p-3">Channel</th>
                  <th className="p-3">Processed Date</th>
                  <th className="p-3">Audit Notes</th>
                  <th className="p-3 text-right">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {refunds.map((refItem) => (
                  <tr key={refItem.id} className="hover:bg-muted/30 transition-colors">
                    <td className="p-3 font-mono font-bold">{refItem.reference}</td>
                    <td className="p-3 font-medium text-foreground">
                      {refItem.homeowner} {refItem.lot ? `(Lot ${refItem.lot})` : ''}
                    </td>
                    <td className="p-3 font-mono font-bold text-rose-600">
                      {refItem.currency} {refItem.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </td>
                    <td className="p-3 capitalize">{refItem.channel}</td>
                    <td className="p-3 font-mono text-muted-foreground">{refItem.date}</td>
                    <td className="p-3 text-muted-foreground">{refItem.notes || 'Reversal per bylaws'}</td>
                    <td className="p-3 text-right">
                      <Badge variant="outline" className="font-mono text-[10px] border-rose-500/40 text-rose-600 bg-rose-500/10">
                        REFUNDED
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
  );
}
