import React, { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { router } from '@inertiajs/react';
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
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { useToast } from '@/hooks/use-toast';
import { Newspaper } from 'lucide-react';
import type { FundraiserRow } from './fundraiser-progress-card';

const formSchema = z.object({
  title: z.string().min(3, 'Title must be at least 3 characters.').max(150),
  content: z.string().min(10, 'Content must be at least 10 characters.').max(2000),
  image_url: z.string().url('Must be a valid URL').optional().or(z.literal('')),
});

type AddFundraiserUpdateDialogProps = {
  fundraiser: FundraiserRow;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function AddFundraiserUpdateDialog({
  fundraiser,
  open,
  onOpenChange,
}: AddFundraiserUpdateDialogProps) {
  const { toast } = useToast();
  const [submitting, setSubmitting] = useState(false);

  const form = useForm<z.infer<typeof formSchema>>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      title: '',
      content: '',
      image_url: '',
    },
  });

  function onSubmit(values: z.infer<typeof formSchema>) {
    setSubmitting(true);

    router.post(
      `/dashboard/fundraising/${fundraiser.id}/updates`,
      {
        title: values.title,
        content: values.content,
        image_url: values.image_url || null,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          form.reset();
          onOpenChange(false);
          toast({
            title: 'Update posted',
            description: `A new update has been posted to "${fundraiser.title}".`,
          });
        },
        onError: (errors) => {
          toast({
            variant: 'destructive',
            title: 'Failed to post update',
            description: Object.values(errors)[0] ?? 'Please check the form inputs.',
          });
        },
        onFinish: () => setSubmitting(false),
      }
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <div className="p-2 rounded-full bg-primary/10 text-primary">
              <Newspaper className="h-5 w-5" />
            </div>
            <div>
              <DialogTitle>Post Project Update</DialogTitle>
              <DialogDescription>
                Share progress, milestones, or receipts with supporters of &ldquo;{fundraiser.title}&rdquo;.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="title"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Update Headline</FormLabel>
                  <FormControl>
                    <Input placeholder="e.g. Ground broken on new playground equipment!" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="content"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Update Message</FormLabel>
                  <FormControl>
                    <Textarea
                      rows={4}
                      placeholder="Describe progress, vendor deliveries, or project milestones achieved..."
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="image_url"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Progress Photo URL (Optional)</FormLabel>
                  <FormControl>
                    <Input placeholder="https://images.unsplash.com/..." {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter className="pt-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Posting...' : 'Post Milestone Update'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
