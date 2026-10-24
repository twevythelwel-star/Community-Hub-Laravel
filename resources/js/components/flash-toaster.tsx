import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from '@/hooks/use-toast';

type Flash = {
  success?: string | null;
  error?: string | null;
  info?: string | null;
};

/*
 * Shows the session flash that arrives with a full page load as toasts.
 *
 * Nothing rendered `props.flash`, so an outcome delivered by a redirect from
 * outside the app — "Payment received" or "We could not confirm payment with
 * Stripe" after Checkout sends the resident back — was never seen.
 *
 * Only the flash of the document load itself is read. Actions made from
 * within the app already toast from their own onSuccess/onError handlers, and
 * the layout remounts on every page change, so reading each mount's flash
 * would show those messages twice.
 */
let initialFlashShown = false;

export function FlashToaster() {
  const { props } = usePage();

  useEffect(() => {
    if (initialFlashShown) {
      return;
    }
    initialFlashShown = true;

    const flash = (props as { flash?: Flash }).flash;

    if (flash?.success) {
      toast({ title: 'Done', description: flash.success });
    }

    if (flash?.error) {
      toast({ title: 'Something went wrong', description: flash.error, variant: 'destructive' });
    }

    if (flash?.info) {
      toast({ description: flash.info });
    }
    // First mount of the document only — see the note above.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return null;
}
