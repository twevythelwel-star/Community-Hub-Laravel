import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { MapPin, Phone, ShieldAlert, Car, HeartPulse } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ClientFormattedDate } from '@/components/client-formatted-date';
import { useToast } from '@/hooks/use-toast';

type Alert = {
  id: number;
  incidentId: string;
  auditTrail: {
    eventType: string;
    occurredAt: string;
    actor: string | null;
    snapshot: Record<string, string | number | boolean | null | string[]>;
  }[];
  status: 'open' | 'acknowledged' | 'resolved' | 'false_alarm';
  statusLabel: string;
  assistance: 'police' | 'medical' | 'fire' | 'security' | 'contact' | 'emergency_services';
  severity: string;
  severityLabel: string;
  channel: string;
  gate: string | null;
  event: string | null;
  eventAt: string | null;
  credentialReference: string | null;
  vehicles: string[];
  latitude: number | null;
  longitude: number | null;
  notes: string | null;
  triggeredAt: string;
  escalationLevel: string | null;
  escalationLabel: string;
  canEscalateToSecurity: boolean;
  canEscalateToEmergencyServices: boolean;
  isEmergencyServicesEligible: boolean;
  resident: { name: string | null; phone: string | null; property: string | null; holderType?: string | null };
  profile: {
    emergencyContactName: string | null;
    emergencyContactPhone: string | null;
    emergencyContactRelationship: string | null;
    preferredAssistance: string;
    assistancePreferences: string[];
    emergencyContacts: { name: string; phone: string; relationship?: string | null }[];
    preferredGate: string | null;
    preferredVehicle: string | null;
    medicalNotes: string | null;
  } | null;
  acknowledgedBy: string | null;
  resolvedBy: string | null;
  resolutionNotes: string | null;
};

const ASSISTANCE: Record<Alert['assistance'], string> = {
  police: 'Police',
  medical: 'Medical',
  fire: 'Fire',
  security: 'Security',
  contact: 'Emergency contact',
  emergency_services: 'Emergency assistance',
};

function isActive(a: Alert) {
  return a.status === 'open' || a.status === 'acknowledged';
}

const AUDIT_LABELS: Record<string, string> = {
  activated: 'Distress activated',
  security_notified: 'Security notified',
  security_acknowledged: 'Security acknowledged',
  response_initiated: 'Response initiated',
  gate_context_captured: 'Gate context captured',
  emergency_services_escalated: 'Emergency services escalation recorded',
  incident_closed: 'Incident closed',
};

function AuditTrail({ events }: { events: Alert['auditTrail'] }) {
  return (
    <section className="rounded-md border p-3 sm:col-span-2" aria-label="Incident audit trail">
      <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Incident audit trail</h3>
      {events.length === 0 ? <p className="text-xs text-muted-foreground">No event details recorded.</p> : (
        <ol className="space-y-2">
          {events.map((event, index) => (
            <li key={`${event.eventType}-${event.occurredAt}-${index}`} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-xs">
              <span>
                <span className="font-medium">{AUDIT_LABELS[event.eventType] ?? event.eventType.replaceAll('_', ' ')}</span>
                {event.actor && <span className="text-muted-foreground"> · {event.actor}</span>}
                {typeof event.snapshot.service === 'string' && <span className="text-muted-foreground"> · {event.snapshot.service}</span>}
                {typeof event.snapshot.outcome === 'string' && <span className="text-muted-foreground"> · {event.snapshot.outcome.replaceAll('_', ' ')}</span>}
                {typeof event.snapshot.recipientCount === 'number' && <span className="text-muted-foreground"> · {event.snapshot.recipientCount} responder(s)</span>}
                {typeof event.snapshot.gate === 'string' && <span className="text-muted-foreground"> · {event.snapshot.gate}{typeof event.snapshot.direction === 'string' ? `, ${event.snapshot.direction}` : ''}</span>}
                {typeof event.snapshot.credential === 'string' && <span className="text-muted-foreground"> · Credential {event.snapshot.credential}</span>}
              </span>
              <time className="shrink-0 text-muted-foreground">
                <ClientFormattedDate date={event.occurredAt} formatString="d MMM, HH:mm:ss" />
              </time>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}

export default function SilentAssistancePage({ alerts }: { alerts: Alert[] }) {
  const { toast } = useToast();
  const [resolving, setResolving] = useState<Alert | null>(null);
  const [outcome, setOutcome] = useState<'resolved' | 'false_alarm'>('resolved');
  const [notes, setNotes] = useState('');
  const [escalating, setEscalating] = useState<Alert | null>(null);
  const [service, setService] = useState<'police' | 'medical' | 'fire'>('police');
  const [confirmed, setConfirmed] = useState(false);

  const post = (url: string, data: Record<string, string | boolean>, done: string, after?: () => void) =>
    router.post(url, data, {
      preserveScroll: true,
      onSuccess: () => {
        toast({ title: done });
        after?.();
      },
      onError: (errs) => toast({ variant: 'destructive', title: Object.values(errs)[0] as string }),
    });

  const active = alerts.filter(isActive);
  const closed = alerts.filter((a) => !isActive(a));

  return (
    <DashboardLayout>
      <Head title="Silent Assistance" />

      <div className="mx-auto grid max-w-5xl gap-6">
        <div>
          <h1 className="font-headline text-3xl font-bold tracking-tight">Silent Assistance</h1>
          <p className="text-sm text-muted-foreground">
            Residents asking for help without being seen to. Respond discreetly: the person may not be alone.
          </p>
        </div>

        {active.length === 0 && (
          <Card className="border-dashed py-10 text-center">
            <CardContent className="space-y-1">
              <ShieldAlert className="mx-auto h-8 w-8 text-muted-foreground" aria-hidden="true" />
              <p className="font-medium">No open requests</p>
            </CardContent>
          </Card>
        )}

        {active.map((a) => (
          <Card key={a.id} className="border-red-600/40">
            <CardHeader className="pb-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2 text-lg">
                  <ShieldAlert className="h-5 w-5 text-red-600" aria-hidden="true" />
                  {ASSISTANCE[a.assistance]} · {a.resident.name}
                </CardTitle>
                <div className="flex flex-wrap gap-2">
                  <Badge variant={a.status === 'open' ? 'destructive' : 'secondary'}>{a.statusLabel}</Badge>
                  <Badge variant={a.severity === 'silent_emergency' || a.severity === 'emergency_services' ? 'destructive' : 'outline'}>{a.severityLabel}</Badge>
                  <Badge variant="outline">{a.escalationLabel}</Badge>
                </div>
              </div>
              <p className="text-xs text-muted-foreground">
                Incident {a.incidentId} · {a.channel} · <ClientFormattedDate date={a.triggeredAt} formatString="d MMM, HH:mm:ss" />
              </p>
            </CardHeader>
            <CardContent className="grid gap-4 text-sm sm:grid-cols-2">
              <div className="space-y-1.5">
                <p className="flex items-center gap-2"><MapPin className="h-4 w-4 text-muted-foreground" aria-hidden="true" />{a.resident.property}{a.gate ? ` · at ${a.gate}` : ''}</p>
                {a.resident.phone && (
                  <p className="flex items-center gap-2"><Phone className="h-4 w-4 text-muted-foreground" aria-hidden="true" />{a.resident.phone}</p>
                )}
                {a.latitude !== null && a.longitude !== null && (
                  <a
                    className="text-xs text-primary underline-offset-4 hover:underline"
                    href={`https://www.google.com/maps?q=${a.latitude},${a.longitude}`}
                    target="_blank"
                    rel="noreferrer"
                  >
                    Last known location
                  </a>
                )}
                {a.event && <p className="text-xs text-muted-foreground">{a.event}{a.eventAt ? ` · ${new Date(a.eventAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : ''}{a.credentialReference ? ` · ${a.credentialReference}` : ''}</p>}
                {a.vehicles.length > 0 && (
                  <p className="flex items-start gap-2 text-xs text-muted-foreground"><Car className="mt-0.5 h-3.5 w-3.5" aria-hidden="true" />{a.vehicles.join('; ')}</p>
                )}
                {a.notes && <p className="whitespace-pre-line text-xs text-muted-foreground">{a.notes}</p>}
              </div>
              {a.profile && (
                <div className="space-y-1.5 rounded-md border p-3">
                  <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Safety profile</p>
                  {a.profile.assistancePreferences.length > 0 && <p><span className="font-medium">Preferred assistance:</span> {a.profile.assistancePreferences.map((item) => ASSISTANCE[item as Alert['assistance']] ?? item).join(', ')}</p>}
                  {a.profile.emergencyContacts.map((contact, index) => (
                    <p key={`${contact.phone}-${index}`}>
                      <span className="font-medium">Contact {index + 1}:</span> {contact.name}
                      {contact.relationship ? ` (${contact.relationship})` : ''} · {contact.phone}
                    </p>
                  ))}
                  {a.profile.preferredGate && <p><span className="font-medium">Preferred gate:</span> {a.profile.preferredGate}</p>}
                  {a.profile.preferredVehicle && <p className="flex items-center gap-1"><Car className="h-4 w-4" aria-hidden="true" /><span className="font-medium">Preferred vehicle:</span> {a.profile.preferredVehicle}</p>}
                  {a.profile.medicalNotes && (
                    <p className="flex items-start gap-2"><HeartPulse className="mt-0.5 h-4 w-4 text-red-600" aria-hidden="true" />{a.profile.medicalNotes}</p>
                  )}
                  {a.profile.emergencyContacts.length === 0 && !a.profile.medicalNotes && a.profile.assistancePreferences.length === 0 && !a.profile.preferredGate && !a.profile.preferredVehicle && (
                    <p className="text-muted-foreground">Nothing recorded.</p>
                  )}
                </div>
              )}
              <AuditTrail events={a.auditTrail} />
              <div className="flex flex-wrap gap-2 sm:col-span-2">
                {a.status === 'open' && (
                  <Button size="sm" onClick={() => post(`/dashboard/silent-assistance/${a.id}/acknowledge`, {}, 'Acknowledged.')}>
                    Acknowledge
                  </Button>
                )}
                {a.canEscalateToSecurity && (
                  <Button size="sm" variant="outline" onClick={() => post(`/dashboard/silent-assistance/${a.id}/escalate-security`, {}, 'Escalated to security.')}>
                    Escalate to security
                  </Button>
                )}
                {a.canEscalateToEmergencyServices && a.isEmergencyServicesEligible && (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      setEscalating(a);
                      setService(a.assistance === 'medical' || a.assistance === 'fire' ? a.assistance : 'police');
                      setConfirmed(false);
                    }}
                  >
                    Escalate to emergency services
                  </Button>
                )}
                <Button size="sm" variant="ghost" onClick={() => { setResolving(a); setOutcome('resolved'); setNotes(''); }}>
                  Close request
                </Button>
              </div>
            </CardContent>
          </Card>
        ))}

        {closed.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Recently closed</CardTitle>
            </CardHeader>
            <CardContent className="divide-y text-sm">
              {closed.map((a) => (
                <details key={a.id} className="py-2">
                  <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2">
                  <span>{a.incidentId} · {ASSISTANCE[a.assistance]} · {a.resident.holderType ? `${a.resident.holderType.replaceAll('_', ' ')}: ` : ''}{a.resident.name} · {a.resident.property}</span>
                    <span className="text-xs text-muted-foreground">
                      {a.statusLabel}{a.resolvedBy ? ` by ${a.resolvedBy}` : ''} · <ClientFormattedDate date={a.triggeredAt} formatString="d MMM, HH:mm:ss" />
                    </span>
                  </summary>
                  <div className="pt-3"><AuditTrail events={a.auditTrail} /></div>
                </details>
              ))}
            </CardContent>
          </Card>
        )}
      </div>

      <Dialog open={resolving !== null} onOpenChange={(o) => !o && setResolving(null)}>
        <DialogContent className="sm:max-w-[420px]">
          <DialogHeader>
            <DialogTitle>Close request</DialogTitle>
            <DialogDescription>Record what happened. The safety profile is hidden once closed.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <Select value={outcome} onValueChange={(v) => setOutcome(v as 'resolved' | 'false_alarm')}>
              <SelectTrigger aria-label="Outcome"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="resolved">Resolved</SelectItem>
                <SelectItem value="false_alarm">False alarm</SelectItem>
              </SelectContent>
            </Select>
            <Textarea aria-label="What happened" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="What happened" />
          </div>
          <DialogFooter>
            <Button
              disabled={!notes.trim()}
              onClick={() => resolving && post(`/dashboard/silent-assistance/${resolving.id}/resolve`, { outcome, notes }, 'Request closed.', () => setResolving(null))}
            >
              Close request
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={escalating !== null} onOpenChange={(o) => !o && setEscalating(null)}>
        <DialogContent className="sm:max-w-[420px]">
          <DialogHeader>
            <DialogTitle>Escalate to emergency services</DialogTitle>
            <DialogDescription>Records the escalation. Calling the service is still done by you.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <Select value={service} onValueChange={(v) => setService(v as 'police' | 'medical' | 'fire')}>
              <SelectTrigger aria-label="Service"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="police">Police</SelectItem>
                <SelectItem value="medical">Medical</SelectItem>
                <SelectItem value="fire">Fire</SelectItem>
              </SelectContent>
            </Select>
            <div className="flex items-center gap-2">
              <Checkbox id="escalate-confirm" checked={confirmed} onCheckedChange={(c) => setConfirmed(c === true)} />
              <Label htmlFor="escalate-confirm" className="font-normal">I confirm emergency services are needed</Label>
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="destructive"
              disabled={!confirmed}
              onClick={() => escalating && post(`/dashboard/silent-assistance/${escalating.id}/escalate-emergency`, { service, confirmed }, 'Escalated to emergency services.', () => setEscalating(null))}
            >
              Escalate
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </DashboardLayout>
  );
}
