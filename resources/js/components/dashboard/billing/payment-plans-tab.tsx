import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Calendar, Plus } from 'lucide-react';
import type { PaymentPlanItem } from './types';

type Props = {
  paymentPlans: PaymentPlanItem[];
  currency: string;
  isAdmin: boolean;
  /** Opens the payment-plan dialog with no statement chosen. */
  onCreatePlan: () => void;
};

export function PaymentPlansTab({ paymentPlans, currency, isAdmin, onCreatePlan }: Props) {
  return (
    <Card className="border shadow-sm">
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <div>
            <CardTitle className="text-xl font-bold flex items-center gap-2">
              <Calendar className="h-5 w-5 text-emerald-500" />
              <span>Structured Installment Payment Plans</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Formal installment agreements dividing assessments into manageable recurring payments.
            </CardDescription>
          </div>
          {isAdmin && (
            <Button
              size="sm"
              onClick={onCreatePlan}
              className="gap-1.5 text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white"
            >
              <Plus className="h-4 w-4" />
              <span>Create Payment Plan</span>
            </Button>
          )}
        </div>
      </CardHeader>
      <CardContent>
        {paymentPlans.length === 0 ? (
          <div className="p-12 text-center border border-dashed rounded-xl text-muted-foreground">
            <Calendar className="h-8 w-8 mx-auto mb-2 opacity-50" />
            <p className="font-semibold text-sm">No active installment plans registered.</p>
            <p className="text-xs text-muted-foreground mt-1">
              Homeowners with arrears can be placed on approved installment schedules.
            </p>
          </div>
        ) : (
          <div className="rounded-xl border overflow-hidden">
            <table className="w-full text-left text-xs">
              <thead className="bg-muted/60 text-muted-foreground font-semibold border-b">
                <tr>
                  <th className="p-3">Homeowner</th>
                  <th className="p-3">Lot</th>
                  <th className="p-3">Statement Ref</th>
                  <th className="p-3">Installment Amount</th>
                  <th className="p-3">Progress</th>
                  <th className="p-3">Frequency</th>
                  <th className="p-3 text-right">Plan Status</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {paymentPlans.map((plan) => (
                  <tr key={plan.id} className="hover:bg-muted/30 transition-colors">
                    <td className="p-3 font-medium text-foreground">{plan.homeowner}</td>
                    <td className="p-3 font-mono">Lot {plan.lot}</td>
                    <td className="p-3 font-mono font-bold">{plan.invoiceReference}</td>
                    <td className="p-3 font-mono font-bold text-foreground">
                      {currency} {plan.installmentAmount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </td>
                    <td className="p-3">
                      <span className="font-mono text-xs">
                        {plan.totalInstallments - plan.remainingInstallments} of {plan.totalInstallments} paid
                      </span>
                      <div className="w-24 h-1.5 bg-muted rounded-full overflow-hidden mt-1">
                        <div
                          className="h-full bg-emerald-500"
                          style={{
                            width: `${((plan.totalInstallments - plan.remainingInstallments) / plan.totalInstallments) * 100}%`,
                          }}
                        />
                      </div>
                    </td>
                    <td className="p-3 font-medium">{plan.frequency}</td>
                    <td className="p-3 text-right">
                      <Badge variant="outline" className="font-mono text-[10px] border-emerald-500/40 text-emerald-600 bg-emerald-500/10">
                        {plan.status}
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
