import React from 'react';
import {
  Layers,
  Building,
  CheckCircle2,
  XCircle,
  ArrowRight,
  ChevronRight,
} from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { TabsContent } from '@/components/ui/tabs';
import { ApprovalRequest } from './governance-types';

interface GovernanceApprovalsTabProps {
  approvalRequests: ApprovalRequest[];
  onApprove: (id: number) => void;
  onReject: (id: number) => void;
}

export function GovernanceApprovalsTab({
  approvalRequests,
  onApprove,
  onReject,
}: GovernanceApprovalsTabProps) {
  return (
    <TabsContent value="approvals" className="space-y-4">
      {/* Visual Workflow Explainer */}
      <Card className="border border-border/60 bg-muted/20">
        <CardHeader className="p-4 pb-2">
          <CardTitle className="text-sm font-bold flex items-center gap-2">
            <Layers className="w-4 h-4 text-primary" />
            Tiered Access Governance Matrix
          </CardTitle>
          <CardDescription className="text-xs">
            Automated routing guarantees that each persona receives the appropriate legal and administrative approval prior to gate pass generation.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-4 pt-2">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-3 text-xs">
            <div className="p-3 rounded-lg border bg-card/60">
              <span className="font-bold text-foreground block mb-1">Visitor</span>
              <p className="text-muted-foreground mb-2">Pre-registered guest for resident unit</p>
              <div className="flex items-center gap-1.5 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                <span>Homeowner</span>
                <ArrowRight className="w-3 h-3" />
                <span>Approved (Instant)</span>
              </div>
            </div>

            <div className="p-3 rounded-lg border bg-card/60">
              <span className="font-bold text-foreground block mb-1">Contractor</span>
              <p className="text-muted-foreground mb-2">Vendors, maintenance, solar installers</p>
              <div className="flex items-center gap-1.5 text-[11px] font-semibold text-blue-600 dark:text-blue-400">
                <span>Homeowner</span>
                <ArrowRight className="w-3 h-3" />
                <span>Property Admin</span>
                <ArrowRight className="w-3 h-3" />
                <span>Approved</span>
              </div>
            </div>

            <div className="p-3 rounded-lg border bg-card/60">
              <span className="font-bold text-foreground block mb-1">Long-Term Occupant</span>
              <p className="text-muted-foreground mb-2">Renters & extended family members</p>
              <div className="flex items-center gap-1.5 text-[11px] font-semibold text-purple-600 dark:text-purple-400">
                <span>Homeowner</span>
                <ArrowRight className="w-3 h-3" />
                <span>Community Admin</span>
                <ArrowRight className="w-3 h-3" />
                <span>Approved</span>
              </div>
            </div>

            <div className="p-3 rounded-lg border bg-card/60">
              <span className="font-bold text-foreground block mb-1">Legacy Contact</span>
              <p className="text-muted-foreground mb-2">Succession, estate delegate, caregivers</p>
              <div className="flex items-center gap-1.5 text-[11px] font-semibold text-amber-600 dark:text-amber-400">
                <span>Homeowner</span>
                <ArrowRight className="w-3 h-3" />
                <span>ID Verification</span>
                <ArrowRight className="w-3 h-3" />
                <span>Approved</span>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Live Request Queue */}
      <div className="space-y-3">
        <h3 className="text-sm font-bold tracking-tight text-foreground flex items-center justify-between">
          <span>Active Authorization Requests</span>
          <span className="text-xs font-normal text-muted-foreground">
            {approvalRequests.filter((r) => r.status === 'pending').length} requiring review
          </span>
        </h3>

        {approvalRequests.length === 0 && (
          <Card className="border-dashed text-center py-8">
            <CardContent className="space-y-1">
              <CheckCircle2 className="w-8 h-8 text-emerald-500 mx-auto" />
              <h4 className="text-sm font-semibold">No authorization requests</h4>
              <p className="text-xs text-muted-foreground">
                Requests from homeowners for occupants and contractors appear here for review.
              </p>
            </CardContent>
          </Card>
        )}

        {approvalRequests.map((req) => (
          <Card key={req.id} className="border border-border/60 hover:border-border transition-all">
            <CardContent className="p-4 sm:p-5">
              <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div className="space-y-2 flex-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <Badge variant="outline" className="font-mono text-xs">
                      {req.request_number}
                    </Badge>

                    <Badge
                      className={`text-[10px] uppercase font-bold ${
                        req.category === 'long_term_occupant'
                          ? 'bg-purple-600 text-white'
                          : req.category === 'contractor'
                          ? 'bg-blue-600 text-white'
                          : req.category === 'visitor'
                          ? 'bg-emerald-600 text-white'
                          : 'bg-amber-600 text-white'
                      }`}
                    >
                      {req.category.replace('_', ' ')}
                    </Badge>

                    <span className="text-xs font-semibold text-muted-foreground flex items-center gap-1">
                      <Building className="w-3.5 h-3.5" /> {req.property_number}
                    </span>

                    <Badge
                      variant={
                        req.status === 'approved'
                          ? 'default'
                          : req.status === 'rejected'
                          ? 'destructive'
                          : 'secondary'
                      }
                      className="text-[10px] uppercase font-bold"
                    >
                      {req.status}
                    </Badge>
                  </div>

                  <div>
                    <h4 className="text-base font-bold text-foreground">
                      {req.applicant_name}
                    </h4>
                    <p className="text-xs text-muted-foreground mt-0.5">
                      {req.notes || 'No extra notes provided by applicant.'}
                    </p>
                  </div>

                  {/* Multi-step progression breadcrumbs */}
                  <div className="flex items-center gap-2 pt-1 overflow-x-auto text-[11px]">
                    <span className="font-semibold text-muted-foreground">Progress:</span>
                    {req.approval_chain.map((step, idx) => (
                      <React.Fragment key={idx}>
                        <span className="px-2 py-0.5 rounded-md bg-muted font-medium flex items-center gap-1">
                          <CheckCircle2 className="w-3 h-3 text-emerald-500" />
                          {step.action.replace('_', ' ')} ({step.actor_name})
                        </span>
                        {idx < req.approval_chain.length - 1 && (
                          <ChevronRight className="w-3 h-3 text-muted-foreground" />
                        )}
                      </React.Fragment>
                    ))}
                    {req.status === 'pending' && (
                      <span className="px-2 py-0.5 rounded-md bg-amber-500/20 text-amber-700 dark:text-amber-300 font-bold animate-pulse">
                        Pending {req.current_stage.replace('_', ' ')}
                      </span>
                    )}
                  </div>
                </div>

                {/* Approval Actions */}
                <div className="flex md:flex-col gap-2 shrink-0 self-end md:self-center">
                  {req.status === 'pending' ? (
                    <>
                      <Button
                        size="sm"
                        className="gap-1.5 text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs"
                        onClick={() => onApprove(req.id)}
                      >
                        <CheckCircle2 className="w-3.5 h-3.5" /> Approve Stage
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        className="gap-1.5 text-xs text-red-600 hover:bg-red-50 hover:text-red-700"
                        onClick={() => onReject(req.id)}
                      >
                        <XCircle className="w-3.5 h-3.5" /> Reject
                      </Button>
                    </>
                  ) : req.pass ? (
                    <div className="text-right">
                      <span className="text-[10px] text-muted-foreground block">Active Gate Pass</span>
                      <Badge variant="outline" className="font-mono text-xs text-emerald-600">
                        {req.pass.pass_id}
                      </Badge>
                    </div>
                  ) : null}
                </div>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </TabsContent>
  );
}
