import React from 'react';
import { router } from '@inertiajs/react';
import {
  ShieldAlert,
  AlertTriangle,
  Building,
  Clock,
  CheckCircle2,
  Lock,
  History,
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { TabsContent } from '@/components/ui/tabs';
import { RiskIncident } from './governance-types';

interface GovernanceRiskTabProps {
  incidents: RiskIncident[];
  onLockdown: (incident: RiskIncident) => void;
  onTracePass: (passId: string) => void;
}

export function GovernanceRiskTab({
  incidents,
  onLockdown,
  onTracePass,
}: GovernanceRiskTabProps) {
  const handleResolveAlert = (incident: RiskIncident) => {
    const notes = prompt('Enter investigation / resolution notes:');
    if (!notes) return;
    router.post(
      `/dashboard/access-governance/incidents/${incident.id}/resolve`,
      { resolution_notes: notes },
      { preserveScroll: true }
    );
  };

  return (
    <TabsContent value="risk" className="space-y-4">
      <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="p-2.5 rounded-full bg-red-600 text-white shadow-xs">
            <ShieldAlert className="w-5 h-5" />
          </div>
          <div>
            <h3 className="font-bold text-red-950 dark:text-red-200 text-sm">
              ⚠️ Suspicious Credential Activity Engine Active
            </h3>
            <p className="text-xs text-red-800 dark:text-red-300">
              Real-time detection across 8 threat vectors: excessive failed scans, expired QR attempts, revoked QR replay, simultaneous multi-gate presentations (cloned passes), unusual hours, and keyfob cycling.
            </p>
          </div>
        </div>
        <Badge variant="destructive" className="uppercase font-bold tracking-wider text-xs">
          Zero-Trust Guard Mode
        </Badge>
      </div>

      {incidents.length === 0 ? (
        <Card className="text-center py-12">
          <CardContent className="space-y-2">
            <CheckCircle2 className="w-10 h-10 text-emerald-500 mx-auto" />
            <h3 className="text-base font-semibold">No Suspicious Incidents Detected</h3>
            <p className="text-xs text-muted-foreground max-w-md mx-auto">
              All gate presentation events are operating within nominal security parameters. When anomalous presentation patterns occur, immediate quarantine actions appear here.
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {incidents.map((incident) => (
            <Card
              key={incident.id}
              className={`border transition-all ${
                incident.status === 'NEW'
                  ? incident.risk_level === 'CRITICAL'
                    ? 'border-red-500/50 bg-red-500/5 shadow-md ring-1 ring-red-500/20'
                    : 'border-amber-500/40 bg-amber-500/5'
                  : 'border-border/60 opacity-80'
              }`}
            >
              <CardContent className="p-4 sm:p-5">
                <div className="flex flex-col md:flex-row md:items-start justify-between gap-4">
                  <div className="space-y-2 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <Badge
                        className={`text-[11px] font-bold uppercase tracking-wider ${
                          incident.risk_level === 'CRITICAL'
                            ? 'bg-red-600 text-white'
                            : incident.risk_level === 'HIGH'
                            ? 'bg-amber-600 text-white'
                            : 'bg-yellow-600 text-white'
                        }`}
                      >
                        {incident.risk_level} RISK
                      </Badge>

                      <Badge variant="outline" className="text-xs font-mono">
                        {incident.flag_type}
                      </Badge>

                      <span className="text-xs font-semibold text-muted-foreground flex items-center gap-1">
                        <Building className="w-3.5 h-3.5" /> Gate: {incident.gate}
                      </span>

                      <span className="text-xs text-muted-foreground flex items-center gap-1">
                        <Clock className="w-3.5 h-3.5" /> {new Date(incident.occurred_at).toLocaleString()}
                      </span>

                      <Badge
                        variant="secondary"
                        className={`text-[10px] font-semibold uppercase ${
                          incident.status === 'NEW'
                            ? 'bg-red-500/20 text-red-700 dark:text-red-300'
                            : incident.status === 'LOCKED_DOWN'
                            ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900'
                            : 'bg-emerald-500/20 text-emerald-700'
                        }`}
                      >
                        {incident.status.replace('_', ' ')}
                      </Badge>
                    </div>

                    <h4 className="text-base font-bold text-foreground flex items-center gap-2">
                      {incident.title}
                    </h4>

                    <p className="text-xs text-muted-foreground leading-relaxed">
                      {incident.description}
                    </p>

                    {incident.evidence && (
                      <div className="mt-2 p-2.5 rounded-lg bg-muted/60 border border-border/40 text-[11px] font-mono overflow-x-auto">
                        <span className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider block mb-1">
                          Forensic Telemetry Evidence:
                        </span>
                        <pre className="whitespace-pre-wrap text-foreground/90">
                          {JSON.stringify(incident.evidence, null, 2)}
                        </pre>
                      </div>
                    )}

                    {incident.resolution_notes && (
                      <div className="mt-2 text-xs bg-emerald-500/10 border border-emerald-500/20 p-2.5 rounded-lg text-emerald-900 dark:text-emerald-300">
                        <span className="font-semibold">Resolution Notes: </span>
                        {incident.resolution_notes}
                      </div>
                    )}
                  </div>

                  {/* Actions */}
                  <div className="flex md:flex-col gap-2 shrink-0 self-end md:self-start">
                    {incident.status === 'NEW' && incident.pass_id && (
                      <Button
                        variant="destructive"
                        size="sm"
                        className="gap-1.5 text-xs font-bold shadow-xs hover:bg-red-700"
                        onClick={() => onLockdown(incident)}
                      >
                        <Lock className="w-3.5 h-3.5" /> Enforce Lockdown
                      </Button>
                    )}

                    {incident.status === 'NEW' && (
                      <Button
                        variant="outline"
                        size="sm"
                        className="gap-1.5 text-xs font-semibold"
                        onClick={() => handleResolveAlert(incident)}
                      >
                        <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" /> Resolve Alert
                      </Button>
                    )}

                    {incident.pass_id && (
                      <Button
                        variant="ghost"
                        size="sm"
                        className="gap-1 text-xs text-muted-foreground"
                        onClick={() => onTracePass(incident.pass_id!)}
                      >
                        <History className="w-3.5 h-3.5" /> Trace Pass
                      </Button>
                    )}
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </TabsContent>
  );
}
