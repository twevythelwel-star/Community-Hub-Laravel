import { useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Ambulance, Flame, Phone, Shield, Siren } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useToast } from '@/hooks/use-toast';

type Assistance = 'security' | 'police' | 'medical' | 'fire' | 'emergency_services';

const options: Record<Assistance, { label: string; icon: typeof Shield }> = {
  security: { label: 'Security', icon: Shield },
  police: { label: 'Police', icon: Siren },
  medical: { label: 'Medical', icon: Ambulance },
  fire: { label: 'Fire', icon: Flame },
  emergency_services: { label: 'Emergency assistance', icon: Siren },
};

type Props = {
  credential: string;
  holderLabel: string;
  assistanceOptions: Assistance[];
  emergencyNumber: string | null;
  accountHolder?: boolean;
};

export default function CredentialSafetyPage({ credential, holderLabel, assistanceOptions, emergencyNumber, accountHolder = false }: Props) {
  const { toast } = useToast();
  const [sending, setSending] = useState<Assistance | null>(null);

  const request = async (assistance: Assistance) => {
    setSending(assistance);
    try {
      await axios.post(accountHolder ? '/dashboard/safety/account-credential/assist' : `/guest/pass/${encodeURIComponent(credential)}/safety/assist`, { assistance });
      toast({ title: 'Request received.' });
    } catch {
      toast({ variant: 'destructive', title: 'Could not send. Try again.' });
    } finally {
      setSending(null);
    }
  };

  return (
    <main className="mx-auto grid min-h-screen max-w-xl content-start gap-5 bg-background px-4 py-8">
      <Head title="Safety Assistance" />
      <header>
        <h1 className="text-2xl font-bold">Safety Assistance</h1>
        <p className="text-sm text-muted-foreground">Available for your {holderLabel.toLowerCase()} credential.</p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Request help</CardTitle>
          <CardDescription>Your request is sent to authorized community security.</CardDescription>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-3">
          {assistanceOptions.map((assistance) => {
            const option = options[assistance];
            if (!option) return null;
            const Icon = option.icon;
            return (
              <Button key={assistance} variant="outline" className="h-16 flex-col gap-1" disabled={sending !== null} onClick={() => request(assistance)}>
                <Icon className="h-5 w-5" aria-hidden="true" />
                {sending === assistance ? 'Sending…' : option.label}
              </Button>
            );
          })}
          {emergencyNumber && (
            <a className="col-span-2 flex items-center justify-center gap-2 rounded-md border p-3 text-sm underline-offset-4 hover:underline" href={`tel:${emergencyNumber.replace(/[^\d+]/g, '')}`}>
              <Phone className="h-4 w-4" aria-hidden="true" /> Call emergency services: {emergencyNumber}
            </a>
          )}
        </CardContent>
      </Card>

    </main>
  );
}
