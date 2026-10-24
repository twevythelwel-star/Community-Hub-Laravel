import { useState, type FormEvent } from 'react';
import { router, Head } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Where a page or download that needs a recent password confirmation lands —
 * a bulk export, opened by a link. On success the server sends the browser on
 * to what was asked for (ConfirmPasswordController::store).
 */
export default function ConfirmPasswordPage() {
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [processing, setProcessing] = useState(false);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    router.post(
      '/dashboard/confirm-password',
      { password },
      {
        onStart: () => setProcessing(true),
        onError: (errors) => setError(errors.password ?? 'Could not confirm your password.'),
        onFinish: () => setProcessing(false),
      },
    );
  };

  return (
    <DashboardLayout>
      <Head title="Confirm Password" />
      <div className="mx-auto w-full max-w-md">
        <Card>
          <form onSubmit={submit}>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <KeyRound className="h-5 w-5 text-primary" />
                Confirm your password
              </CardTitle>
              <CardDescription>
                This export contains residents' personal or financial data. Enter your password to continue.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-2">
              <Label htmlFor="confirm-password-page-input">Password</Label>
              <Input
                id="confirm-password-page-input"
                type="password"
                autoComplete="current-password"
                autoFocus
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? 'confirm-password-page-error' : undefined}
              />
              {error && (
                <p id="confirm-password-page-error" role="alert" className="text-sm text-destructive">
                  {error}
                </p>
              )}
            </CardContent>
            <CardFooter>
              <Button type="submit" className="w-full" disabled={processing || password === ''}>
                {processing ? 'Confirming…' : 'Confirm and continue'}
              </Button>
            </CardFooter>
          </form>
        </Card>
      </div>
    </DashboardLayout>
  );
}
