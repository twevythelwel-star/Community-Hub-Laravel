import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { DateField } from '@/components/dashboard/date-field';
import { FormDialog } from '@/components/dashboard/form-dialog';
import { TextField } from '@/components/dashboard/text-field';
import { useToast } from '@/hooks/use-toast';
import type { CommunityUpdate } from '@/types';

const formSchema = z.object({
  title: z.string().min(5, 'Title must be at least 5 characters.'),
  date: z.date({ required_error: "The date of the meeting/activity is required."}),
  summary: z.string().min(20, 'Summary must be at least 20 characters.'),
});

type FormValues = z.infer<typeof formSchema>;

type CommunityUpdateFormProps = {
  children: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * Persists the change. Resolves when the server accepted it and rejects when
   * it refused, so the dialog only claims success for a save that happened.
   */
  onSave: (data: Omit<CommunityUpdate, 'id'>) => Promise<void>;
  update?: CommunityUpdate;
};

export function CommunityUpdateForm({ children, open, onOpenChange, onSave, update }: CommunityUpdateFormProps) {
  const { toast } = useToast();
  const form = useForm<FormValues>({ resolver: zodResolver(formSchema) });

  async function onSubmit(values: FormValues) {
    try {
      await onSave(values);
    } catch (message) {
      toast({
        variant: 'destructive',
        title: 'Update not saved',
        description: typeof message === 'string' ? message : 'The server refused the change. Please check the details and try again.',
      });
      return;
    }

    toast({
      title: update ? 'Update Saved' : 'Update Added',
      description: `The summary for "${values.title}" has been saved.`,
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
      record={update}
      resetValues={() => update ?? { title: '', summary: '', date: new Date() }}
      title={`${update ? 'Edit' : 'Add'} Community Update`}
      description="Provide a summary for a past meeting or activity."
      submitLabel="Save Update"
      submittingLabel="Saving…"
      contentClassName="sm:max-w-lg"
    >
      <TextField control={form.control} name="title" label="Title" placeholder="e.g., Q2 2024 HOA Board Meeting" />
      <DateField control={form.control} name="date" label="Date of Meeting/Activity" disabled={(date) => date > new Date()} />
      <TextField
        control={form.control}
        name="summary"
        label="Summary"
        placeholder="Write a concise summary of the key points and decisions..."
        multiline={6}
      />
    </FormDialog>
  );
}
