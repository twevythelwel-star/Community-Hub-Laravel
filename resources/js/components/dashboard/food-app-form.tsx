import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { FormDialog } from '@/components/dashboard/form-dialog';
import { TextField } from '@/components/dashboard/text-field';
import type { FoodApp } from '@/types';

const formSchema = z.object({
  name: z.string().min(2, 'Service name must be at least 2 characters.'),
  websiteUrl: z.string().url('Please enter a valid URL.'),
  logoUrl: z.string().url('Please enter a valid logo URL.'),
  aiHint: z.string().min(1, 'AI hint is required.').max(40, 'AI hint is too long.'),
  couponPercentage: z.coerce.number().optional(),
});

type FormValues = z.infer<typeof formSchema>;

const DEFAULT_LOGO = 'https://picsum.photos/seed/newapp/100/100';
const DEFAULT_HINT = 'food delivery logo';

type FoodAppFormProps = {
  children: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSave: (app: Omit<FoodApp, 'id'>) => void;
  app?: FoodApp;
};

export function FoodAppForm({ children, open, onOpenChange, onSave, app }: FoodAppFormProps) {
  const form = useForm<FormValues>({ resolver: zodResolver(formSchema) });

  function onSubmit(values: FormValues) {
    onSave(values);
    onOpenChange(false);
  }

  return (
    <FormDialog
      trigger={children}
      open={open}
      onOpenChange={onOpenChange}
      form={form}
      onSubmit={onSubmit}
      record={app}
      resetValues={() => ({
        name: app?.name ?? '',
        websiteUrl: app?.websiteUrl ?? '',
        logoUrl: app?.logoUrl || DEFAULT_LOGO,
        aiHint: app?.aiHint || DEFAULT_HINT,
        couponPercentage: app?.couponPercentage ?? undefined,
      })}
      title={`${app ? 'Edit' : 'Add'} Food Delivery Service`}
      description="Fill in the details for the delivery service."
      submitLabel="Save Service"
    >
      <TextField control={form.control} name="name" label="Service Name" placeholder="e.g., DoorDash" />
      <TextField control={form.control} name="websiteUrl" label="Website URL" placeholder="https://www.doordash.com" />
      <TextField control={form.control} name="logoUrl" label="Logo URL" placeholder="https://picsum.photos/seed/logo/100/100" />
      <TextField
        control={form.control}
        name="aiHint"
        label="AI Hint for Logo"
        placeholder="e.g., food delivery logo"
        description="A few keywords to help AI find a replacement image."
        descriptionClassName="text-xs"
      />
      <TextField control={form.control} name="couponPercentage" label="Coupon Percentage (Optional)" type="number" placeholder="e.g., 15" />
    </FormDialog>
  );
}
