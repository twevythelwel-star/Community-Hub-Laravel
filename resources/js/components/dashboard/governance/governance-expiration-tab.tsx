import React from 'react';
import { Clock, RefreshCw } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { TabsContent } from '@/components/ui/tabs';

interface GovernanceExpirationTabProps {
  onRunSweep: () => void;
}

export function GovernanceExpirationTab({ onRunSweep }: GovernanceExpirationTabProps) {
  return (
    <TabsContent value="expiration" className="space-y-4">
      <Card className="border border-blue-500/20 bg-linear-to-br from-blue-500/5 to-transparent">
        <CardHeader className="p-4 pb-2">
          <CardTitle className="text-sm font-bold flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Clock className="w-4 h-4 text-blue-500" />
              Mandatory Expiration Policy Engine
            </div>
            <Badge variant="outline" className="text-xs text-blue-600 border-blue-300">
              Continuous Sweeping Active
            </Badge>
          </CardTitle>
          <CardDescription className="text-xs">
            Zero forgotten permissions: every non-permanent authorization is bound to a hard mathematical cutoff timestamp. Once the validity window closes, access tokens are cryptographically rejected at all gates.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-4 pt-2">
          <div className="grid grid-cols-1 md:grid-cols-5 gap-3 mt-2">
            <div className="p-3.5 rounded-xl border bg-card/80 space-y-1">
              <span className="text-xs font-bold text-foreground block">Visitor Passes</span>
              <p className="text-[11px] text-muted-foreground">Pre-registered guests and social visits</p>
              <div className="pt-2">
                <Badge className="bg-blue-600 text-white text-[10px] uppercase font-bold">
                  Expires Tonight (23:59)
                </Badge>
              </div>
            </div>

            <div className="p-3.5 rounded-xl border bg-card/80 space-y-1">
              <span className="text-xs font-bold text-foreground block">Contractor Passes</span>
              <p className="text-[11px] text-muted-foreground">Trades, technicians, deliveries</p>
              <div className="pt-2">
                <Badge className="bg-indigo-600 text-white text-[10px] uppercase font-bold">
                  Expires Friday (18:00)
                </Badge>
              </div>
            </div>

            <div className="p-3.5 rounded-xl border bg-card/80 space-y-1">
              <span className="text-xs font-bold text-foreground block">Long-Term Occupant</span>
              <p className="text-[11px] text-muted-foreground">Renters & subleases with contracts</p>
              <div className="pt-2">
                <Badge className="bg-purple-600 text-white text-[10px] uppercase font-bold">
                  Expires Lease Date
                </Badge>
              </div>
            </div>

            <div className="p-3.5 rounded-xl border bg-card/80 space-y-1">
              <span className="text-xs font-bold text-foreground block">Caregivers & Staff</span>
              <p className="text-[11px] text-muted-foreground">Domestic nurses, household staff</p>
              <div className="pt-2">
                <Badge className="bg-emerald-600 text-white text-[10px] uppercase font-bold">
                  Expires Dec 31
                </Badge>
              </div>
            </div>

            <div className="p-3.5 rounded-xl border bg-card/80 space-y-1">
              <span className="text-xs font-bold text-foreground block">Legacy Contacts</span>
              <p className="text-[11px] text-muted-foreground">Delegated access & legal nominees</p>
              <div className="pt-2">
                <Badge className="bg-amber-600 text-white text-[10px] uppercase font-bold">
                  Max 90-Day Window
                </Badge>
              </div>
            </div>
          </div>

          <div className="mt-5 p-4 rounded-xl bg-muted/40 border flex flex-col sm:flex-row items-center justify-between gap-3">
            <div>
              <h4 className="text-xs font-bold text-foreground">Enforce Immediate Policy Sweep</h4>
              <p className="text-[11px] text-muted-foreground">
                Scans the global gate pass directory and immediately transitions any pass past its deadline to EXPIRED status, logging events in the audit trail.
              </p>
            </div>
            <Button
              onClick={onRunSweep}
              className="gap-2 text-xs font-semibold shrink-0 bg-blue-600 hover:bg-blue-700 text-white"
            >
              <RefreshCw className="w-3.5 h-3.5" /> Sweep Stale Passes
            </Button>
          </div>
        </CardContent>
      </Card>
    </TabsContent>
  );
}
