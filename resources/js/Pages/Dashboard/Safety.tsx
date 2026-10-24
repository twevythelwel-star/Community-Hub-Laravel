import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import axios from 'axios';
import { Ambulance, Flame, Phone, Shield, ShieldCheck, Siren, UserRound, KeyRound, QrCode, Plus, Trash2 } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { ClientFormattedDate } from '@/components/client-formatted-date';
import { useToast } from '@/hooks/use-toast';

type Assistance = 'police' | 'medical' | 'fire' | 'security' | 'contact' | 'emergency_services';

type Props = {
  holderLabel: string;
  assistanceOptions: Assistance[];
  profileEnabled?: boolean;
  incidents: {
    incidentId: string;
    assistanceLabel: string;
    status: string;
    activatedAt: string;
    closedAt: string | null;
    auditTrail: { eventType: string; occurredAt: string }[];
  }[];
  profile: {
    emergencyContactName: string | null;
    emergencyContactPhone: string | null;
    emergencyContactRelationship: string | null;
    preferredAssistance: Assistance;
    assistancePreferences: Assistance[];
    emergencyContacts: { name: string; phone: string; relationship?: string | null }[];
    preferredGate: string | null;
    preferredVehicleId: string | number | null;
    medicalNotes: string | null;
    silentAlertsEnabled: boolean;
    hasDuressPin: boolean;
  };
  holdSeconds: number;
  armedMinutes: number;
  publicEmergencyNumber: string | null;
  gateOptions: { value: string; label: string }[];
  vehicleOptions: { value: string; label: string }[];
};

/*
 * Every option looks the same and says the same thing. Nothing on this page
 * names the help being asked for in large type, so a glance at the screen does
 * not give it away.
 */
const OPTIONS: { value: Assistance; label: string; icon: typeof Shield }[] = [
  { value: 'police', label: 'Police', icon: Siren },
  { value: 'medical', label: 'Medical', icon: Ambulance },
  { value: 'fire', label: 'Fire', icon: Flame },
  { value: 'security', label: 'Security', icon: Shield },
  { value: 'contact', label: 'Emergency contact', icon: UserRound },
  { value: 'emergency_services', label: 'Emergency assistance', icon: Siren },
];

function FieldError({ message }: { message?: string }) {
  return message ? <p className="text-xs text-destructive">{message}</p> : null;
}

export default function SafetyPage({ holderLabel, assistanceOptions, profileEnabled = true, profile, incidents, holdSeconds, armedMinutes, publicEmergencyNumber, gateOptions, vehicleOptions }: Props) {
  const { toast } = useToast();
  const [pending, setPending] = useState<Assistance | null>(null);
  const [sending, setSending] = useState(false);

  const profileForm = useForm({
    preferred_assistance: profile.preferredAssistance,
    assistance_preferences: profile.assistancePreferences?.length ? profile.assistancePreferences : [profile.preferredAssistance],
    emergency_contacts: profile.emergencyContacts?.length ? profile.emergencyContacts : [],
    preferred_gate: profile.preferredGate ?? '',
    preferred_vehicle_id: profile.preferredVehicleId ? String(profile.preferredVehicleId) : '',
    medical_notes: profile.medicalNotes ?? '',
    silent_alerts_enabled: profile.silentAlertsEnabled,
  });

  const pinForm = useForm({ pin: '', pin_confirmation: '' });

  const confirmRequest = async () => {
    if (!pending) return;
    setSending(true);
    try {
      await axios.post('/dashboard/safety/assist', { assistance: pending });
      // The same quiet acknowledgement whatever was asked for.
      toast({ title: 'Request sent.' });
    } catch {
      toast({ variant: 'destructive', title: 'Could not send. Try again.' });
    } finally {
      setSending(false);
      setPending(null);
    }
  };

  const saveProfile = (e: React.FormEvent) => {
    e.preventDefault();
    profileForm.patch('/dashboard/safety/profile', {
      preserveScroll: true,
      onSuccess: () => toast({ title: 'Saved.' }),
    });
  };

  const savePin = (e: React.FormEvent) => {
    e.preventDefault();
    pinForm.put('/dashboard/safety/duress-pin', {
      preserveScroll: true,
      onSuccess: () => {
        pinForm.reset();
        toast({ title: 'Saved.' });
      },
    });
  };

  const removePin = () => {
    pinForm.delete('/dashboard/safety/duress-pin', {
      preserveScroll: true,
      onSuccess: () => toast({ title: 'Removed.' }),
    });
  };

  return (
    <DashboardLayout>
      <Head title="Safety & Assistance" />

      <div className="mx-auto grid max-w-3xl gap-6">
        <div>
          <h1 className="font-headline text-3xl font-bold tracking-tight">Safety &amp; Assistance</h1>
          <p className="text-sm text-muted-foreground">
            Ask for help quietly as an authorized {holderLabel.toLowerCase()}.
          </p>
        </div>

        {/* ── Ask for help ── */}
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Request assistance</CardTitle>
            <CardDescription>Your request is sent quietly to authorized security personnel.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              {OPTIONS.filter(({ value }) => assistanceOptions.includes(value)).map(({ value, label, icon: Icon }) => (
                <Button
                  key={value}
                  type="button"
                  variant="outline"
                  className="h-16 flex-col gap-1 text-sm"
                  onClick={() => setPending(value)}
                >
                  <Icon className="h-5 w-5 text-muted-foreground" aria-hidden="true" />
                  {label}
                </Button>
              ))}
            </div>

            {publicEmergencyNumber && (
              <a
                href={`tel:${publicEmergencyNumber.replace(/[^\d+]/g, '')}`}
                className="flex items-center gap-2 text-sm text-muted-foreground underline-offset-4 hover:underline"
              >
                <Phone className="h-4 w-4" aria-hidden="true" />
                Call {publicEmergencyNumber}
              </a>
            )}
          </CardContent>
        </Card>

        {incidents.length > 0 && (
          <Card>
            <CardHeader>
          <CardTitle className="text-lg">My assistance history</CardTitle>
              <CardDescription>Only you and authorized responders can see these records.</CardDescription>
            </CardHeader>
            <CardContent className="divide-y">
              {incidents.map((incident) => (
                <div key={incident.incidentId} className="py-3 text-sm">
                  <div className="flex flex-wrap justify-between gap-2">
                    <span className="font-medium">{incident.incidentId} · {incident.assistanceLabel} · {incident.status.replaceAll('_', ' ')}</span>
                    <ClientFormattedDate date={incident.activatedAt} formatString="d MMM yyyy, HH:mm:ss" />
                  </div>
                  <ol className="mt-2 space-y-1 text-xs text-muted-foreground">
                    {incident.auditTrail.map((event, index) => (
                      <li key={`${event.eventType}-${index}`} className="flex justify-between gap-3">
                        <span>{event.eventType.replaceAll('_', ' ')}</span>
                        <ClientFormattedDate date={event.occurredAt} formatString="d MMM, HH:mm:ss" />
                      </li>
                    ))}
                  </ol>
                </div>
              ))}
            </CardContent>
          </Card>
        )}

        {/* Deliberately plain: no "POLICE!" screen for someone nearby to read. */}
        <Dialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
          <DialogContent className="sm:max-w-[360px]">
            <DialogHeader>
              <DialogTitle>Request assistance?</DialogTitle>
              <DialogDescription className="sr-only">Sends a silent request to estate security.</DialogDescription>
            </DialogHeader>
            <DialogFooter className="gap-2 sm:gap-0">
              <Button type="button" variant="outline" onClick={() => setPending(null)} disabled={sending}>
                Cancel
              </Button>
              <Button type="button" onClick={confirmRequest} disabled={sending}>
                Confirm
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* ── The discreet ways ── */}
        {profileEnabled && <Card>
          <CardHeader>
            <CardTitle className="text-lg">If you cannot use this page</CardTitle>
            <CardDescription>Two ways to ask for help that look like nothing at all.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4 text-sm">
            <div className="flex gap-3">
              <QrCode className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
              <p>
                <span className="font-medium">Hold your gate pass QR code</span> for {holdSeconds} seconds. Nothing on
                your screen changes. Security is alerted, and if your pass is scanned at a gate within{' '}
                {armedMinutes} minutes the guard is told quietly where you are.
              </p>
            </div>
            <div className="flex gap-3">
              <KeyRound className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
              <p>
                <span className="font-medium">Enter your duress PIN</span> at the gate instead of your normal PIN. The
                gate opens exactly as usual, and security is alerted.
              </p>
            </div>
          </CardContent>
        </Card>}

        {/* ── Duress PIN ── */}
        {profileEnabled && <Card>
          <CardHeader>
            <CardTitle className="text-lg">Duress PIN</CardTitle>
            <CardDescription>
              {profile.hasDuressPin
                ? 'A duress PIN is set. Setting a new one replaces it.'
                : 'Six digits, different from your normal gate PIN. Keep it to yourself.'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={savePin} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
              <div className="space-y-1.5">
                <Label htmlFor="duress-pin">New PIN</Label>
                <Input
                  id="duress-pin"
                  type="password"
                  inputMode="numeric"
                  autoComplete="off"
                  maxLength={6}
                  value={pinForm.data.pin}
                  onChange={(e) => pinForm.setData('pin', e.target.value.replace(/\D/g, ''))}
                  aria-invalid={!!pinForm.errors.pin}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="duress-pin-confirm">Repeat PIN</Label>
                <Input
                  id="duress-pin-confirm"
                  type="password"
                  inputMode="numeric"
                  autoComplete="off"
                  maxLength={6}
                  value={pinForm.data.pin_confirmation}
                  onChange={(e) => pinForm.setData('pin_confirmation', e.target.value.replace(/\D/g, ''))}
                />
              </div>
              <Button type="submit" disabled={pinForm.processing || pinForm.data.pin.length !== 6}>
                {profile.hasDuressPin ? 'Replace' : 'Set PIN'}
              </Button>
              <div className="sm:col-span-3">
                <FieldError message={pinForm.errors.pin} />
                {profile.hasDuressPin && (
                  <Button type="button" variant="link" className="h-auto px-0 text-xs text-muted-foreground" onClick={removePin}>
                    Remove duress PIN
                  </Button>
                )}
              </div>
            </form>
          </CardContent>
        </Card>}

        {/* ── Safety profile ── */}
        {profileEnabled && <Card>
          <CardHeader>
            <CardTitle className="text-lg flex items-center gap-2">
              <ShieldCheck className="h-5 w-5 text-primary" aria-hidden="true" />
              Safety profile
            </CardTitle>
            <CardDescription>Shown to estate security only while you have an open request.</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={saveProfile} className="space-y-4">
              <fieldset className="space-y-1.5">
                <legend className="text-sm font-medium">Preferred emergency assistance</legend>
                <div className="grid gap-2 sm:grid-cols-2">
                  {OPTIONS.filter((option) => option.value !== 'contact' && assistanceOptions.includes(option.value)).map(({ value, label, icon: Icon }) => {
                    const checked = profileForm.data.assistance_preferences.includes(value);
                    return (
                      <label key={value} className="flex min-h-11 items-center gap-2 rounded-md border px-3">
                        <Checkbox
                          checked={checked}
                          onCheckedChange={(next) => {
                            const selected = next
                              ? [...profileForm.data.assistance_preferences, value]
                              : profileForm.data.assistance_preferences.filter((item) => item !== value);
                            if (selected.length === 0) return;
                            profileForm.setData('assistance_preferences', selected);
                            profileForm.setData('preferred_assistance', selected[0]);
                          }}
                        />
                        <Icon className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                        <span className="text-sm">{label}</span>
                      </label>
                    );
                  })}
                </div>
                <FieldError message={profileForm.errors.assistance_preferences} />
              </fieldset>

              <div className="space-y-2">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <Label>Emergency contacts</Label>
                    <p className="text-xs text-muted-foreground">Shown to authorized security responders while a request is active.</p>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={profileForm.data.emergency_contacts.length >= 5}
                    onClick={() => profileForm.setData('emergency_contacts', [...profileForm.data.emergency_contacts, { name: '', phone: '', relationship: '' }])}
                  >
                    <Plus className="mr-1 h-4 w-4" aria-hidden="true" /> Add contact
                  </Button>
                </div>
                {profileForm.data.emergency_contacts.map((contact, index) => (
                  <div key={index} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end">
                    <div className="space-y-1">
                      <Label htmlFor={`contact-name-${index}`}>Name</Label>
                      <Input id={`contact-name-${index}`} value={contact.name} onChange={(e) => profileForm.setData('emergency_contacts', profileForm.data.emergency_contacts.map((item, i) => i === index ? { ...item, name: e.target.value } : item))} />
                      <FieldError message={(profileForm.errors as Record<string, string>)[`emergency_contacts.${index}.name`]} />
                    </div>
                    <div className="space-y-1">
                      <Label htmlFor={`contact-phone-${index}`}>Phone</Label>
                      <Input id={`contact-phone-${index}`} type="tel" value={contact.phone} onChange={(e) => profileForm.setData('emergency_contacts', profileForm.data.emergency_contacts.map((item, i) => i === index ? { ...item, phone: e.target.value } : item))} />
                      <FieldError message={(profileForm.errors as Record<string, string>)[`emergency_contacts.${index}.phone`]} />
                    </div>
                    <div className="space-y-1">
                      <Label htmlFor={`contact-relationship-${index}`}>Relationship</Label>
                      <Input id={`contact-relationship-${index}`} value={contact.relationship ?? ''} onChange={(e) => profileForm.setData('emergency_contacts', profileForm.data.emergency_contacts.map((item, i) => i === index ? { ...item, relationship: e.target.value } : item))} />
                    </div>
                    <Button type="button" variant="ghost" size="icon" aria-label={`Remove contact ${index + 1}`} onClick={() => profileForm.setData('emergency_contacts', profileForm.data.emergency_contacts.filter((_, i) => i !== index))}>
                      <Trash2 className="h-4 w-4" aria-hidden="true" />
                    </Button>
                  </div>
                ))}
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="preferred-gate">Preferred gate</Label>
                  <Select value={profileForm.data.preferred_gate || 'none'} onValueChange={(value) => profileForm.setData('preferred_gate', value === 'none' ? '' : value)}>
                    <SelectTrigger id="preferred-gate"><SelectValue placeholder="No preference" /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No preference</SelectItem>
                      {gateOptions.map((gate) => <SelectItem key={gate.value} value={gate.value}>{gate.label}</SelectItem>)}
                    </SelectContent>
                  </Select>
                  <FieldError message={profileForm.errors.preferred_gate} />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="preferred-vehicle">Preferred vehicle</Label>
                  <Select value={profileForm.data.preferred_vehicle_id || 'none'} onValueChange={(value) => profileForm.setData('preferred_vehicle_id', value === 'none' ? '' : value)}>
                    <SelectTrigger id="preferred-vehicle"><SelectValue placeholder="No preference" /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No preference</SelectItem>
                      {vehicleOptions.map((vehicle) => <SelectItem key={vehicle.value} value={vehicle.value}>{vehicle.label}</SelectItem>)}
                    </SelectContent>
                  </Select>
                  <FieldError message={profileForm.errors.preferred_vehicle_id} />
                </div>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="medical-notes">
                  Special instructions <span className="font-normal text-muted-foreground">(optional)</span>
                </Label>
                <Textarea
                  id="medical-notes"
                  rows={3}
                  placeholder="Conditions, allergies, medication or other responder instructions"
                  value={profileForm.data.medical_notes}
                  onChange={(e) => profileForm.setData('medical_notes', e.target.value)}
                />
                <FieldError message={profileForm.errors.medical_notes} />
                <p className="text-xs text-muted-foreground">Only authorized security and emergency responders can see these while your request is active.</p>
              </div>

              <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                <div>
                  <Label htmlFor="silent-alerts" className="font-medium">Discreet triggers</Label>
                  <p className="text-xs text-muted-foreground">
                    The QR hold and the duress PIN. Requests from this page always work.
                  </p>
                </div>
                <Switch
                  id="silent-alerts"
                  checked={profileForm.data.silent_alerts_enabled}
                  onCheckedChange={(v) => profileForm.setData('silent_alerts_enabled', v)}
                />
              </div>

              <div className="flex justify-end">
                <Button type="submit" disabled={profileForm.processing}>Save</Button>
              </div>
            </form>
          </CardContent>
        </Card>}
      </div>
    </DashboardLayout>
  );
}
