import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Sparkles } from 'lucide-react';
import { useAuth } from '@/context/auth-context';

/**
 * Consent to send text to Google's AI service.
 *
 * The original recorded the answer in localStorage under 'ai-feature-consent'.
 * That made a data-protection decision browser-local: it did not follow the
 * person to another device, clearing site data revoked it with no record that
 * it had ever been given, and the server — which is what actually makes the
 * call to Google — had no way to know. `users.ai_consent` already existed and
 * was already shared on every Inertia response; this dialog now reads and
 * writes that instead, and NotificationController re-checks it server-side.
 *
 * The props are unchanged so existing call sites need no edit.
 */

interface AIConsentDialogProps {
  onAccept: () => void;
  onDecline?: () => void;
}

export function AIConsentDialog({ onAccept, onDecline }: AIConsentDialogProps) {
  const { user } = useAuth();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (user?.aiConsent) {
      onAccept();
    } else {
      setOpen(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleAccept = () => {
    setSaving(true);

    router.post(
      '/dashboard/profile/ai-consent',
      { consent: true },
      {
        // The caller is mid-compose; losing the pasted document to a prop
        // refresh would be a poor reward for agreeing.
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
          setOpen(false);
          onAccept();
        },
        onFinish: () => setSaving(false),
      },
    );
  };

  const handleDecline = () => {
    setOpen(false);
    onDecline?.();
  };

  return (
    <Dialog open={open} onOpenChange={() => {}}>
      <DialogContent className="sm:max-w-md" onInteractOutside={(e) => e.preventDefault()}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Sparkles className="h-5 w-5 text-primary" />
            AI-Powered Feature
          </DialogTitle>
          <DialogDescription className="text-left space-y-2 pt-2">
            <span className="block">
              This feature uses <strong>Google Gemini</strong>, an external AI service operated by Google LLC,
              to process your content. The text you provide will be sent to Google&apos;s servers for AI processing.
            </span>
            <span className="block">
              By clicking <strong>I Understand</strong>, you consent to this data being sent to Google&apos;s AI
              service. Your consent is recorded on your account and you will not be asked again. See our{' '}
              <Link href="/privacy" className="underline text-primary" target="_blank">
                Privacy Policy
              </Link>{' '}
              for full details.
            </span>
          </DialogDescription>
        </DialogHeader>
        <DialogFooter className="flex-col sm:flex-row gap-2">
          <Button variant="outline" onClick={handleDecline} disabled={saving} className="w-full sm:w-auto">
            Cancel
          </Button>
          <Button onClick={handleAccept} disabled={saving} className="w-full sm:w-auto">
            {saving ? 'Saving…' : 'I Understand, Continue'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
