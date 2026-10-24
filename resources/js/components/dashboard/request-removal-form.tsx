

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useToast } from '@/hooks/use-toast';
import { Textarea } from '../ui/textarea';


const formSchema = z.object({
  reason: z.string().min(10, 'A reason of at least 10 characters is required.'),
});

type FormValues = z.infer<typeof formSchema>;

/** Only the fields this dialog reads; server rows carry a numeric id. */
export type RemovalSubject = {
  id: number | string;
  name: string;
};

type RequestRemovalFormProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  entry: RemovalSubject;
};

/**
 * Resident request that a blocklist entry be reviewed.
 *
 * The submit handler previously called console.log and then told the resident
 * "your request ... has been sent to the administrators for review". Nothing was
 * stored and no administrator ever saw it. It now writes a
 * `blocklist_removal_requests` row that appears on the block list for anyone who
 * can manage it.
 */
export function RequestRemovalForm({ open, onOpenChange, entry }: RequestRemovalFormProps) {
  const { toast } = useToast();
  const [isSubmitting, setIsSubmitting] = useState(false);
  
  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      reason: '',
    },
  });

  function onSubmit(values: FormValues) {
    setIsSubmitting(true);

    router.post(
      `/dashboard/block-list/${entry.id}/request-removal`,
      { reason: values.reason },
      {
        preserveScroll: true,
        onSuccess: () => {
          setIsSubmitting(false);
          form.reset();

          toast({
            title: 'Request Sent',
            description: `Your request to remove ${entry.name} from the blocklist is now with community administration.`,
          });

          onOpenChange(false);
        },
        onError: (errors) => {
          setIsSubmitting(false);

          toast({
            variant: 'destructive',
            title: 'Request Not Sent',
            description: errors?.reason ?? 'Your request could not be submitted. Please try again.',
          });
        },
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={(isOpen) => {
        onOpenChange(isOpen);
        if (!isOpen) {
            form.reset();
        }
    }}>
      <DialogContent className="sm:max-w-md">
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <DialogHeader>
              <DialogTitle>Request Removal for {entry.name}</DialogTitle>
              <DialogDescription>
                Please provide a reason for requesting this individual&apos;s removal from the blocklist. This will be sent to an administrator for review.
              </DialogDescription>
            </DialogHeader>
            
             <FormField
              control={form.control}
              name="reason"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Reason for Request</FormLabel>
                  <FormControl>
                    <Textarea placeholder="Explain why this person should be removed..." {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? 'Sending…' : 'Submit Request'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
