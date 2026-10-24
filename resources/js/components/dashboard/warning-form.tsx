import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
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
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AlertTriangle, PlusCircle } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';

/**
 * Raise a community safety alert.
 *
 * Two things were wrong here:
 *
 *   1. onSubmit called console.log and toasted "Alert Sent". Nothing was stored
 *      and nobody was alerted.
 *   2. The confirmation step could be reached with an invalid form. The "Send
 *      Alert" button was both `type="submit"` and a `DialogTrigger`, so Radix
 *      opened the confirm dialog on click regardless of whether zod validation
 *      passed — and "Yes, Send Alert" then called `onSubmit(form.getValues())`
 *      directly, bypassing the resolver. The trigger is gone; the confirmation
 *      now opens only from a validated submit.
 */

const warningFormSchema = z.object({
  title: z.string().min(5, 'Title must be at least 5 characters long.'),
  description: z.string().min(10, 'Description must be at least 10 characters long.'),
});

type WarningFormValues = z.infer<typeof warningFormSchema>;

export function WarningForm() {
  const { toast } = useToast();
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [pending, setPending] = useState<WarningFormValues | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const form = useForm<WarningFormValues>({
    resolver: zodResolver(warningFormSchema),
    defaultValues: { title: '', description: '' },
  });

  function send(values: WarningFormValues) {
    setSubmitting(true);

    router.post('/dashboard/warnings', values, {
      preserveScroll: true,
      onSuccess: () => {
        setPending(null);
        setIsDialogOpen(false);
        form.reset();
        toast({
          title: 'Alert sent',
          description: 'Your alert is now visible to the community.',
        });
      },
      onError: (errors) => {
        setPending(null);
        toast({
          variant: 'destructive',
          title: 'Could not send the alert',
          // A 429 from the route's throttle arrives with no field errors.
          description:
            Object.values(errors)[0] ??
            'You have raised several alerts recently. Please wait before sending another.',
        });
      },
      onFinish: () => setSubmitting(false),
    });
  }

  return (
    <>
      <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
        <DialogTrigger asChild>
          <Button size="sm" variant="destructive" className="gap-1">
            <PlusCircle className="h-3.5 w-3.5" />
            <span className="sr-only sm:not-sr-only sm:whitespace-nowrap">Send Alert</span>
          </Button>
        </DialogTrigger>

        <DialogContent className="sm:max-w-[425px]">
          <Form {...form}>
            <form onSubmit={form.handleSubmit((values) => setPending(values))} className="space-y-4">
              <DialogHeader>
                <DialogTitle>Send New Alert</DialogTitle>
                <DialogDescription>
                  Describe the issue. Your alert will be sent to all residents and the admin.
                </DialogDescription>
              </DialogHeader>

              <FormField
                control={form.control}
                name="title"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Title</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. Lost Pet" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="description"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Description</FormLabel>
                    <FormControl>
                      <Textarea placeholder="Provide details here..." {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <DialogFooter>
                <Button type="submit" variant="destructive" disabled={submitting}>
                  Send Alert
                </Button>
              </DialogFooter>
            </form>
          </Form>
        </DialogContent>
      </Dialog>

      {/* Opens only once the form has validated. */}
      <AlertDialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2">
              <AlertTriangle className="text-destructive" />
              Are you sure?
            </AlertDialogTitle>
            <AlertDialogDescription>
              Sending an alert notifies the entire community. Please confirm you want to proceed to
              avoid false alarms.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={submitting}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              disabled={submitting}
              onClick={(e) => {
                // Keep the dialog up while the request is in flight.
                e.preventDefault();
                if (pending) send(pending);
              }}
            >
              {submitting ? 'Sending…' : 'Yes, Send Alert'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
