import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { format, setHours, setMinutes } from 'date-fns';
import { DateField } from '@/components/dashboard/date-field';
import { FormDialog } from '@/components/dashboard/form-dialog';
import { TextField } from '@/components/dashboard/text-field';
import { FormControl, FormField, FormItem, FormLabel } from '@/components/ui/form';
import { Switch } from '@/components/ui/switch';
import { useToast } from '@/hooks/use-toast';
import type { CommunityEvent } from '@/types';

/** The date at the given "HH:mm" time, or the date unchanged when there is no time. */
function withTime(date: Date, time?: string): Date {
  if (!time) {
    return date;
  }
  const [hours, minutes] = time.split(':').map(part => parseInt(part, 10));

  return setMinutes(setHours(date, hours), minutes);
}

const formSchema = z.object({
  title: z.string().min(5, 'Title must be at least 5 characters.'),
  description: z.string().min(10, 'Description must be at least 10 characters.'),
  startDate: z.date({ required_error: "A start date is required." }),
  startTime: z.string().optional(),
  hasEndTime: z.boolean().default(false),
  endDate: z.date().optional(),
  endTime: z.string().optional(),
  imageUrl: z.string().url().optional().or(z.literal('')),
}).refine(data => {
    if (!data.hasEndTime) return true;
    if (!data.endDate) return false; // End date is required if hasEndTime is true

    return withTime(data.endDate, data.endTime) >= withTime(data.startDate, data.startTime);
}, {
    message: "End date and time must be after the start date and time.",
    path: ["endDate"],
});

type FormValues = z.infer<typeof formSchema>;

type EventFormProps = {
  children?: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * Persists the event. Resolves when the server accepted it and rejects when
   * it refused, so the dialog only claims success for a save that happened.
   */
  onSave: (data: Omit<CommunityEvent, 'id'>, id?: string) => Promise<void>;
  event?: CommunityEvent;
};

export function EventForm({ children, open, onOpenChange, onSave, event }: EventFormProps) {
  const { toast } = useToast();
  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { hasEndTime: false },
  });

  const hasEndTime = form.watch('hasEndTime');

  async function onSubmit(values: FormValues) {
    const finalData = {
        title: values.title,
        description: values.description,
        startDate: withTime(values.startDate, values.startTime),
        endDate: values.hasEndTime && values.endDate ? withTime(values.endDate, values.endTime) : undefined,
        imageUrl: values.imageUrl || undefined,
    };

    try {
      await onSave(finalData, event?.id);
    } catch (message) {
      toast({
        variant: 'destructive',
        title: 'Event not saved',
        description: typeof message === 'string' ? message : 'The server refused the event. Please check the details and try again.',
      });
      return;
    }

    toast({
      title: event ? 'Event Updated' : 'Event Created',
      description: `The event "${values.title}" has been saved.`,
    });
    onOpenChange(false);
  }

  return (
    <FormDialog
      trigger={children}
      open={open}
      onOpenChange={onOpenChange}
      form={form}
      onSubmit={onSubmit}
      record={event}
      resetValues={() => event
        ? {
            ...event,
            startTime: event.startDate ? format(event.startDate, 'HH:mm') : undefined,
            hasEndTime: !!event.endDate,
            endTime: event.endDate ? format(event.endDate, 'HH:mm') : undefined,
          }
        : {
            title: '',
            description: '',
            startDate: new Date(),
            startTime: format(new Date(), 'HH:mm'),
            hasEndTime: false,
            endDate: undefined,
            endTime: undefined,
            imageUrl: '',
          }}
      title={`${event ? 'Edit' : 'Add'} Community Event`}
      description="Fill in the details for the event. Times are optional for all-day events."
      submitLabel="Save Event"
      submittingLabel="Saving…"
      contentClassName="sm:max-w-lg"
    >
      <TextField control={form.control} name="title" label="Event Title" placeholder="e.g., Community Pool Party" />
      <TextField control={form.control} name="description" label="Description" placeholder="Describe the event..." multiline />

      <div className="grid grid-cols-2 gap-4">
        <DateField control={form.control} name="startDate" label="Start Date" />
        <TextField control={form.control} name="startTime" label="Start Time (Optional)" type="time" />
      </div>

      <FormField
        control={form.control}
        name="hasEndTime"
        render={({ field }) => (
          <FormItem className="flex flex-row items-center justify-between rounded-lg border p-3 shadow-sm">
            <div className="space-y-0.5">
              <FormLabel>Add end date & time</FormLabel>
            </div>
            <FormControl>
              <Switch checked={field.value} onCheckedChange={field.onChange} />
            </FormControl>
          </FormItem>
        )}
      />

      {hasEndTime && (
        <div className="grid grid-cols-2 gap-4">
          <DateField
            control={form.control}
            name="endDate"
            label="End Date"
            disabled={(date) => date < form.getValues('startDate')}
          />
          <TextField control={form.control} name="endTime" label="End Time (Optional)" type="time" />
        </div>
      )}

      <TextField control={form.control} name="imageUrl" label="Image URL (Optional)" placeholder="https://example.com/image.png" />
    </FormDialog>
  );
}
