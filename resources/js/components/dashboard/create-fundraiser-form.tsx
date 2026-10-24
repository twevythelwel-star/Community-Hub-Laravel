import React, { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { format } from 'date-fns';
import { Target } from 'lucide-react';
import { router } from '@inertiajs/react';
import { DateField } from '@/components/dashboard/date-field';
import { TextField } from '@/components/dashboard/text-field';
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
import { Form } from '@/components/ui/form';
import { useToast } from '@/hooks/use-toast';

const formSchema = z
  .object({
    title: z.string().min(5, 'Title must be at least 5 characters.').max(160),
    description: z.string().min(10, 'Description must be at least 10 characters.').max(5000),
    beneficiary: z.string().max(150).optional(),
    goal: z.coerce.number().min(1, 'Goal must be at least $1.'),
    startDate: z.date({ required_error: 'A start date is required.' }),
    endDate: z.date({ required_error: 'An end date is required.' }),
    coverImageUrl: z.string().url('Must be a valid URL').optional().or(z.literal('')),
    images: z.string().optional(),
    matchingSponsor: z.string().max(150).optional(),
  })
  .refine((data) => data.endDate > data.startDate, {
    message: 'End date must be after start date.',
    path: ['endDate'],
  });

type CreateFundraiserFormProps = {
  children: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function CreateFundraiserForm({ children, open, onOpenChange }: CreateFundraiserFormProps) {
  const { toast } = useToast();
  const [submitting, setSubmitting] = useState(false);

  const form = useForm<z.infer<typeof formSchema>>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      title: '',
      description: '',
      beneficiary: 'Cypress Bay Community Improvement Fund',
      goal: 150000,
      coverImageUrl: '',
      images: '',
      matchingSponsor: '',
    },
  });

  function onSubmit(values: z.infer<typeof formSchema>) {
    setSubmitting(true);

    router.post(
      '/dashboard/fundraising',
      {
        title: values.title,
        description: values.description,
        beneficiary: values.beneficiary || 'Cypress Bay Community Improvement Fund',
        goal: values.goal,
        start_date: format(values.startDate, 'yyyy-MM-dd'),
        end_date: format(values.endDate, 'yyyy-MM-dd'),
        status: 'Upcoming',
        cover_image_url: values.coverImageUrl || null,
        images: values.images || null,
        matching_sponsor: values.matchingSponsor || null,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          form.reset();
          onOpenChange(false);
          toast({
            title: 'Campaign created',
            description: `"${values.title}" has been scheduled. Use "Enable Now" to open it for donations.`,
          });
        },
        onError: (errors) =>
          toast({
            variant: 'destructive',
            title: 'Could not create the campaign',
            description: Object.values(errors)[0] ?? 'Please check the form and try again.',
          }),
        onFinish: () => setSubmitting(false),
      }
    );
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(isOpen) => {
        onOpenChange(isOpen);
        if (!isOpen) {
          form.reset();
        }
      }}
    >
      <DialogTrigger asChild>{children}</DialogTrigger>
      <DialogContent className="sm:max-w-xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <div className="p-2 rounded-full bg-primary/10 text-primary">
              <Target className="h-5 w-5" />
            </div>
            <div>
              <DialogTitle>Create Community Campaign</DialogTitle>
              <DialogDescription>
                Launch a targeted fundraising campaign for estate improvements or charity.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <TextField control={form.control} name="title" label="Campaign Title" placeholder="e.g. New Playground & Recreation Equipment" />

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <TextField control={form.control} name="beneficiary" label="Beneficiary / Fund" placeholder="e.g. Park Development Committee" />
              <TextField control={form.control} name="goal" label="Donation Target Goal (JMD)" type="number" placeholder="150000" />
            </div>

            <TextField
              control={form.control}
              name="description"
              label="Campaign Purpose & Description"
              placeholder="Detail why this campaign is important to our community, expected costs, and contractor timelines..."
              multiline={3}
            />

            <div className="grid grid-cols-2 gap-4">
              <DateField control={form.control} name="startDate" label="Start Date" placeholder="Pick start date" />
              <DateField control={form.control} name="endDate" label="End Date" placeholder="Pick end date" />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <TextField
                control={form.control}
                name="matchingSponsor"
                label="1:1 Matching Sponsor (Optional)"
                placeholder="e.g. Apex Construction Foundation"
                description="Sponsor matching resident gifts dollar-for-dollar."
                descriptionClassName="text-[11px]"
              />
              <TextField
                control={form.control}
                name="coverImageUrl"
                label="Cover Photo URL (Optional)"
                placeholder="https://images.unsplash.com/..."
                description="Hero picture featured on campaign cards."
                descriptionClassName="text-[11px]"
              />
            </div>

            <TextField
              control={form.control}
              name="images"
              label="Gallery Image URLs (Optional)"
              placeholder="Enter one image URL per line for the campaign photo gallery..."
              multiline={2}
            />

            <DialogFooter className="pt-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Creating Campaign...' : 'Launch Campaign'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
