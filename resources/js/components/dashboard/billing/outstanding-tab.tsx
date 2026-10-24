import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import { AlertCircle, Calendar, CheckCircle2, RefreshCw, Send, AlertTriangle } from 'lucide-react';
import type { OutstandingItem } from './types';

type Props = {
  outstanding: OutstandingItem[];
  isAdmin: boolean;
  /** Opens the payment-plan dialog for this statement. */
  onCreatePlan: (invoiceId: number) => void;
};

export function OutstandingTab({ outstanding, isAdmin, onCreatePlan }: Props) {
  const { toast } = useToast();
  const [remindingId, setRemindingId] = useState<number | null>(null);

  const handleRemind = (item: OutstandingItem) => {
    if (!isAdmin) return;

    setRemindingId(item.id);
    router.post(
      `/dashboard/billing/invoices/${item.id}/remind`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => {
          setRemindingId(null);
          toast({
            title: 'Payment Reminder Dispatched',
            description: `Digital statement reminder queued for ${item.homeowner} (Lot ${item.lot}) via email, SMS, and in-app.`,
          });
        },
        onError: (errors) => {
          setRemindingId(null);
          toast({
            variant: 'destructive',
            title: 'Reminder Dispatch Failed',
            description: (Object.values(errors)[0] as string) || 'Could not send payment reminder.',
          });
        },
      }
    );
  };

  return (
    <Card className="border shadow-sm">
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <div>
            <CardTitle className="text-xl font-bold flex items-center gap-2">
              <AlertCircle className="h-5 w-5 text-amber-500" />
              <span>Outstanding Balances &amp; Delinquency Ledger</span>
            </CardTitle>
            <CardDescription className="text-xs">
              All unsettled assessment statements across residential lots with real-time aging calculation.
            </CardDescription>
          </div>
          <Badge variant="outline" className="font-mono text-xs">
            {outstanding.length} Delinquent Statements
          </Badge>
        </div>
      </CardHeader>
      <CardContent>
        {outstanding.length === 0 ? (
          <div className="p-12 text-center border border-dashed rounded-xl text-muted-foreground">
            <CheckCircle2 className="h-8 w-8 mx-auto mb-2 text-emerald-500 opacity-60" />
            <p className="font-semibold text-sm">All community accounts are settled in full.</p>
            <p className="text-xs text-muted-foreground mt-1">No outstanding balances currently active.</p>
          </div>
        ) : (
          <div className="rounded-xl border overflow-hidden">
            <table className="w-full text-left text-xs">
              <thead className="bg-muted/60 text-muted-foreground font-semibold border-b">
                <tr>
                  <th className="p-3">Statement Ref</th>
                  <th className="p-3">Homeowner</th>
                  <th className="p-3">Lot</th>
                  <th className="p-3">Amount Due</th>
                  <th className="p-3">Due Date</th>
                  <th className="p-3">Overdue Aging</th>
                  <th className="p-3">Reminder Status</th>
                  <th className="p-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {outstanding.map((item) => (
                  <tr key={item.id} className="hover:bg-muted/30 transition-colors">
                    <td className="p-3 font-mono font-bold text-foreground">{item.reference}</td>
                    <td className="p-3 font-medium">{item.homeowner}</td>
                    <td className="p-3 font-mono text-muted-foreground">{item.lot}</td>
                    <td className="p-3 font-mono font-bold text-foreground">
                      {item.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })} {item.currency}
                    </td>
                    <td className="p-3 text-muted-foreground">{item.dueOn}</td>
                    <td className="p-3">
                      {item.daysOverdue > 0 ? (
                        <span className="inline-flex items-center gap-1 font-semibold text-rose-600 dark:text-rose-400">
                          <span className="h-1.5 w-1.5 rounded-full bg-rose-500" />
                          {item.daysOverdue}d overdue
                        </span>
                      ) : (
                        <span className="text-muted-foreground">Current</span>
                      )}
                    </td>
                    <td className="p-3">
                      <div className="flex flex-col gap-0.5">
                        {item.reminderStatus === 'delivered' && (
                          <Badge variant="outline" className="h-5 text-[10px] w-fit text-emerald-600 border-emerald-500/30 gap-1 bg-emerald-50/50 dark:bg-emerald-950/20">
                            <CheckCircle2 className="h-2.5 w-2.5" />
                            <span>Delivered</span>
                          </Badge>
                        )}
                        {item.reminderStatus === 'sent' && (
                          <Badge variant="outline" className="h-5 text-[10px] w-fit text-blue-600 border-blue-500/30 gap-1 bg-blue-50/50 dark:bg-blue-950/20">
                            <span>Sent</span>
                          </Badge>
                        )}
                        {item.reminderStatus === 'queued' && (
                          <Badge variant="outline" className="h-5 text-[10px] w-fit text-amber-600 border-amber-500/30 gap-1 bg-amber-50/50 dark:bg-amber-950/20">
                            <span>Queued</span>
                          </Badge>
                        )}
                        {item.reminderStatus === 'failed' && (
                          <Badge variant="destructive" className="h-5 text-[10px] w-fit gap-1">
                            <AlertTriangle className="h-2.5 w-2.5" />
                            <span>Failed</span>
                          </Badge>
                        )}
                        {!item.reminderStatus && (
                          <span className="text-[11px] text-muted-foreground">None sent</span>
                        )}
                        {item.lastRemindedAt && (
                          <span className="text-[10px] text-muted-foreground/80 font-mono">
                            {item.lastRemindedAt}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="p-3 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        {isAdmin && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onCreatePlan(item.id)}
                            className="h-7 text-xs font-semibold gap-1 text-emerald-600 border-emerald-500/30 hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
                          >
                            <Calendar className="h-3 w-3" />
                            <span>Payment Plan</span>
                          </Button>
                        )}

                        {isAdmin ? (
                          <Button
                            size="sm"
                            variant={item.reminderStatus === 'failed' ? 'destructive' : 'secondary'}
                            disabled={remindingId === item.id}
                            onClick={() => handleRemind(item)}
                            className="h-7 text-xs font-semibold gap-1 cursor-pointer"
                            title={item.reminderStatus === 'failed' ? 'Retry reminder dispatch' : 'Dispatch digital statement reminder'}
                          >
                            {remindingId === item.id ? (
                              <>
                                <RefreshCw className="h-3 w-3 animate-spin" />
                                <span>Sending...</span>
                              </>
                            ) : item.reminderStatus === 'failed' ? (
                              <>
                                <RefreshCw className="h-3 w-3" />
                                <span>Retry</span>
                              </>
                            ) : (
                              <>
                                <Send className="h-3 w-3" />
                                <span>{item.reminderCount ? 'Remind Again' : 'Remind'}</span>
                              </>
                            )}
                          </Button>
                        ) : (
                          <Button
                            size="sm"
                            variant="secondary"
                            disabled
                            className="h-7 text-xs font-semibold gap-1 opacity-50 cursor-not-allowed"
                            title="Reminders can only be dispatched by estate administration."
                          >
                            <Send className="h-3 w-3" />
                            <span>Remind</span>
                          </Button>
                        )}
                      </div>
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
