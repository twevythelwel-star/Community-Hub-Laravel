import { FormEvent, useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/hooks/use-toast';

/*
 * Asks for the password when a sensitive action was refused for want of a
 * recent confirmation (App\Http\Middleware\RequirePasswordConfirmation).
 *
 * The server answers such a submission with the validation error
 * `password_confirmation`, so the dialog that sent it stays open and shows
 * why; this opens alongside it. Once confirmed, the user runs the action
 * again themselves. It is not replayed: a refund or a role change should
 * happen because someone clicked, once.
 */
export function PasswordConfirmationDialog() {
  const page = usePage();
  const required = Boolean((page.props.errors as Record<string, string> | undefined)?.password_confirmation);

  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Keyed on the page, not the message: a second refusal carries the same text.
  useEffect(() => {
    if (required) {
      setOpen(true);
      setPassword('');
      setError(null);
    }
  }, [page, required]);

  const confirm = async (event: FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      // window.axios carries the CSRF token (resources/js/bootstrap.ts).
      await window.axios.post('/dashboard/confirm-password', { password });
      setOpen(false);
      setPassword('');
      toast({ title: 'Password confirmed', description: 'Run the action again to continue.' });
    } catch (e: any) {
      setError(
        e?.response?.data?.errors?.password?.[0] ??
          (e?.response?.status === 429 ? 'Too many attempts. Wait a minute and try again.' : 'Could not confirm your password.'),
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={confirm} className="space-y-4">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <KeyRound className="h-5 w-5 text-primary" />
              Confirm your password
            </DialogTitle>
            <DialogDescription>
              This action changes accounts, money or bulk data. Enter your password to continue; you won't be asked
              again for a while.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-2">
            <Label htmlFor="confirm-password-input">Password</Label>
            <Input
              id="confirm-password-input"
              type="password"
              autoComplete="current-password"
              autoFocus
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              aria-invalid={error ? true : undefined}
              aria-describedby={error ? 'confirm-password-error' : undefined}
            />
            {error && (
              <p id="confirm-password-error" role="alert" className="text-sm text-destructive">
                {error}
              </p>
            )}
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting || password === ''}>
              {submitting ? 'Confirming…' : 'Confirm'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
