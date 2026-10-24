import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { FormDialog } from '@/components/dashboard/form-dialog';
import { TextField } from '@/components/dashboard/text-field';
import { useToast } from '@/hooks/use-toast';
import type { Guideline } from '@/types';

const formSchema = z.object({
  category: z.string().min(3, 'Category must be at least 3 characters.'),
  title: z.string().min(3, 'Title must be at least 3 characters.'),
  description: z.string().min(10, 'Description must be at least 10 characters.'),
});

type FormValues = z.infer<typeof formSchema>;

type GuidelineFormProps = {
  children: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * Persists the change. Resolves when the server accepted it and rejects when
   * it refused, so the dialog only claims success for a save that happened.
   */
  onSave: (data: Omit<Guideline, 'id'>, id?: string) => Promise<void>;
  guideline?: Guideline;
};

export function GuidelineForm({ children, open, onOpenChange, onSave, guideline }: GuidelineFormProps) {
  const { toast } = useToast();
  const form = useForm<FormValues>({ resolver: zodResolver(formSchema) });

  async function onSubmit(values: FormValues) {
    try {
      await onSave(values, guideline?.id);
    } catch (message) {
      toast({
        variant: 'destructive',
        title: 'Guideline not saved',
        description: typeof message === 'string' ? message : 'The server refused the change. Please check the details and try again.',
      });
      return;
    }

    toast({
      title: guideline ? 'Guideline Updated' : 'Guideline Added',
      description: `The guideline "${values.title}" has been saved.`,
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
      record={guideline}
      resetValues={() => guideline ?? { category: '', title: '', description: '' }}
      title={`${guideline ? 'Edit' : 'Add'} Guideline`}
      description="Fill in the details for the community guideline."
      submitLabel="Save Guideline"
      submittingLabel="Saving…"
    >
      <TextField control={form.control} name="category" label="Category" placeholder="e.g., General Conduct" />
      <TextField control={form.control} name="title" label="Title" placeholder="e.g., Noise Levels" />
      <TextField control={form.control} name="description" label="Description" placeholder="Explain the rule in detail..." multiline />
    </FormDialog>
  );
}
