import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { FormDialog } from '@/components/dashboard/form-dialog';
import { TextField } from '@/components/dashboard/text-field';
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Business, Voucher } from '@/types';

const formSchema = z.object({
  title: z.string().min(5, 'Title must be at least 5 characters.'),
  description: z.string().min(10, 'Description must be at least 10 characters.'),
  businessId: z.string({ required_error: "Please select a business." }),
});

type FormValues = z.infer<typeof formSchema>;

type VoucherFormProps = {
  children: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSave: (data: Omit<Voucher, 'id'>) => void;
  businesses: Business[];
  voucher?: Voucher;
};

export function VoucherForm({ children, open, onOpenChange, onSave, businesses, voucher }: VoucherFormProps) {
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
      record={voucher}
      resetValues={() => voucher
        ? { title: voucher.title, description: voucher.description, businessId: String(voucher.businessId ?? '') }
        : { title: '', description: '', businessId: undefined }}
      title={`${voucher ? 'Edit' : 'Add'} Voucher`}
      description="Fill in the details for the new community voucher."
      submitLabel="Save Voucher"
    >
      <FormField
        control={form.control}
        name="businessId"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Business</FormLabel>
            <Select onValueChange={field.onChange} defaultValue={field.value}>
              <FormControl>
                <SelectTrigger>
                  <SelectValue placeholder="Select a business" />
                </SelectTrigger>
              </FormControl>
              <SelectContent>
                {businesses.map(business => (
                  <SelectItem key={business.id} value={String(business.id)}>{business.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FormMessage />
          </FormItem>
        )}
      />
      <TextField control={form.control} name="title" label="Voucher Title" placeholder="e.g., Free Delivery Friday!" />
      <TextField control={form.control} name="description" label="Description" placeholder="Explain the details of the offer..." multiline />
    </FormDialog>
  );
}
