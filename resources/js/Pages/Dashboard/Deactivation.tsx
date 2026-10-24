import { useState, type FormEvent } from 'react';
import { router, Head } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  CardFooter,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { ShieldAlert, Info } from 'lucide-react';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';

/*
 | This page used to offer "Delete My Account Now", which cleared localStorage,
 | signed the user out and announced "Account Permanently Deleted" without
 | calling the server — the account stayed active and its gate pass kept
 | working. Its "Schedule Deactivation" countdown lived only in the open tab
 | and promised email and SMS reminders that nothing sends.
 |
 | Both are gone. What remains is the one thing the server does:
 | DeactivationController::store checks the password, marks the account
 | Inactive, revokes the gate pass, ends the session and logs it. Nothing is
 | deleted, so an administrator can reactivate the account from the directory.
 */

type Props = {
  user: {
    displayName: string;
    email: string;
  };
};

type Errors = Partial<Record<'password' | 'reason' | 'confirm', string>>;

export default function DeactivationPage({ user }: Props) {
  const [password, setPassword] = useState('');
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [isConfirmOpen, setConfirmOpen] = useState(false);
  const [processing, setProcessing] = useState(false);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    setConfirmOpen(true);
  };

  const deactivate = () => {
    setConfirmOpen(false);
    router.post(
      '/dashboard/deactivation',
      { password, reason: reason || null, confirm: confirmed },
      {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onError: (serverErrors) => setErrors(serverErrors as Errors),
        onFinish: () => setProcessing(false),
      },
    );
  };

  return (
    <DashboardLayout>
      <Head title="Account Deactivation" />
      <div className="grid gap-8 max-w-3xl mx-auto pb-12">
        <div>
          <h1 className="font-headline text-3xl font-bold">Account Deactivation</h1>
          <p className="text-muted-foreground">
            Deactivate the account for {user.displayName} ({user.email}).
          </p>
        </div>

        <Alert>
          <Info className="h-4 w-4" />
          <AlertTitle>What deactivation does</AlertTitle>
          <AlertDescription>
            <ul className="mt-2 list-disc space-y-1 pl-5">
              <li>You are signed out immediately and cannot sign back in.</li>
              <li>Your gate pass is revoked and will be refused at every gate.</li>
              <li>
                Your records (visitors, invoices, access history) are kept, not deleted. An estate
                administrator can reactivate the account if you change your mind.
              </li>
            </ul>
          </AlertDescription>
        </Alert>

        <Card className="border-destructive">
          <form onSubmit={handleSubmit}>
            <CardHeader>
              <CardTitle className="font-headline text-xl flex items-center gap-2 text-destructive">
                <ShieldAlert className="w-5 h-5" />
                Deactivate my account
              </CardTitle>
              <CardDescription>
                Confirm with your current password. This takes effect as soon as you submit.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
              <div className="grid gap-2">
                <Label htmlFor="deactivation-password">Current password</Label>
                <Input
                  id="deactivation-password"
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  aria-invalid={!!errors.password}
                  aria-describedby={errors.password ? 'deactivation-password-error' : undefined}
                  required
                />
                {errors.password && (
                  <p id="deactivation-password-error" className="text-sm text-destructive">
                    {errors.password}
                  </p>
                )}
              </div>

              <div className="grid gap-2">
                <Label htmlFor="deactivation-reason">Reason (optional)</Label>
                <Textarea
                  id="deactivation-reason"
                  maxLength={1000}
                  placeholder="e.g., Moving out of the community"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                />
                {errors.reason && <p className="text-sm text-destructive">{errors.reason}</p>}
              </div>

              <div className="flex items-start gap-3">
                <Checkbox
                  id="deactivation-confirm"
                  checked={confirmed}
                  onCheckedChange={(checked) => setConfirmed(checked === true)}
                />
                <div className="grid gap-1">
                  <Label htmlFor="deactivation-confirm" className="leading-snug">
                    I understand I will be signed out and my gate pass will stop working.
                  </Label>
                  {errors.confirm && <p className="text-sm text-destructive">{errors.confirm}</p>}
                </div>
              </div>
            </CardContent>
            <CardFooter>
              <Button
                type="submit"
                variant="destructive"
                disabled={!confirmed || password === '' || processing}
              >
                {processing ? 'Deactivating…' : 'Deactivate Account'}
              </Button>
            </CardFooter>
          </form>
        </Card>
      </div>

      <AlertDialog open={isConfirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Deactivate your account now?</AlertDialogTitle>
            <AlertDialogDescription>
              You will be signed out straight away and your gate pass will be revoked. Only an
              estate administrator can reactivate the account.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={deactivate} className="bg-destructive hover:bg-destructive/90">
              Yes, deactivate
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </DashboardLayout>
  );
}
